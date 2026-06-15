<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\MikrotikSetting;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
use App\Services\RouterDeviceDetectionService;
use App\Services\TrafficDetectionService;
use App\Services\TrafficSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const ALLOCATION_CYCLE_SECONDS = 120;

    public function index(
        ImportanceEngineService $engine,
        MikrotikService $mikrotik,
        TrafficSyncService $trafficSync,
        TrafficDetectionService $detector,
        AllocationPreviewService $allocationPreview,
        RouterDeviceDetectionService $deviceDetection,
    ): View {
        $mikrotikSettings = MikrotikSetting::current();
        $mikrotikConnected = $mikrotik->isReachable();
        $bandwidthMeasureError = null;

        $users = User::whereNotNull('ip_address')
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();
        $latestAvailable = null;
        $totalAvailableAt = null;
        $allocation = [
            'pool_kbps' => 0,
            'pool_label' => '0k/0k',
            'pool_display' => '0 Kbps',
            'total_score' => 0,
            'online_count' => 0,
            'offline_count' => 0,
            'users' => collect(),
        ];
        $onlineIps = [];
        $detection = null;
        $poolMeasure = null;
        $interfaceTraffic = [];

        if ($mikrotikConnected) {
            $detection = $deviceDetection->diagnose();
            $onlineIps = $detection['online_ips'];

            try {
                $trafficSync->syncFromRouter($mikrotik, $detector, $onlineIps);
            } catch (\Throwable) {
                // Connection can succeed for identity but fail on connection table — continue
            }

            $poolMeasure = $mikrotik->measurePoolKbps();
            $interfaceTraffic = $mikrotik->getInterfaceTrafficSamples();
            $poolKbps = $poolMeasure['kbps'];

            if ($poolMeasure['interface_error'] && $poolKbps === 0) {
                $bandwidthMeasureError = 'Could not read monitor interface "'.$poolMeasure['interface'].'". '
                    .'Pick a valid interface below (see live traffic per interface).';
                $allocation = $allocationPreview->build(0, $onlineIps);
            } else {
                $totalAvailableAt = now();
                $allocation = $allocationPreview->build($poolKbps, $onlineIps);
                $latestAvailable = $engine->formatKbpsDisplay($allocation['pool_kbps']);

                if ($allocation['pool_using_fallback'] ?? false) {
                    $bandwidthMeasureError = 'Interface "'.$poolMeasure['interface'].'" shows '
                        .$poolMeasure['interface_kbps'].' Kbps; client connections show '
                        .$poolMeasure['connection_kbps'].' Kbps — using minimum pool of '
                        .config('bandwidth.min_pool_kbps', 64).' Kbps for active users.';
                } elseif ($poolKbps === 0 && ($allocation['total_score'] ?? 0) === 0) {
                    $bandwidthMeasureError = $this->poolZeroMessage($poolMeasure, $interfaceTraffic, $detection);
                } elseif ($poolMeasure['source'] === 'connections' && $poolMeasure['interface_kbps'] === 0) {
                    $bandwidthMeasureError = 'Pool measured from active client connections ('
                        .$poolMeasure['connection_kbps'].' Kbps). Interface "'.$poolMeasure['interface']
                        .'" shows 0 — consider setting monitor interface to your LAN/WLAN interface.';
                }

                $activeUsers = $allocation['users']
                    ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
                    ->values();
            }

            foreach ($users as $user) {
                $row = $allocation['users']->first(fn ($r) => $r->user->id === $user->id);
                $isGettingBandwidth = $row && $row->is_online && $row->share_kbps > 0;

                if (! $isGettingBandwidth) {
                    $inactiveUsers->push((object) [
                        'user' => $user,
                        'is_online' => $row?->is_online ?? $mikrotik->isDeviceOnline($user->ip_address, $onlineIps),
                        'share_kbps' => 0,
                        'kbps_display' => '0 Kbps',
                        'activity_status' => $row?->activity_status ?? $user->activity_status,
                        'offline_reason' => match ($row?->activity_status ?? $user->activity_status) {
                            'offline' => 'Device offline',
                            'idle' => 'Idle — no internet use',
                            'low_usage' => 'Low usage — minimal share',
                            default => 'No bandwidth allocated',
                        },
                    ]);
                }
            }
        } else {
            foreach ($users as $user) {
                if ($user->active_flows_count === 0) {
                    $inactiveUsers->push((object) ['user' => $user]);
                }
            }
        }

        $stats = [
            'active_users' => $activeUsers->count(),
            'inactive_users' => $inactiveUsers->count(),
            'online_devices' => $allocation['online_count'] ?? 0,
            'offline_devices' => $allocation['offline_count'] ?? 0,
            'activity' => $allocation['activity'] ?? [],
            'pool_kbps' => $allocation['pool_kbps'] ?? 0,
            'active_flows' => Flow::where('is_active', true)->count(),
            'total_reports' => BandwidthLog::where('router_connected', true)->count(),
            'total_available_bandwidth' => $latestAvailable,
            'total_available_at' => $totalAvailableAt,
        ];

        return view('dashboard', compact(
            'activeUsers',
            'inactiveUsers',
            'users',
            'stats',
            'mikrotikSettings',
            'mikrotikConnected',
            'bandwidthMeasureError',
            'allocation',
            'detection',
            'poolMeasure',
            'interfaceTraffic',
        ));
    }

    /**
     * @param  array<string, mixed>  $poolMeasure
     * @param  list<array{name: string, kbps: int|null}>  $interfaceTraffic
     * @param  array<string, mixed>  $detection
     */
    private function poolZeroMessage(array $poolMeasure, array $interfaceTraffic, array $detection): string
    {
        $busy = collect($interfaceTraffic)->filter(fn ($row) => ($row['kbps'] ?? 0) > 0)->take(3);
        $detectedUsers = collect($detection['users'] ?? [])->where('detected', true)->count();

        $message = 'Bandwidth pool is 0 Kbps. Interface "'.$poolMeasure['interface'].'" has no traffic right now';

        if ($poolMeasure['connection_kbps'] > 0) {
            $message .= ' (but client connections show '.$poolMeasure['connection_kbps'].' Kbps)';
        }

        $message .= '.';

        if ($busy->isNotEmpty()) {
            $message .= ' Interfaces with traffic now: '
                .$busy->map(fn ($row) => $row['name'].' ('.$row['kbps'].' Kbps)')->implode(', ')
                .' — set Monitor interface to one of these.';
        } elseif ($detectedUsers === 0) {
            $message .= ' No users detected on router — fix user IP addresses first (see detection table above).';
        } else {
            $message .= ' Browse on a client device, then refresh. Or pick the LAN/WLAN interface below.';
        }

        return $message;
    }

    public function updateMikrotik(Request $request, MikrotikService $mikrotik): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'monitor_interface' => ['required', 'string', 'max:64'],
        ]);

        $settings = MikrotikSetting::current();
        $settings->update($validated);

        $mikrotik->resetClient();

        return redirect()
            ->route('dashboard')
            ->with('success', 'MikroTik settings saved.');
    }

    public function userReports(Request $request, User $user, MikrotikService $mikrotik): View
    {
        $taskType = $request->query('task_type');
        $mikrotikConnected = $mikrotik->isReachable();

        $reports = BandwidthLog::where('user_id', $user->id)
            ->where('router_connected', true)
            ->when($taskType, fn ($q) => $q->where('task_type', $taskType))
            ->latest()
            ->paginate(50);

        $taskTypes = BandwidthLog::where('user_id', $user->id)
            ->where('router_connected', true)
            ->select('task_type')
            ->distinct()
            ->orderBy('task_type')
            ->pluck('task_type');

        $activeFlows = Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('importance_score')
            ->get();

        $cycleStart = $this->latestAllocationCycleStart();
        $isGettingBandwidth = $mikrotikConnected
            && ($this->usersGettingBandwidth($cycleStart)->contains($user->id) || $activeFlows->isNotEmpty());
        $isUsingBandwidth = $activeFlows->isNotEmpty();

        $latestLog = BandwidthLog::where('user_id', $user->id)
            ->where('router_connected', true)
            ->latest()
            ->first();

        $activeFlows->each(function ($flow) use ($latestLog, $isGettingBandwidth) {
            $flow->allocated_bandwidth = $isGettingBandwidth ? $latestLog?->allocated_bandwidth : null;
        });

        $score = $latestLog?->importance_score;
        $bandwidth = $latestLog?->allocated_bandwidth;

        return view('allocation-reports', compact(
            'user',
            'reports',
            'taskType',
            'taskTypes',
            'activeFlows',
            'latestLog',
            'score',
            'bandwidth',
            'isGettingBandwidth',
            'isUsingBandwidth',
            'mikrotikConnected',
        ));
    }

    private function usersFromRecentLogs(ImportanceEngineService $engine): Collection
    {
        $cycleStart = $this->latestAllocationCycleStart();

        if (! $cycleStart) {
            return collect();
        }

        $userIds = $this->usersGettingBandwidth($cycleStart);

        return $userIds->map(function ($userId) use ($cycleStart) {
            $log = BandwidthLog::with('user')
                ->where('user_id', $userId)
                ->where('router_connected', true)
                ->where('created_at', '>=', $cycleStart)
                ->latest()
                ->first();

            if (! $log) {
                return null;
            }

            return (object) [
                'user' => $log->user,
                'score' => $log->importance_score,
                'bandwidth' => $log->allocated_bandwidth,
                'last_seen_at' => $log->created_at,
            ];
        })->filter()->values();
    }

    private function latestAllocationCycleStart(): ?Carbon
    {
        $latestLog = BandwidthLog::where('router_connected', true)->latest()->first();

        if (! $latestLog) {
            return null;
        }

        return $latestLog->created_at->copy()->subSeconds(self::ALLOCATION_CYCLE_SECONDS);
    }

    private function usersGettingBandwidth(?Carbon $cycleStart): Collection
    {
        if (! $cycleStart) {
            return collect();
        }

        return BandwidthLog::where('router_connected', true)
            ->where('created_at', '>=', $cycleStart)
            ->distinct()
            ->pluck('user_id');
    }
}
