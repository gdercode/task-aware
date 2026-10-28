@extends('layouts.app')

@section('title', 'Dashboard')

@push('head')
    <script>
        setInterval(function () {
            var el = document.activeElement;
            if (el && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
                return;
            }
            location.reload();
        }, 5000);
    </script>
@endpush

@section('content')
@php
    $live = $reportState === 'live';
    $congested = $live && (bool) ($allocation['congested'] ?? false);
    $utilization = (int) ($allocation['utilization_percent'] ?? 0);
    $receiving = $activeUsers->sortByDesc(fn ($row) => (int) ($row->share_kbps ?? 0))->values();
@endphp

<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Task-Aware Bandwidth</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Dashboard</h1>
            </div>
            <div class="flex items-center gap-3 text-sm text-slate-400">
                @if ($live)
                    <span class="inline-flex items-center gap-1.5 text-emerald-300">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        Live
                        @if ($reportUpdatedAt)
                            <span class="text-slate-500">· {{ $reportUpdatedAt->diffForHumans() }}</span>
                        @endif
                    </span>
                @elseif ($reportState === 'offline')
                    <span class="inline-flex items-center gap-1.5 text-amber-300">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                        Router offline
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-amber-300">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                        Allocator stopped
                    </span>
                @endif
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @if (! $live)
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                @if ($reportState === 'offline')
                    The router is not responding, so there is no live allocation.
                @else
                    Start <code class="text-amber-100">php artisan bandwidth:run</code> to publish a live allocation.
                @endif
            </div>
        @elseif (! empty($bandwidthMeasureError))
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ $bandwidthMeasureError }}
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div @class([
                'rounded-xl border p-5',
                'border-amber-500/40 bg-amber-500/10' => $congested,
                'border-slate-800 bg-slate-900' => ! $congested,
            ])>
                <p class="text-sm text-slate-400">Network</p>
                <p @class(['mt-1 text-2xl font-semibold', 'text-amber-200' => $congested, 'text-white' => ! $congested])>
                    {{ $live ? ($allocation['congestion_label'] ?? 'Stable') : '—' }}
                </p>
                <p class="text-xs text-slate-500 mt-1">
                    {{ $live ? $utilization.'% of capacity in use' : 'No live report' }}
                </p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">In use</p>
                <p class="mt-1 text-2xl font-semibold text-sky-300">
                    {{ $live ? number_format($stats['total_throughput_kbps'] ?? 0) : '—' }}
                </p>
                <p class="text-xs text-slate-500 mt-1">Kbps moving now</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Pool</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-400">
                    {{ $live ? number_format($stats['pool_kbps'] ?? 0) : '—' }}
                </p>
                <p class="text-xs text-slate-500 mt-1">Kbps available to share</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Receiving</p>
                <p class="mt-1 text-2xl font-semibold text-white">{{ number_format($receiving->count()) }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    {{ number_format($stats['online_devices'] ?? 0) }} online
                    · {{ number_format($inactiveUsers->count()) }} waiting
                </p>
            </div>
        </div>

        <section class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Allocations</h2>
                <p class="text-sm text-slate-400 mt-1">Role, task, and urgency set the share. Unused bandwidth moves to a device that is filling its queue.</p>
            </div>
            @if ($receiving->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-500 text-center">
                    {{ $live ? 'No device is receiving bandwidth.' : 'Allocations appear here once the report is live.' }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">Device</th>
                                <th class="px-5 py-3 font-medium">Role</th>
                                <th class="px-5 py-3 font-medium">Task</th>
                                <th class="px-5 py-3 font-medium text-right">In use</th>
                                <th class="px-5 py-3 font-medium text-right">Allocated</th>
                                <th class="px-5 py-3 font-medium">Share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($receiving as $row)
                                @php
                                    $share = min(100, max(0, (float) ($row->share_percent ?? 0)));
                                @endphp
                                <tr class="hover:bg-slate-800/50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('allocation-reports', $row->user) }}" class="font-medium text-white hover:text-emerald-300">
                                            {{ $row->user->name }}
                                        </a>
                                        <span class="block text-xs font-mono text-slate-500">{{ $row->user->ip_address }}</span>
                                    </td>
                                    <td class="px-5 py-3">
                                        @include('partials.role-badge', ['role' => $row->user->role])
                                    </td>
                                    <td class="px-5 py-3 text-slate-200">
                                        {{ $row->task_label ?? 'General use' }}
                                        <span class="block text-xs text-slate-500">Urgency {{ (int) ($row->urgency ?? 0) }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-sky-300">
                                        {{ number_format((int) ($row->throughput_total_kbps ?? 0)) }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-emerald-400">
                                        {{ number_format((int) ($row->share_kbps ?? 0)) }}
                                    </td>
                                    <td class="px-5 py-3 min-w-[140px]">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 flex-1 rounded-full bg-slate-800 overflow-hidden">
                                                <div class="h-full rounded-full {{ $congested ? 'bg-amber-400' : 'bg-emerald-400' }}" style="width: {{ $share }}%"></div>
                                            </div>
                                            <span class="w-10 text-right font-mono text-xs text-slate-300">{{ (int) round($share) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($inactiveUsers->isNotEmpty())
            <section class="rounded-xl border border-slate-800 bg-slate-900 px-5 py-4">
                <h2 class="text-sm font-medium text-slate-300">Not receiving bandwidth</h2>
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach ($inactiveUsers as $row)
                        <li class="inline-flex items-center gap-2 rounded-full border border-slate-700 bg-slate-800/60 px-3 py-1 text-xs text-slate-300">
                            <span class="text-white">{{ $row->user->name }}</span>
                            <span class="text-slate-500">{{ $row->offline_reason ?? 'Not using' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <details class="rounded-xl border border-slate-800 bg-slate-900" @if ($errors->any()) open @endif>
            <summary class="cursor-pointer px-5 py-4 text-sm text-slate-300 flex items-center justify-between gap-3">
                <span>
                    Router
                    <span class="font-mono text-slate-400">{{ $mikrotikSettings->host }}:{{ $mikrotikSettings->port }}</span>
                </span>
                <span @class([
                    'text-xs font-medium',
                    'text-emerald-300' => $live,
                    'text-amber-300' => ! $live,
                ])>{{ $live ? 'Connected' : 'Not connected' }}</span>
            </summary>
            <div class="px-5 pb-5 border-t border-slate-800">
                @include('partials.mikrotik-settings')
            </div>
        </details>
    </main>
</div>
@endsection
