@extends('layouts.app')

@section('title', 'AL Reports Dashboard')

@push('head')
    <meta http-equiv="refresh" content="30">
@endpush

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Task-Aware Bandwidth</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Allocation Dashboard</h1>
            </div>
            <div class="flex items-center gap-3 text-sm text-slate-400">
                <span class="inline-flex items-center gap-1.5">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    Live — refreshes every 30s
                </span>
                <button onclick="location.reload()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-medium transition-colors">
                    Refresh now
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Active Users</p>
                <p class="mt-1 text-3xl font-semibold text-emerald-400">{{ number_format($stats['active_users']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Inactive Users</p>
                <p class="mt-1 text-3xl font-semibold text-slate-400">{{ number_format($stats['inactive_users']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Active Flows</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['active_flows']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Total AL Reports</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['total_reports']) }}</p>
            </div>
        </div>

        {{-- Active users with current allocation --}}
        <div class="rounded-xl border border-emerald-900/50 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center gap-3">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                </span>
                <h2 class="text-lg font-semibold text-white">Active Users — Current Allocation</h2>
            </div>
            <div class="overflow-x-auto">
                @if ($activeUsers->isEmpty())
                    <div class="px-5 py-12 text-center text-slate-500">
                        <p class="text-sm">No active users right now.</p>
                        <p class="text-xs mt-1">Run <code class="text-emerald-400">/detect-traffic</code> then <code class="text-emerald-400">php artisan bandwidth:run</code>.</p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">User</th>
                                <th class="px-5 py-3 font-medium">Role</th>
                                <th class="px-5 py-3 font-medium">IP Address</th>
                                <th class="px-5 py-3 font-medium">Task</th>
                                <th class="px-5 py-3 font-medium text-right">Score</th>
                                <th class="px-5 py-3 font-medium text-right">Bandwidth</th>
                                <th class="px-5 py-3 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($activeUsers as $row)
                                <tr class="hover:bg-slate-800/50 transition-colors">
                                    <td class="px-5 py-3 text-white font-medium">{{ $row->user->name }}</td>
                                    <td class="px-5 py-3">
                                        @include('partials.role-badge', ['role' => $row->user->role])
                                    </td>
                                    <td class="px-5 py-3 font-mono text-slate-400 text-xs">{{ $row->user->ip_address }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-mono bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">{{ $row->task_type }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $row->importance_score ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-emerald-400 font-medium">{{ $row->allocated_bandwidth }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <a href="{{ route('allocation-reports', ['user' => $row->user->id, 'task_type' => $row->task_type]) }}"
                                           class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors border border-slate-700">
                                            View AL Report
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- Inactive users --}}
        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center gap-3">
                <span class="inline-flex rounded-full h-2.5 w-2.5 bg-slate-600"></span>
                <h2 class="text-lg font-semibold text-white">Inactive Users</h2>
            </div>
            <div class="overflow-x-auto">
                @if ($inactiveUsers->isEmpty())
                    <div class="px-5 py-8 text-center text-slate-500 text-sm">All monitored users are currently active.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">User</th>
                                <th class="px-5 py-3 font-medium">Role</th>
                                <th class="px-5 py-3 font-medium">IP Address</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Last Task</th>
                                <th class="px-5 py-3 font-medium">Last Allocation</th>
                                <th class="px-5 py-3 font-medium">Last Seen</th>
                                <th class="px-5 py-3 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($inactiveUsers as $row)
                                <tr class="hover:bg-slate-800/50 transition-colors opacity-80">
                                    <td class="px-5 py-3 text-slate-300 font-medium">{{ $row->user->name }}</td>
                                    <td class="px-5 py-3">
                                        @include('partials.role-badge', ['role' => $row->user->role])
                                    </td>
                                    <td class="px-5 py-3 font-mono text-slate-500 text-xs">{{ $row->user->ip_address }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-400">Inactive</span>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $row->last_task_type ?? '—' }}</td>
                                    <td class="px-5 py-3 font-mono text-slate-500">{{ $row->last_allocation ?? '—' }}</td>
                                    <td class="px-5 py-3 text-slate-500 whitespace-nowrap">{{ $row->last_seen_at?->format('M j, H:i') ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right">
                                        @if ($row->last_task_type)
                                            <a href="{{ route('allocation-reports', ['user' => $row->user->id, 'task_type' => $row->last_task_type]) }}"
                                               class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors border border-slate-700">
                                                View AL Report
                                            </a>
                                        @else
                                            <a href="{{ route('allocation-reports', ['user' => $row->user->id]) }}"
                                               class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors border border-slate-700">
                                                View AL Report
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </main>
</div>
@endsection
