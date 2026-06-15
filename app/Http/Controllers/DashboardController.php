<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use App\Services\ImportanceEngineService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const ALLOCATION_CYCLE_SECONDS = 15;

    public function index(ImportanceEngineService $engine): View
    {
        $cycleStart = $this->latestAllocationCycleStart();

        $gettingBandwidthIds = $this->usersGettingBandwidth($cycleStart);

        $users = User::whereNotNull('ip_address')
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();

        foreach ($users as $user) {
            $isGettingBandwidth = $gettingBandwidthIds->contains($user->id);
            $isUsingBandwidth = $user->active_flows_count > 0;

            if ($isGettingBandwidth) {
                $cycleLog = BandwidthLog::where('user_id', $user->id)
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

        $latestAvailable = BandwidthLog::whereNotNull('available_bandwidth')
            ->latest()
            ->value('available_bandwidth');

        $stats = [
            'active_users' => $activeUsers->count(),
            'inactive_users' => $inactiveUsers->count(),
            'active_flows' => Flow::where('is_active', true)->count(),
            'total_reports' => BandwidthLog::count(),
            'total_available_bandwidth' => $latestAvailable,
            'total_available_at' => BandwidthLog::whereNotNull('available_bandwidth')->latest()->value('created_at'),
        ];

        return view('dashboard', compact('activeUsers', 'inactiveUsers', 'stats'));
    }

    public function userReports(Request $request, User $user, ImportanceEngineService $engine): View
    {
        $taskType = $request->query('task_type');

        $reports = BandwidthLog::where('user_id', $user->id)
            ->when($taskType, fn ($q) => $q->where('task_type', $taskType))
            ->latest()
            ->paginate(50);

        $taskTypes = BandwidthLog::where('user_id', $user->id)
            ->select('task_type')
            ->distinct()
            ->orderBy('task_type')
            ->pluck('task_type');

        $activeFlows = Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('importance_score')
            ->get();

        $cycleStart = $this->latestAllocationCycleStart();
        $isGettingBandwidth = $this->usersGettingBandwidth($cycleStart)->contains($user->id);
        $isUsingBandwidth = $activeFlows->isNotEmpty();

        $latestLog = $isGettingBandwidth
            ? BandwidthLog::where('user_id', $user->id)
                ->when($cycleStart, fn ($q) => $q->where('created_at', '>=', $cycleStart))
                ->latest()
                ->first()
            : BandwidthLog::where('user_id', $user->id)->latest()->first();

        $activeFlows->each(function ($flow) use ($latestLog, $isGettingBandwidth) {
            $flow->allocated_bandwidth = $isGettingBandwidth ? $latestLog?->allocated_bandwidth : null;
        });

        $score = $isGettingBandwidth
            ? $latestLog?->importance_score
            : null;
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
        ));
    }

    private function latestAllocationCycleStart(): ?Carbon
    {
        $latestLog = BandwidthLog::latest()->first();

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

        return BandwidthLog::where('created_at', '>=', $cycleStart)
            ->distinct()
            ->pluck('user_id');
    }
}
