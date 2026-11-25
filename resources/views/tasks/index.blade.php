@extends('layouts.app')

@section('title', 'Tasks')

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Management</p>
            <h1 class="text-xl sm:text-2xl font-semibold text-white">Tasks</h1>
            <p class="text-sm text-slate-400 mt-1 max-w-3xl">A destination is matched to the first task whose words it contains. Priority is role + task + urgency + failure risk. If utilization stays above the threshold, critical tasks are raised and best-effort tasks are reduced but keep a minimum share.</p>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('tasks.store') }}" class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            @csrf
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Add task</h2>
                <p class="text-sm text-slate-400 mt-1">The new row is checked after the tasks already listed. A word that appears in an earlier row still belongs to that earlier task.</p>
            </div>
            <div class="px-5 py-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <label class="block lg:col-span-2">
                    <span class="text-xs font-medium text-slate-400">Task</span>
                    <input type="text" name="name" required maxlength="80" value="{{ old('name') }}"
                           placeholder="Campus portal"
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-400">Task weight</span>
                    <input type="number" name="weight" required min="1" max="100" value="{{ old('weight', 2) }}"
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-emerald-400">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-400">Urgency</span>
                    <input type="number" name="urgency" required min="1" max="5" value="{{ old('urgency', 2) }}"
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-white">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-400">Failure risk</span>
                    <input type="number" name="failure_risk" required min="1" max="5" value="{{ old('failure_risk', 2) }}"
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-amber-300">
                </label>
                <label class="flex items-end gap-2 pb-2 text-sm text-slate-300">
                    <input type="checkbox" name="is_critical" value="1" @checked(old('is_critical'))
                           class="rounded border-slate-600 bg-slate-950 text-emerald-500">
                    Critical
                </label>
                <label class="block sm:col-span-2 lg:col-span-5">
                    <span class="text-xs font-medium text-slate-400">Destination contains</span>
                    <input type="text" name="match_terms" required maxlength="1000" value="{{ old('match_terms') }}"
                           placeholder="portal.university.edu, intranet"
                           class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-slate-200">
                </label>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">
                        Add task
                    </button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('tasks.update') }}" class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            @csrf
            @method('PUT')
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Priority</h2>
                <p class="text-sm text-slate-400 mt-1">These three weights are added to the user’s role. Rows are checked from top to bottom, so a word in an earlier row wins.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-800">
                            <th class="px-5 py-3 font-medium">Task</th>
                            <th class="px-5 py-3 font-medium">Task weight</th>
                            <th class="px-5 py-3 font-medium">Urgency</th>
                            <th class="px-5 py-3 font-medium">Failure risk</th>
                            <th class="px-5 py-3 font-medium">Critical</th>
                            <th class="px-5 py-3 font-medium">Destination contains</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($tasks as $task)
                            <tr class="align-top">
                                <td class="px-5 py-3 min-w-[180px]">
                                    <input type="text" name="tasks[{{ $task->id }}][name]" value="{{ old('tasks.'.$task->id.'.name', $task->name) }}"
                                           class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white">
                                    <p class="mt-1 text-xs text-emerald-300/80">{{ $task->classLabel() }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        @switch($task->slug)
                                            @case('DATA_TRANSFER')
                                                Used for an upload that did not match a name above.
                                                @break
                                            @case('BULK')
                                                Used when no name matches and the connection is larger than 50 MB. That size uses urgency 1.
                                                @break
                                            @case('NORMAL')
                                                Used when the destination matches nothing else.
                                                @break
                                            @default
                                                Checked {{ $task->sort_order }}{{ $task->sort_order === 1 ? 'st' : ($task->sort_order === 2 ? 'nd' : ($task->sort_order === 3 ? 'rd' : 'th')) }}.
                                        @endswitch
                                    </p>
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" name="tasks[{{ $task->id }}][weight]" min="1" max="100" required
                                           value="{{ old('tasks.'.$task->id.'.weight', $task->weight) }}"
                                           class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-emerald-400">
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" name="tasks[{{ $task->id }}][urgency]" min="1" max="5" required
                                           value="{{ old('tasks.'.$task->id.'.urgency', $task->urgency) }}"
                                           class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-white">
                                </td>
                                <td class="px-5 py-3">
                                    <input type="number" name="tasks[{{ $task->id }}][failure_risk]" min="1" max="5" required
                                           value="{{ old('tasks.'.$task->id.'.failure_risk', $task->failure_risk) }}"
                                           class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-amber-300">
                                </td>
                                <td class="px-5 py-3">
                                    <label class="inline-flex items-center gap-2 text-slate-300">
                                        <input type="checkbox" name="tasks[{{ $task->id }}][is_critical]" value="1"
                                               @checked(old('tasks.'.$task->id.'.is_critical', $task->is_critical))
                                               class="rounded border-slate-600 bg-slate-950 text-emerald-500">
                                        Yes
                                    </label>
                                </td>
                                <td class="px-5 py-3 min-w-[280px]">
                                    <input type="text" name="tasks[{{ $task->id }}][match_terms]"
                                           value="{{ old('tasks.'.$task->id.'.match_terms', $task->match_terms) }}"
                                           placeholder="zoom, teams, meet"
                                           class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-slate-200">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4 border-t border-slate-800 flex justify-end">
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium">
                    Save priorities
                </button>
            </div>
        </form>

        <section class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Destinations</h2>
                <p class="text-sm text-slate-400 mt-1">These are the addresses reported for registered computers. The task is chosen by the words above.</p>
            </div>
            @if ($flows->isEmpty())
                <p class="px-5 py-10 text-sm text-slate-500 text-center">No destination has been reported yet. Use Apply changes on the dashboard after a computer is online.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-800">
                                <th class="px-5 py-3 font-medium">Computer</th>
                                <th class="px-5 py-3 font-medium">Destination</th>
                                <th class="px-5 py-3 font-medium">Task</th>
                                <th class="px-5 py-3 font-medium text-right">Bytes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($flows as $flow)
                                <tr>
                                    <td class="px-5 py-3">
                                        <span class="text-white">{{ $flow->user->name ?? 'Unknown' }}</span>
                                        <span class="block text-xs font-mono text-slate-500">{{ $flow->source_ip }}</span>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-sky-300 break-all">{{ $flow->destination ?: '—' }}</td>
                                    <td class="px-5 py-3 text-slate-200">{{ \App\Models\TaskPriority::labelFor($flow->classification ?: $flow->task_type) ?? ($flow->classification ?: $flow->task_type) }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-slate-300">{{ number_format((int) $flow->bytes) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
</div>
@endsection
