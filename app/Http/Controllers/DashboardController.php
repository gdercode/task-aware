<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\MikrotikSetting;
use App\Models\User;
use App\Services\AllocationSnapshotService;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const ALLOCATION_CYCLE_SECONDS = 120;

    public function index(AllocationSnapshotService $snapshot): View
    {
        return view('dashboard', $snapshot->forDashboard());
    }

    public function liveUse(AllocationSnapshotService $snapshot): View
    {
        $report = $snapshot->forDashboard();
        $live = ($report['reportState'] ?? '') === 'live';
        $allocation = $report['allocation'];
        $rows = collect($allocation['users'] ?? []);
        $byUser = $rows->keyBy(fn ($row) => $row->user->id);
        $pcs = [];

        if ($live) {
            foreach ($report['detection']['devices'] ?? [] as $device) {
                if (! ($device['using_bandwidth'] ?? false)) {
                    continue;
                }

                $row = isset($device['user_id']) ? $byUser->get($device['user_id']) : null;
                $used = (int) ($device['rate_kbps'] ?? 0);
                if ($used <= 0 && $row) {
                    $used = (int) ($row->throughput_total_kbps ?? 0);
                }

                $pcs[] = [
                    'name' => $device['registered_name'] ?: ($device['hostname'] ?: $device['ip']),
                    'ip' => $device['ip'] ?? '',
                    'used' => $used,
                ];
            }
        }

        usort($pcs, fn (array $a, array $b) => [$b['used'], $a['name']] <=> [$a['used'], $b['name']]);

        return view('live-use', [
            'live' => $live,
            'updatedAt' => $report['reportUpdatedAt'],
            'totalKbps' => $live ? (int) ($allocation['pool_kbps'] ?? 0) : null,
            'pcs' => $pcs,
        ]);
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

    public function userReports(Request $request, User $user, AllocationSnapshotService $snapshot): View
    {
        $taskType = $request->query('task_type');
        $mikrotikConnected = $snapshot->isLive();

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
