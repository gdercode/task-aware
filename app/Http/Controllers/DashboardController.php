<?php

namespace App\Http\Controllers;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $reports = BandwidthLog::with('user')
            ->latest()
            ->limit(100)
            ->get();

        $stats = [
            'total_reports' => BandwidthLog::count(),
            'active_flows' => Flow::where('is_active', true)->count(),
            'users_monitored' => User::whereNotNull('ip_address')->count(),
            'latest_allocation' => BandwidthLog::latest()->value('allocated_bandwidth'),
        ];

        $byTaskType = BandwidthLog::query()
            ->select('task_type', DB::raw('count(*) as total'))
            ->groupBy('task_type')
            ->orderByDesc('total')
            ->get();

        $byRole = BandwidthLog::query()
            ->join('users', 'bandwidth_logs.user_id', '=', 'users.id')
            ->select('users.role', DB::raw('count(*) as total'))
            ->groupBy('users.role')
            ->orderByDesc('total')
            ->get();

        $activeFlows = Flow::with('user')
            ->where('is_active', true)
            ->orderByDesc('importance_score')
            ->limit(10)
            ->get();

        return view('dashboard', compact(
            'reports',
            'stats',
            'byTaskType',
            'byRole',
            'activeFlows',
        ));
    }
}
