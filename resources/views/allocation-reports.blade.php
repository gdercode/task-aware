@extends('layouts.app')

@section('title', 'AL Report — ' . $user->name)

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 transition-colors mb-3">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Dashboard
            </a>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Allocation Report</p>
                    <h1 class="text-xl sm:text-2xl font-semibold text-white">{{ $user->name }}</h1>
                    <p class="text-sm text-slate-400 mt-0.5">
                        @include('partials.role-badge', ['role' => $user->role])
                        <span class="ml-2 font-mono text-xs">{{ $user->ip_address }}</span>
                    </p>
                </div>
                @if ($taskType)
                    <span class="inline-flex self-start px-3 py-1 rounded-lg text-sm font-mono bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">
                        Task: {{ $taskType }}
                    </span>
                @endif
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        @if ($taskTypes->count() > 1)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('allocation-reports', $user) }}"
                   @class([
                       'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors border',
                       'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' => !$taskType,
                       'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700' => $taskType,
                   ])>
                    All Tasks
                </a>
                @foreach ($taskTypes as $type)
                    <a href="{{ route('allocation-reports', ['user' => $user->id, 'task_type' => $type]) }}"
                       @class([
                           'px-3 py-1.5 rounded-lg text-xs font-mono font-medium transition-colors border',
                           'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' => $taskType === $type,
                           'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700' => $taskType !== $type,
                       ])>
                        {{ $type }}
                    </a>
                @endforeach
            </div>
        @endif

        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white">
                    @if ($taskType)
                        Report history for <span class="font-mono text-emerald-400">{{ $taskType }}</span>
                    @else
                        Full allocation history
                    @endif
                </h2>
                <span class="text-xs text-slate-500">{{ $reports->total() }} entries</span>
            </div>
            <div class="overflow-x-auto">
                @if ($reports->isEmpty())
                    <div class="px-5 py-12 text-center text-slate-500">
                        <p class="text-sm">
                            No allocation reports found for this user{{ $taskType ? ' and task' : '' }}.
                        </p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">Time</th>
                                <th class="px-5 py-3 font-medium">Task Type</th>
                                <th class="px-5 py-3 font-medium text-right">Score</th>
                                <th class="px-5 py-3 font-medium text-right">Bandwidth</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($reports as $report)
                                <tr class="hover:bg-slate-800/50 transition-colors">
                                    <td class="px-5 py-3 text-slate-400 whitespace-nowrap">{{ $report->created_at->format('M j, Y H:i:s') }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-mono bg-slate-800 text-slate-300">{{ $report->task_type }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $report->importance_score }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-emerald-400 font-medium">{{ $report->allocated_bandwidth }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($reports->hasPages())
                        <div class="px-5 py-4 border-t border-slate-800">
                            {{ $reports->withQueryString()->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </main>
</div>
@endsection
