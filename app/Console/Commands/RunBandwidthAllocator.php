<?php

namespace App\Console\Commands;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\AllocationSnapshotService;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
use App\Services\RouterDeviceDetectionService;
use App\Services\TrafficDetectionService;
use App\Services\TrafficSyncService;
use Illuminate\Console\Command;

class RunBandwidthAllocator extends Command
{
    protected $signature = 'bandwidth:run';

    protected $description = 'Real-time bandwidth allocation engine';

    public function handle(
        MikrotikService $mikrotik,
        ImportanceEngineService $engine,
        AllocationPreviewService $allocationPreview,
        TrafficSyncService $trafficSync,
        TrafficDetectionService $detector,
        RouterDeviceDetectionService $deviceDetection,
        AllocationSnapshotService $snapshot,
    ) {
        $this->info('Bandwidth allocator started...');

        while (true) {
            $this->cycle(
                $mikrotik,
                $engine,
                $allocationPreview,
                $trafficSync,
                $detector,
                $deviceDetection,
                $snapshot,
            );
        }
    }

    protected function cycle(
        MikrotikService $mikrotik,
        ImportanceEngineService $engine,
        AllocationPreviewService $allocationPreview,
        TrafficSyncService $trafficSync,
        TrafficDetectionService $detector,
        RouterDeviceDetectionService $deviceDetection,
        AllocationSnapshotService $snapshot,
    ): void {
        if (! $mikrotik->isReachable()) {
            $snapshot->publishOffline();
            $this->warn("MikroTik unreachable at {$mikrotik->connectionLabel()} — skipping until connected");
            sleep(5);

            return;
        }

        $presence = $deviceDetection->presenceSources();
        $identified = [];
        foreach ($presence as $source) {
            foreach ($source['ips'] as $ip) {
                $identified[$ip] = true;
            }
        }
        $this->line('Identified '.count($identified).' device address(es) from ARP, DHCP, and hotspot');

        $connections = [];
        $connectionError = null;

        try {
            $connections = $mikrotik->getConnections();
        } catch (\Throwable $e) {
            $connectionError = $e->getMessage();
            $this->warn('Connection table unavailable: '.$e->getMessage());
        }

        $detection = $deviceDetection->diagnose(
            $connectionError === null ? $connections : null,
            $presence,
            $connectionError,
        );
        $onlineIps = $detection['online_ips'];
        $activeIps = [];
        foreach ($detection['devices'] ?? [] as $device) {
            if (($device['connected'] ?? false) && ($device['using_bandwidth'] ?? false)) {
                $ip = $mikrotik->normalizeIp($device['ip'] ?? null);
                if ($ip !== '') {
                    $activeIps[$ip] = true;
                }
            }
        }
        $this->line('Devices online on router: '.count($onlineIps));
        $this->line('Devices using bandwidth now: '.count($activeIps));

        if ($connectionError === null) {
            try {
                $sync = $trafficSync->syncFromRouter($mikrotik, $detector, $onlineIps, $connections);
                $this->line("Synced {$sync['synced']} connection(s)");
            } catch (\Throwable $e) {
                $this->warn('Traffic sync failed: '.$e->getMessage());
            }
        }

        $monitor = $mikrotik->monitorInterfaceName();
        $interfaceKbps = 0;
        $interfaceError = null;

        try {
            $interfaceKbps = $mikrotik->measureInterfaceKbps($monitor);
        } catch (\Throwable $e) {
            $interfaceError = $e->getMessage();
            $mikrotik->resetClient();
        }

        $poolMeasure = $mikrotik->poolFromMeasurements($interfaceKbps, $interfaceError, $connections);
        $interfaceTraffic = $mikrotik->interfaceTrafficSnapshot($monitor, $interfaceError ? null : $interfaceKbps);
        $throughput = $mikrotik->measureUserThroughputKbps($connections);
        $allocation = $allocationPreview->build($poolMeasure['kbps'], $onlineIps, $throughput, $activeIps);
        $sharesByUser = $allocation['users']->keyBy(fn ($row) => $row->user->id);
        foreach ($detection['devices'] ?? [] as $index => $device) {
            $row = isset($device['user_id']) ? $sharesByUser->get($device['user_id']) : null;
            $detection['devices'][$index]['role_percentage'] = $row->role_percentage ?? null;
            $detection['devices'][$index]['share_percent'] = ($device['using_bandwidth'] ?? false)
                ? (float) ($row->share_percent ?? 0)
                : 0;
        }
        $detection['active_weight_total'] = (int) ($allocation['total_score'] ?? 0);
        $warning = $snapshot->measurementWarning($poolMeasure, $allocation, $interfaceTraffic, $detection);

        $snapshot->publishLive($detection, $poolMeasure, $interfaceTraffic, $allocation, $warning);

        $poolKbps = $allocation['pool_kbps'];
        $this->info('Report published: '.$engine->formatKbpsDisplay($poolKbps).' ['.$poolMeasure['source'].']');

        $availableBandwidth = $poolKbps > 0 ? $engine->formatLimit($poolKbps) : '0k/0k';
        $rowsByUser = $allocation['users']->keyBy(fn ($row) => $row->user->id);
        $assignments = [];
        $pendingLogs = [];

        foreach (User::whereNotNull('ip_address')->get() as $user) {
            $row = $rowsByUser->get($user->id);
            $shareKbps = (int) ($row?->share_kbps ?? 0);
            $isOnline = (bool) ($row?->is_online ?? false);
            $status = $row?->activity_status ?? 'unknown';
            $rolePercentage = (int) ($row?->role_percentage ?? $engine->roleScore((string) $user->role));
            $limit = $engine->queueLimitFor($rolePercentage, $shareKbps);

            $assignments[] = [
                'name' => $user->name,
                'target' => $user->ip_address,
                'max_limit' => $limit,
            ];

            if ($rolePercentage <= 0) {
                $this->line("{$user->name} → blocked (0% weight)");

                continue;
            }

            if (! $isOnline || $shareKbps <= 0) {
                $reason = ! $isOnline ? 'offline' : $status;
                $this->line("{$user->name} → 0 Kbps ({$reason})");

                continue;
            }

            $pendingLogs[] = [
                'name' => $user->name,
                'user_id' => $user->id,
                'share_kbps' => $shareKbps,
                'bandwidth' => $limit,
                'score' => $row->score ?? 0,
                'status' => $status,
                'task_type' => $row->task_type
                    ?? Flow::where('user_id', $user->id)->where('is_active', true)->value('classification')
                    ?? 'NORMAL',
            ];
        }

        if ($poolKbps <= 0) {
            $blockedOnly = array_values(array_filter(
                $assignments,
                fn (array $row) => $row['max_limit'] === $engine->blockedLimit(),
            ));
            if ($blockedOnly !== []) {
                $mikrotik->syncQueueLimits($blockedOnly);
            }
            $this->warn('Pool is 0 Kbps — set monitor interface on dashboard or generate client traffic');
            $mikrotik->probeNextInterface($monitor);
            sleep(5);

            return;
        }

        $results = $mikrotik->syncQueueLimits($assignments);

        foreach ($pendingLogs as $log) {
            if (! ($results[$log['name']] ?? false)) {
                $this->warn("Queue update failed — skipping log for {$log['name']}");

                continue;
            }

            BandwidthLog::create([
                'user_id' => $log['user_id'],
                'task_type' => $log['task_type'],
                'importance_score' => $log['score'],
                'allocated_bandwidth' => $log['bandwidth'],
                'available_bandwidth' => $availableBandwidth,
                'router_connected' => true,
            ]);

            $this->info("{$log['name']} → {$engine->formatKbpsDisplay($log['share_kbps'])} ({$log['bandwidth']}, score {$log['score']}, {$log['status']})");
        }

        $mikrotik->probeNextInterface($monitor);
        sleep(5);
    }
}
