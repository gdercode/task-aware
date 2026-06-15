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
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->with(['flows' => fn ($q) => $q->where('is_active', true)->orderByDesc('importance_score')])
            ->orderBy('name')
            ->get();

        $activeUsers = collect();
        $inactiveUsers = collect();

        foreach ($users as $user) {
            $latestLog = BandwidthLog::where('user_id', $user->id)->latest()->first();
            $topFlow = $user->flows->first();

            $score = $latestLog?->importance_score
                ?? $topFlow?->importance_score
                ?? null;

            $bandwidth = $latestLog?->allocated_bandwidth;

            $row = (object) [
                'user' => $user,
                'score' => $score,
                'bandwidth' => $bandwidth,
                'bandwidth_mbps' => $bandwidth ? $engine->parseBandwidthToMbps($bandwidth) : 0,
                'last_seen_at' => $latestLog?->created_at,
                'active_flows_count' => $user->active_flows_count,
            ];

            if ($user->active_flows_count > 0) {
                $activeUsers->push($row);
            } else {
                $inactiveUsers->push($row);
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

        $latestLog = BandwidthLog::where('user_id', $user->id)->latest()->first();

        $activeFlows->each(function ($flow) use ($latestLog) {
            $flow->allocated_bandwidth = $latestLog?->allocated_bandwidth;
        });

        $score = $latestLog?->importance_score ?? $activeFlows->max('importance_score');
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
        ));
    }
}
