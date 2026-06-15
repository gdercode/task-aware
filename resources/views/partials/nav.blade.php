<nav class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-sm sticky top-0 z-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-white hover:text-emerald-400 transition-colors">
                    Task-Aware
                </a>
                <div class="flex items-center gap-1">
                    <a href="{{ route('dashboard') }}"
                       @class([
                           'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                           'bg-slate-800 text-white' => request()->routeIs('dashboard'),
                           'text-slate-400 hover:text-white hover:bg-slate-800/50' => !request()->routeIs('dashboard'),
                       ])>
                        Dashboard
                    </a>
                    <a href="{{ route('users.index') }}"
                       @class([
                           'px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                           'bg-slate-800 text-white' => request()->routeIs('users.*'),
                           'text-slate-400 hover:text-white hover:bg-slate-800/50' => !request()->routeIs('users.*'),
                       ])>
                        Users
                    </a>
                </div>
            </div>
            @hasSection('nav-actions')
                <div class="flex items-center gap-3 text-sm">
                    @yield('nav-actions')
                </div>
            @endif
        </div>
    </div>
</nav>
