@extends('layouts.app')

@section('title', 'AL Reports Dashboard')

@push('head')
    <meta http-equiv="refresh" content="30">
@endpush

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Task-Aware Bandwidth</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Allocation Dashboard</h1>
            </div>
            <div class="flex items-center gap-3 text-sm text-slate-400">
                @if ($mikrotikConnected)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        Live — MikroTik connected
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-amber-400">
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                        No live data — router offline
                    </span>
                @endif
                <button onclick="location.reload()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-medium transition-colors">
                    Refresh now
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @include('partials.mikrotik-settings')

        @unless ($mikrotikConnected)
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                <strong>No live allocations.</strong> The dashboard only shows users and bandwidth when MikroTik is connected.
                Allocations come from the router via <code class="text-amber-100">php artisan bandwidth:run</code> — not from old database records.
            </div>
        @endunless

        @if ($mikrotikConnected && !empty($bandwidthMeasureError))
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ $bandwidthMeasureError }}
            </div>
        @endif

        @if ($mikrotikConnected && empty($bandwidthMeasureError) && $stats['active_users'] === 0 && $stats['active_flows'] === 0)
            <div class="rounded-lg border border-slate-700 bg-slate-800/50 px-4 py-3 text-sm text-slate-300">
                MikroTik is connected but no monitored user traffic was detected. Ensure user IP addresses match active connections on the router, then run <code class="text-emerald-300">php artisan bandwidth:run</code>.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Getting Bandwidth</p>
                <p class="mt-1 text-3xl font-semibold text-emerald-400">{{ number_format($stats['active_users']) }}</p>
                <p class="text-xs text-slate-500 mt-1">Online devices with allocation</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Not Using Bandwidth</p>
                <p class="mt-1 text-3xl font-semibold text-slate-400">{{ number_format($stats['inactive_users']) }}</p>
                <p class="text-xs text-slate-500 mt-1">Offline or no traffic</p>
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Devices Online</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['online_devices'] ?? 0) }}</p>
                @if (!empty($stats['activity']))
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $stats['activity']['active'] ?? 0 }} active · {{ $stats['activity']['idle'] ?? 0 }} idle
                    </p>
                @endif
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Total Bandwidth Available</p>
                @if ($mikrotikConnected && $stats['total_available_bandwidth'] !== null)
                    <p class="mt-1 text-3xl font-semibold text-emerald-400">{{ $stats['total_available_bandwidth'] }}</p>
                    <p class="text-xs text-slate-500 mt-1 font-mono">{{ number_format($stats['pool_kbps'] ?? 0) }} Kbps measured</p>
                    @if ($stats['total_available_at'])
                        <p class="text-xs text-slate-500">{{ $stats['total_available_at']->format('M j, H:i:s') }}</p>
                    @endif
                @else
                    <p class="mt-1 text-3xl font-semibold text-slate-500">—</p>
                    <p class="text-xs text-slate-500 mt-1">Connect MikroTik to measure live traffic</p>
                @endif
            </div>
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <p class="text-sm text-slate-400">Total AL Reports</p>
                <p class="mt-1 text-3xl font-semibold text-white">{{ number_format($stats['total_reports']) }}</p>
            </div>
        </div>

        @if ($mikrotikConnected)
            @include('partials.allocation-breakdown', ['allocation' => $allocation])
        @endif

        @include('partials.user-table', [
            'title' => 'Getting Bandwidth',
            'users' => $activeUsers,
            'status' => 'active',
            'emptyMessage' => $mikrotikConnected
                ? 'No users are receiving bandwidth right now. Run the allocator while MikroTik is connected and traffic is active.'
                : 'Connect to MikroTik and run the allocator to see live allocations.',
        ])

        @include('partials.user-table', [
            'title' => 'Not Using Bandwidth',
            'users' => $inactiveUsers,
            'status' => 'inactive',
            'emptyMessage' => 'All monitored users are currently receiving bandwidth.',
        ])
    </main>
</div>
@endsection
