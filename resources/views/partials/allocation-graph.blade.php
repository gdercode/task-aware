@php
    $chartRoles = \App\Models\Role::query()->orderByDesc('percentage')->orderBy('name')->get();
    $palette = ['#34d399', '#38bdf8', '#a78bfa', '#fbbf24', '#fb7185', '#2dd4bf', '#f472b6', '#94a3b8'];
    $roleColor = [];
    foreach ($chartRoles as $index => $role) {
        $roleColor[$role->slug] = $palette[$index % count($palette)];
    }
    $sharing = collect($allocation['users'] ?? [])->filter(fn ($row) => (int) ($row->share_kbps ?? 0) > 0)->values();
    $blocked = collect($allocation['users'] ?? [])->filter(fn ($row) => (int) ($row->role_percentage ?? -1) === 0)->values();
    $weightTotal = (int) $sharing->sum(fn ($row) => (int) ($row->role_percentage ?? 0));
    $poolKbps = (int) ($allocation['pool_kbps'] ?? 0);
@endphp

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">How a weight becomes a queue</h2>
        <p class="text-sm text-slate-400 mt-1">
            Every role has a weight. Devices using bandwidth now add those weights together.
            Each device’s queue is its weight divided by that total, and that limit is sent to the router through the API.
            A weight of 0% is blocked instead.
        </p>
    </div>

    <div class="px-5 py-5 space-y-6">
        <div>
            <div class="flex items-baseline justify-between gap-3 mb-2">
                <h3 class="text-sm font-medium text-slate-200">1. Role weights</h3>
                <span class="text-xs font-mono text-slate-500">{{ $chartRoles->sum('percentage') }}% total</span>
            </div>
            @if ($chartRoles->isEmpty())
                <p class="text-sm text-slate-500">No roles yet. Add roles and give each one a percentage.</p>
            @else
                <svg viewBox="0 0 1000 40" class="w-full h-10 rounded-lg overflow-hidden" role="img" aria-label="Role weights that add up to 100 percent">
                    @php $cursor = 0; $basis = max(1, (int) $chartRoles->sum('percentage')); @endphp
                    @foreach ($chartRoles as $role)
                        @if ((int) $role->percentage <= 0)
                            @continue
                        @endif
                        @php
                            $width = ((int) $role->percentage / $basis) * 1000;
                        @endphp
                        <rect x="{{ $cursor }}" y="0" width="{{ $width }}" height="40" fill="{{ $roleColor[$role->slug] }}"></rect>
                        @if ($width >= 70)
                            <text x="{{ $cursor + ($width / 2) }}" y="25" text-anchor="middle" fill="#0f172a" font-size="16" font-family="ui-sans-serif, system-ui, sans-serif" font-weight="600">{{ $role->percentage }}%</text>
                        @endif
                        @php $cursor += $width; @endphp
                    @endforeach
                </svg>
                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-300">
                    @foreach ($chartRoles as $role)
                        <li class="inline-flex items-center gap-2">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ (int) $role->percentage <= 0 ? '#f87171' : $roleColor[$role->slug] }}"></span>
                            <span>{{ $role->name }}</span>
                            <span class="font-mono text-slate-400">{{ (int) $role->percentage <= 0 ? 'Blocked' : $role->percentage.'%' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <div class="flex items-baseline justify-between gap-3 mb-2">
                <h3 class="text-sm font-medium text-slate-200">2. Devices using bandwidth now</h3>
                @if ($sharing->isNotEmpty())
                    <span class="text-xs font-mono text-slate-500">weights add up to {{ $weightTotal }}</span>
                @endif
            </div>

            @if ($sharing->isEmpty())
                <p class="text-sm text-slate-400 mb-3">
                    No device is using bandwidth in the current report, so no share queue is being written.
                    When one device has a weight of 1% and another has 99%, and both are using bandwidth, the queues become 1% and 99% of the pool.
                </p>
                <svg viewBox="0 0 1000 40" class="w-full h-10 rounded-lg overflow-hidden" role="img" aria-label="Example: a 1 percent device and a 99 percent device">
                    <rect x="0" y="0" width="10" height="40" fill="#fbbf24"></rect>
                    <rect x="10" y="0" width="990" height="40" fill="#34d399"></rect>
                    <text x="505" y="25" text-anchor="middle" fill="#0f172a" font-size="16" font-family="ui-sans-serif, system-ui, sans-serif" font-weight="600">99%</text>
                </svg>
                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-300">
                    <li class="inline-flex items-center gap-2">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm bg-amber-400"></span>
                        <span>1% device</span>
                        <span class="font-mono text-slate-400">{{ $poolKbps > 0 ? number_format((int) round($poolKbps * 0.01)).' Kbps' : '1% of the pool' }}</span>
                    </li>
                    <li class="inline-flex items-center gap-2">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-400"></span>
                        <span>99% device</span>
                        <span class="font-mono text-slate-400">{{ $poolKbps > 0 ? number_format((int) round($poolKbps * 0.99)).' Kbps' : '99% of the pool' }}</span>
                    </li>
                </ul>
                <p class="text-xs text-slate-500 mt-2">Example only. It is not a live queue until both devices are using bandwidth and the allocator can reach the router.</p>
            @else
                <svg viewBox="0 0 1000 40" class="w-full h-10 rounded-lg overflow-hidden" role="img" aria-label="Share of the pool for devices using bandwidth now">
                    @php $cursor = 0; @endphp
                    @foreach ($sharing as $row)
                        @php
                            $width = ((float) $row->share_percent / 100) * 1000;
                            $color = $roleColor[$row->user->role] ?? '#94a3b8';
                        @endphp
                        <rect x="{{ $cursor }}" y="0" width="{{ max($width, 0) }}" height="40" fill="{{ $color }}"></rect>
                        @if ($width >= 70)
                            <text x="{{ $cursor + ($width / 2) }}" y="25" text-anchor="middle" fill="#0f172a" font-size="16" font-family="ui-sans-serif, system-ui, sans-serif" font-weight="600">{{ $row->share_percent }}%</text>
                        @endif
                        @php $cursor += $width; @endphp
                    @endforeach
                </svg>
                <ul class="mt-4 space-y-2">
                    @foreach ($sharing as $row)
                        @php $color = $roleColor[$row->user->role] ?? '#94a3b8'; @endphp
                        <li class="flex flex-col sm:flex-row sm:items-center gap-2 text-sm">
                            <span class="inline-flex items-center gap-2 sm:w-48 shrink-0 text-slate-200">
                                <span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $color }}"></span>
                                {{ $row->user->name }}
                            </span>
                            <span class="text-slate-400">
                                weight {{ (int) ($row->role_percentage ?? 0) }}%
                                <span class="text-slate-600">→</span>
                                share {{ $row->share_percent }}%
                                <span class="text-slate-600">→</span>
                                <span class="font-mono text-emerald-300">{{ $row->bandwidth ?? $row->kbps_display }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-slate-500 mt-3">
                    The allocator writes each of those limits onto the device queue through the MikroTik API.
                    @if ($poolKbps > 0)
                        The pool in this report is {{ number_format($poolKbps) }} Kbps.
                    @endif
                </p>
            @endif

            @if ($blocked->isNotEmpty())
                <p class="text-sm text-red-300 mt-3">
                    Blocked at 0%:
                    {{ $blocked->map(fn ($row) => $row->user->name)->implode(', ') }}.
                    These devices are dropped on the router instead of receiving a share.
                </p>
            @endif
        </div>
    </div>
</div>
