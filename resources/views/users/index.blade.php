@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Management</p>
                <h1 class="text-xl sm:text-2xl font-semibold text-white">Users</h1>
            </div>
            <a href="{{ route('users.create') }}"
               class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
                Add user
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @if (session('success'))
            <div class="mb-6 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-800">
                            <th class="px-5 py-3 font-medium">Name</th>
                            <th class="px-5 py-3 font-medium">Email</th>
                            <th class="px-5 py-3 font-medium">Role</th>
                            <th class="px-5 py-3 font-medium">IP Address</th>
                            <th class="px-5 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($users as $user)
                            <tr class="hover:bg-slate-800/50 transition-colors">
                                <td class="px-5 py-3 text-white font-medium">{{ $user->name }}</td>
                                <td class="px-5 py-3 text-slate-400">{{ $user->email }}</td>
                                <td class="px-5 py-3">
                                    @include('partials.role-badge', ['role' => $user->role])
                                </td>
                                <td class="px-5 py-3 font-mono text-slate-400 text-xs">{{ $user->ip_address ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('allocation-reports', $user) }}"
                                           class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium border border-slate-700 transition-colors">
                                            Reports
                                        </a>
                                        <a href="{{ route('users.edit', $user) }}"
                                           class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium border border-slate-700 transition-colors">
                                            Edit
                                        </a>
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline"
                                              onsubmit="return confirm('Delete this user?')">
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
                                <td colspan="5" class="px-5 py-12 text-center text-slate-500">
                                    No users yet. <a href="{{ route('users.create') }}" class="text-emerald-400 hover:underline">Add one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($users->hasPages())
                <div class="px-5 py-4 border-t border-slate-800">
                    {{ $users->links() }}
                </div>
            @endif
        </div>
    </main>
</div>
@endsection
