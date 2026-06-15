<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\MikrotikSetting;
use App\Models\User;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const ALLOCATION_CYCLE_SECONDS = 15;

    public function index(ImportanceEngineService $engine, MikrotikService $mikrotik): View
    {
        $mikrotikSettings = MikrotikSetting::current();
        $mikrotikConnected = $mikrotik->isReachable();

        $users = User::whereNotNull('ip_address')
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();

        if ($mikrotikConnected) {
            $cycleStart = $this->latestAllocationCycleStart();
            $gettingBandwidthIds = $this->usersGettingBandwidth($cycleStart);

            foreach ($users as $user) {
                $isGettingBandwidth = $gettingBandwidthIds->contains($user->id);
                $isUsingBandwidth = $user->active_flows_count > 0;

                if ($isGettingBandwidth) {
                    $cycleLog = BandwidthLog::where('user_id', $user->id)
                        ->where('router_connected', true)
                        ->when($cycleStart, fn ($q) => $q->where('created_at', '>=', $cycleStart))
                        ->latest()
                        ->first();

                    $activeUsers->push((object) [
                        'user' => $user,
                        'score' => $cycleLog?->importance_score,
                        'bandwidth' => $cycleLog?->allocated_bandwidth,
                        'last_seen_at' => $cycleLog?->created_at,
                    ]);
                } elseif (! $isUsingBandwidth) {
                    $inactiveUsers->push((object) [
                        'user' => $user,
                    ]);
                }
            }

            $latestAvailable = BandwidthLog::where('router_connected', true)
                ->whereNotNull('available_bandwidth')
                ->latest()
                ->value('available_bandwidth');

            $totalAvailableAt = BandwidthLog::where('router_connected', true)
                ->whereNotNull('available_bandwidth')
                ->latest()
                ->value('created_at');
        } else {
            foreach ($users as $user) {
                if ($user->active_flows_count === 0) {
                    $inactiveUsers->push((object) [
                        'user' => $user,
                    ]);
                }
            }

            $latestAvailable = null;
            $totalAvailableAt = null;
        }

        $stats = [
            'active_users' => $activeUsers->count(),
            'inactive_users' => $inactiveUsers->count(),
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
        ));
    }

    public function updateMikrotik(Request $request, MikrotikService $mikrotik): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $settings = MikrotikSetting::current();
        $settings->update($validated);

        $mikrotik->resetClient();

        return redirect()
            ->route('dashboard')
            ->with('success', 'MikroTik address updated to '.$settings->host.':'.$settings->port);
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
            && $this->usersGettingBandwidth($cycleStart)->contains($user->id);
        $isUsingBandwidth = $activeFlows->isNotEmpty();

        $latestLog = $isGettingBandwidth
            ? BandwidthLog::where('user_id', $user->id)
                ->where('router_connected', true)
                ->when($cycleStart, fn ($q) => $q->where('created_at', '>=', $cycleStart))
                ->latest()
                ->first()
            : BandwidthLog::where('user_id', $user->id)
                ->where('router_connected', true)
                ->latest()
                ->first();

        $activeFlows->each(function ($flow) use ($latestLog, $isGettingBandwidth) {
            $flow->allocated_bandwidth = $isGettingBandwidth ? $latestLog?->allocated_bandwidth : null;
        });

        $score = $isGettingBandwidth ? $latestLog?->importance_score : null;
        $bandwidth = $isGettingBandwidth ? $latestLog?->allocated_bandwidth : null;

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
