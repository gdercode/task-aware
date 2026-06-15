@extends('layouts.app')

@section('title', 'Add User')

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <a href="{{ route('users.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 transition-colors mb-3">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Users
            </a>
            <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Management</p>
            <h1 class="text-xl sm:text-2xl font-semibold text-white">Add User</h1>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <form method="POST" action="{{ route('users.store') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-6 space-y-4">
            @csrf
            @include('users._form', ['roles' => $roles])
            <div class="pt-2 flex gap-3">
                <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
                    Create user
                </button>
                <a href="{{ route('users.index') }}" class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </main>
</div>
@endsection
