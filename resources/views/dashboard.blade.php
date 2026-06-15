@extends('layouts.app')

@section('title', 'AL Reports Dashboard')

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Task-Aware Bandwidth</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Allocation (AL) Reports Dashboard</h1>
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
        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Total AL Reports</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['total_reports']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Active Flows</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['active_flows']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Users Monitored</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['users_monitored']) }}</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Latest Allocation</p>
                <p class="mt-1 text-3xl font-semibold text-emerald-400">{{ $stats['latest_allocation'] ?? '—' }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- AL Reports table --}}
            <div class="lg:col-span-2 rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-white">Allocation Reports</h2>
                    <span class="text-xs text-slate-500">Last 100 entries</span>
                </div>
                <div class="overflow-x-auto">
                    @if ($reports->isEmpty())
                        <div class="px-5 py-12 text-center text-slate-500">
                            <p class="text-sm">No allocation reports yet.</p>
                            <p class="text-xs mt-1">Run <code class="text-emerald-400">php artisan bandwidth:run</code> to start logging allocations.</p>
                        </div>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-400 border-b border-slate-800">
                                    <th class="px-5 py-3 font-medium">Time</th>
                                    <th class="px-5 py-3 font-medium">User</th>
                                    <th class="px-5 py-3 font-medium">Role</th>
                                    <th class="px-5 py-3 font-medium">Task Type</th>
                                    <th class="px-5 py-3 font-medium text-right">Score</th>
                                    <th class="px-5 py-3 font-medium text-right">Bandwidth</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                @foreach ($reports as $report)
                                    <tr class="hover:bg-slate-800/50 transition-colors">
                                        <td class="px-5 py-3 text-slate-400 whitespace-nowrap">{{ $report->created_at->format('M j, H:i:s') }}</td>
                                        <td class="px-5 py-3 text-white font-medium">{{ $report->user?->name ?? 'Unknown' }}</td>
                                        <td class="px-5 py-3">
                                            @php $role = $report->user?->role ?? 'unknown'; @endphp
                                            <span @class([
                                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize',
                                                'bg-purple-500/20 text-purple-300' => $role === 'dean',
                                                'bg-blue-500/20 text-blue-300' => $role === 'lecturer',
                                                'bg-slate-500/20 text-slate-300' => $role === 'student',
                                                'bg-slate-700 text-slate-400' => !in_array($role, ['dean', 'lecturer', 'student']),
                                            ])>{{ $role }}</span>
                                        </td>
                                        <td class="px-5 py-3">
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-mono bg-slate-800 text-slate-300">{{ $report->task_type }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $report->importance_score }}</td>
                                        <td class="px-5 py-3 text-right font-mono text-emerald-400 font-medium">{{ $report->allocated_bandwidth }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            {{-- Side panels --}}
            <div class="space-y-6">
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-semibold text-white mb-4">By Task Type</h2>
                    @if ($byTaskType->isEmpty())
                        <p class="text-sm text-slate-500">No data yet.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach ($byTaskType as $row)
                                <li class="flex items-center justify-between text-sm">
                                    <span class="font-mono text-slate-300">{{ $row->task_type }}</span>
                                    <span class="text-slate-400">{{ number_format($row->total) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                    <h2 class="text-lg font-semibold text-white mb-4">By User Role</h2>
                    @if ($byRole->isEmpty())
                        <p class="text-sm text-slate-500">No data yet.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach ($byRole as $row)
                                <li class="flex items-center justify-between text-sm">
                                    <span class="capitalize text-slate-300">{{ $row->role }}</span>
                                    <span class="text-slate-400">{{ number_format($row->total) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Active flows --}}
        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Active Traffic Flows</h2>
            </div>
            <div class="overflow-x-auto">
                @if ($activeFlows->isEmpty())
                    <div class="px-5 py-8 text-center text-slate-500 text-sm">No active flows. Hit <code class="text-emerald-400">/detect-traffic</code> to analyze connections.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">User</th>
                                <th class="px-5 py-3 font-medium">Role</th>
                                <th class="px-5 py-3 font-medium">Classification</th>
                                <th class="px-5 py-3 font-medium">Destination</th>
                                <th class="px-5 py-3 font-medium text-right">Bytes</th>
                                <th class="px-5 py-3 font-medium text-right">Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($activeFlows as $flow)
                                <tr class="hover:bg-slate-800/50 transition-colors">
                                    <td class="px-5 py-3 text-white font-medium">{{ $flow->user?->name ?? 'Unknown' }}</td>
                                    <td class="px-5 py-3 capitalize text-slate-400">{{ $flow->user?->role }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-300">{{ $flow->classification ?? $flow->task_type }}</td>
                                    <td class="px-5 py-3 text-slate-400 truncate max-w-[200px]">{{ $flow->destination ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-slate-400">{{ number_format($flow->bytes) }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $flow->importance_score ?? '—' }}</td>
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
