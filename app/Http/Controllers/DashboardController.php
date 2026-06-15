<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\MikrotikSetting;
use App\Models\User;
use App\Services\AllocationPreviewService;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
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

        if ($mikrotikConnected) {
            try {
                $trafficSync->syncFromRouter($mikrotik, $detector);
            } catch (\Throwable) {
                // Connection can succeed for identity but fail on connection table — continue
            }

            $onlineIps = $mikrotik->tryGetOnlineDeviceIps() ?? [];

            $poolKbps = $mikrotik->tryMeasureIncomingBandwidthKbps();

            if ($poolKbps === null) {
                $bandwidthMeasureError = 'Connected to MikroTik but could not measure traffic. Set the correct monitor interface (e.g. ether1, wlan1).';
                $allocation = $allocationPreview->build(0, $onlineIps);
            } else {
                $latestAvailable = $engine->formatKbpsDisplay($poolKbps);
                $totalAvailableAt = now();
                $allocation = $allocationPreview->build($poolKbps, $onlineIps);

                if ($poolKbps === 0) {
                    $bandwidthMeasureError = 'No traffic on the monitor interface (0 Kbps pool). Allocations will appear when traffic is detected.';
                }

                $activeUsers = $allocation['users']
                    ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
                    ->values();
            }

            foreach ($users as $user) {
                $row = $allocation['users']->firstWhere('user.id', $user->id);
                $isGettingBandwidth = $row && $row->is_online && $row->share_kbps > 0;

                if (! $isGettingBandwidth) {
                    $inactiveUsers->push((object) [
                        'user' => $user,
                        'is_online' => $row?->is_online ?? $mikrotik->isDeviceOnline($user->ip_address, $onlineIps),
                        'share_kbps' => 0,
                        'kbps_display' => '0 Kbps',
                        'offline_reason' => ($row && ! $row->is_online) ? 'Device offline' : 'No active traffic',
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
            'pool_kbps' => $allocation['pool_kbps'] ?? 0,
            'active_flows' => Flow::where('is_active', true)->count(),
            'total_reports' => BandwidthLog::where('router_connected', true)->count(),
            'total_available_bandwidth' => $latestAvailable,
            'total_available_at' => $totalAvailableAt,
        ];

        return view('dashboard', compact(
            'activeUsers',
            'inactiveUsers',
            'stats',
            'mikrotikSettings',
            'mikrotikConnected',
            'bandwidthMeasureError',
            'allocation',
        ));
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
