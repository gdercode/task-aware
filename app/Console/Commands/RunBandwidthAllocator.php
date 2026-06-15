<?php

namespace App\Console\Commands;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
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
    ) {
        $this->info('Bandwidth allocator started...');

        while (true) {
            $routerReachable = $mikrotik->isReachable();

            if (! $routerReachable) {
                $this->warn("MikroTik unreachable at {$mikrotik->connectionLabel()} — skipping until connected");
                sleep(5);
                continue;
            }

            $onlineIps = $mikrotik->tryGetOnlineDeviceIps() ?? [];
            $this->line('Devices online on router: '.count($onlineIps));

            try {
                $sync = $trafficSync->syncFromRouter($mikrotik, $detector, $onlineIps);
                $this->line("Synced {$sync['synced']} connection(s)");
            } catch (\Throwable $e) {
                $this->warn('Traffic sync failed: '.$e->getMessage());
            }

            $poolMeasure = $mikrotik->measurePoolKbps();
            $allocation = $allocationPreview->build($poolMeasure['kbps'], $onlineIps);
            $poolKbps = $allocation['pool_kbps'];

            if ($poolKbps <= 0) {
                $this->warn('Pool is 0 Kbps — set monitor interface on dashboard or generate client traffic');
                sleep(5);
                continue;
            }

            $availableBandwidth = $engine->formatLimit($poolKbps);
            $this->info("Pool: {$engine->formatKbpsDisplay($poolKbps)} ({$availableBandwidth}) [{$poolMeasure['source']}]");

            foreach (User::whereNotNull('ip_address')->get() as $user) {
                $row = $allocation['users']->first(fn ($r) => $r->user->id === $user->id);
                $shareKbps = $row->share_kbps ?? 0;
                $isOnline = $row->is_online ?? false;
                $status = $row->activity_status ?? 'unknown';

                if (! $isOnline || $shareKbps <= 0) {
                    $mikrotik->updateQueue($user->name, $user->ip_address, '0k/0k');
                    $reason = ! $isOnline ? 'offline' : $status;
                    $this->line("{$user->name} → 0 Kbps ({$reason})");

                    continue;
                }

                $bandwidth = $engine->formatLimit($shareKbps);
                $updated = $mikrotik->updateQueue($user->name, $user->ip_address, $bandwidth);

                if (! $updated) {
                    $this->warn("Queue update failed — skipping log for {$user->name}");
                    continue;
                }

                $score = $row->score ?? 0;
                $taskType = $row->task_type
                    ?? Flow::where('user_id', $user->id)->where('is_active', true)->value('classification')
                    ?? 'NORMAL';

                BandwidthLog::create([
                    'user_id' => $user->id,
                    'task_type' => $taskType,
                    'importance_score' => $score,
                    'allocated_bandwidth' => $bandwidth,
                    'available_bandwidth' => $availableBandwidth,
                    'router_connected' => true,
                ]);

                $this->info("{$user->name} → {$engine->formatKbpsDisplay($shareKbps)} ({$bandwidth}, score {$score}, {$status})");
            }

            sleep(5);
        }
    }
}
