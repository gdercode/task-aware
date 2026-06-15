<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use App\Services\ImportanceEngineService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(ImportanceEngineService $engine): View
    {
        $users = User::whereNotNull('ip_address')
            ->with(['flows' => fn ($q) => $q->where('is_active', true)->orderByDesc('importance_score')])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();

        foreach ($users as $user) {
            if ($user->flows->isNotEmpty()) {
                foreach ($user->flows as $flow) {
                    $taskType = $flow->classification ?? $flow->task_type;
                    $latestLog = $this->latestLogForTask($user->id, $taskType);

                    $activeUsers->push((object) [
                        'user' => $user,
                        'flow' => $flow,
                        'task_type' => $taskType,
                        'importance_score' => $latestLog?->importance_score ?? $flow->importance_score,
                        'allocated_bandwidth' => $latestLog?->allocated_bandwidth
                            ?? $engine->bandwidthFromScore($flow->importance_score ?? 0),
                    ]);
                }
            } else {
                $latestLog = BandwidthLog::where('user_id', $user->id)->latest()->first();

                $inactiveUsers->push((object) [
                    'user' => $user,
                    'last_task_type' => $latestLog?->task_type,
                    'last_allocation' => $latestLog?->allocated_bandwidth,
                    'last_seen_at' => $latestLog?->created_at,
                ]);
            }
        }

        $stats = [
            'active_users' => $activeUsers->unique(fn ($row) => $row->user->id)->count(),
            'inactive_users' => $inactiveUsers->count(),
            'active_flows' => Flow::where('is_active', true)->count(),
            'total_reports' => BandwidthLog::count(),
        ];

        return view('dashboard', compact('activeUsers', 'inactiveUsers', 'stats'));
    }

    public function userReports(Request $request, User $user): View
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

        return view('allocation-reports', compact('user', 'reports', 'taskType', 'taskTypes'));
    }

    private function latestLogForTask(int $userId, string $taskType): ?BandwidthLog
    {
        return BandwidthLog::where('user_id', $userId)
            ->where('task_type', $taskType)
            ->latest()
            ->first();
    }
}
