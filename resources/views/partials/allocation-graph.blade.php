@php
    $chartRoles = \App\Models\Role::query()->orderByDesc('percentage')->orderBy('name')->get();
    $palette = ['#34d399', '#38bdf8', '#a78bfa', '#fbbf24', '#fb7185', '#2dd4bf', '#f472b6', '#94a3b8'];
    $roleColor = [];
    foreach ($chartRoles as $index => $role) {
        $roleColor[$role->slug] = $palette[$index % count($palette)];
    }

    $sharing = collect($allocation['users'] ?? [])->filter(fn ($row) => (int) ($row->share_kbps ?? 0) > 0)->values();
    $bars = [];

    if ($sharing->isNotEmpty()) {
        foreach ($sharing as $row) {
            $bars[] = [
                'label' => $row->user->name,
                'value' => (float) $row->share_percent,
                'caption' => number_format((int) $row->share_kbps).' Kbps',
                'color' => $roleColor[$row->user->role] ?? '#94a3b8',
            ];
        }
        $heading = 'Live share';
        $note = 'Each column is that device’s queue as a percent of the pool.';
    } else {
        foreach ($chartRoles as $role) {
            if ((int) $role->percentage <= 0) {
                continue;
            }
            $bars[] = [
                'label' => $role->name,
                'value' => (int) $role->percentage,
                'caption' => $role->percentage.'% weight',
                'color' => $roleColor[$role->slug],
            ];
        }
        $heading = 'Role weights';
        $note = 'These weights are what a device carries while it is using bandwidth.';
    }

    $plotLeft = 44;
    $plotRight = 16;
    $plotTop = 16;
    $plotBottom = 72;
    $width = 720;
    $height = 280;
    $plotWidth = $width - $plotLeft - $plotRight;
    $plotHeight = $height - $plotTop - $plotBottom;
    $count = max(1, count($bars));
    $slot = $plotWidth / $count;
    $barWidth = min(72, $slot * 0.55);
@endphp

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 border-b border-slate-800">
        <div>
            <h2 class="text-lg font-semibold text-white">{{ $heading }}</h2>
            <p class="text-sm text-slate-400 mt-1">{{ $note }}</p>
        </div>
        @if ($sharing->isNotEmpty())
            <p class="text-sm font-mono text-slate-300">{{ number_format((int) ($allocation['pool_kbps'] ?? 0)) }} Kbps pool</p>
        @endif
    </div>

    @if ($bars === [])
        <p class="px-5 py-10 text-sm text-slate-500">No roles yet. Add a role and set its percentage to see the chart.</p>
    @else
        <div class="px-3 pt-4 pb-2 overflow-x-auto">
            <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[520px] h-72" role="img" aria-label="{{ $heading }}">
                @foreach ([0, 25, 50, 75, 100] as $tick)
                    @php $y = $plotTop + $plotHeight - ($tick / 100 * $plotHeight); @endphp
                    <line x1="{{ $plotLeft }}" y1="{{ $y }}" x2="{{ $width - $plotRight }}" y2="{{ $y }}" stroke="#1e293b" stroke-width="1"></line>
                    <text x="{{ $plotLeft - 8 }}" y="{{ $y + 4 }}" text-anchor="end" fill="#64748b" font-size="12" font-family="ui-sans-serif, system-ui, sans-serif">{{ $tick }}%</text>
                @endforeach

                @foreach ($bars as $index => $bar)
                    @php
                        $value = max(0, min(100, (float) $bar['value']));
                        $barHeight = $value / 100 * $plotHeight;
                        $x = $plotLeft + ($index * $slot) + (($slot - $barWidth) / 2);
                        $y = $plotTop + $plotHeight - $barHeight;
                    @endphp
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ max($barHeight, 0) }}" rx="6" fill="{{ $bar['color'] }}"></rect>
                    <text x="{{ $x + ($barWidth / 2) }}" y="{{ max($plotTop + 14, $y - 8) }}" text-anchor="middle" fill="#e2e8f0" font-size="13" font-family="ui-sans-serif, system-ui, sans-serif" font-weight="600">{{ rtrim(rtrim(number_format($value, 1), '0'), '.') }}%</text>
                    <text x="{{ $x + ($barWidth / 2) }}" y="{{ $plotTop + $plotHeight + 22 }}" text-anchor="middle" fill="#e2e8f0" font-size="13" font-family="ui-sans-serif, system-ui, sans-serif">{{ \Illuminate\Support\Str::limit($bar['label'], 16) }}</text>
                    <text x="{{ $x + ($barWidth / 2) }}" y="{{ $plotTop + $plotHeight + 40 }}" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="ui-monospace, monospace">{{ $bar['caption'] }}</text>
                @endforeach
            </svg>
        </div>
    @endif
</div>
