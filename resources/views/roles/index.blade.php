@extends('layouts.app')

@section('title', 'Roles')

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Management</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Roles</h1>
                <p class="text-sm text-slate-400 mt-1">Percentages always add up to 100. Bandwidth is shared using these percentages.</p>
            </div>
            <a href="{{ route('roles.create') }}"
               class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
                Add role
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @if (session('success'))
            <div class="mb-6 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                {{ session('error') }}
            </div>
        @endif

        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-800">
                            <th class="px-5 py-3 font-medium">Role</th>
                            <th class="px-5 py-3 font-medium">Percentage</th>
                            <th class="px-5 py-3 font-medium">Users</th>
                            <th class="px-5 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($roles as $role)
                            <tr class="hover:bg-slate-800/50 transition-colors">
                                <td class="px-5 py-3 text-white font-medium">
                                    {{ $role->name }}
                                    @if ($role->is_default)
                                        <span class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300">Default</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 font-mono text-emerald-400">{{ $role->percentage }}%</td>
                                <td class="px-5 py-3 text-slate-400">{{ $role->users_count }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('roles.edit', $role) }}"
                                           class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium border border-slate-700 transition-colors">
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('roles.destroy', $role) }}" class="inline"
                                              onsubmit="return confirm('Delete this role?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-3 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 text-xs font-medium border border-red-500/20 transition-colors">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center text-slate-500">
                                    No roles yet. <a href="{{ route('roles.create') }}" class="text-emerald-400 hover:underline">Add one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($roles->isNotEmpty())
                        <tfoot>
                            <tr class="border-t border-slate-800 bg-slate-800/30">
                                <td class="px-5 py-3 text-slate-400">Total</td>
                                <td class="px-5 py-3 font-mono text-white">{{ $roles->sum('percentage') }}%</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </main>
</div>
@endsection
