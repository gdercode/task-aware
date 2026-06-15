<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $users = User::whereNotNull('ip_address')
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();

        foreach ($users as $user) {
            $latestLog = BandwidthLog::where('user_id', $user->id)->latest()->first();

            $row = (object) [
                'user' => $user,
                'last_allocation' => $latestLog?->allocated_bandwidth,
                'last_task_type' => $latestLog?->task_type,
                'last_seen_at' => $latestLog?->created_at,
                'active_flows_count' => $user->active_flows_count,
            ];

            if ($user->active_flows_count > 0) {
                $activeUsers->push($row);
            } else {
                $inactiveUsers->push($row);
            }
        }

        $stats = [
            'active_users' => $activeUsers->count(),
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

        $activeFlows = Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('importance_score')
            ->get();

        $latestLog = BandwidthLog::where('user_id', $user->id)->latest()->first();

        return view('allocation-reports', compact(
            'user',
            'reports',
            'taskType',
            'taskTypes',
            'activeFlows',
            'latestLog',
        ));
    }
}
