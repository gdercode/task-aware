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

        @include('partials.user-table', [
            'title' => 'Active Users',
            'users' => $activeUsers,
            'status' => 'active',
            'emptyMessage' => 'No active users right now.',
        ])

        @include('partials.user-table', [
            'title' => 'Inactive Users',
            'users' => $inactiveUsers,
            'status' => 'inactive',
            'emptyMessage' => 'All monitored users are currently active.',
        ])
    </main>
</div>
@endsection
