@php
    $rows = collect($allocation['users'] ?? []);
    $points = [];

    if ($rows->isNotEmpty()) {
        foreach ($rows as $row) {
            $used = (int) ($row->throughput_total_kbps ?? 0);
            $allocated = (int) ($row->share_kbps ?? 0);
            $points[] = [
                'label' => $row->user->name,
                'used' => $used,
                'allocated' => $allocated,
                'percent' => $allocated > 0 ? (int) round(($used / $allocated) * 100) : null,
            ];
        }
    } else {
        foreach ($users ?? [] as $user) {
            $points[] = [
                'label' => $user->name,
                'used' => 0,
                'allocated' => 0,
                'percent' => null,
            ];
        }
    }

    usort($points, function (array $a, array $b) {
        return [$b['used'], $a['label']] <=> [$a['used'], $b['label']];
    });

    $totalUsed = array_sum(array_column($points, 'used'));
    $totalAllocated = array_sum(array_column($points, 'allocated'));
@endphp

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-white">How data is used</h2>
            <p class="text-sm text-slate-400 mt-1">In use is the data moving now. Queue is what that device was given.</p>
        </div>
        <div class="flex gap-6">
            <div>
                <p class="text-xs text-slate-500">In use</p>
                <p class="text-2xl font-semibold font-mono text-sky-300">{{ number_format($totalUsed) }} <span class="text-sm font-normal text-slate-500">Kbps</span></p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Queue</p>
                <p class="text-2xl font-semibold font-mono text-emerald-300">{{ number_format($totalAllocated) }} <span class="text-sm font-normal text-slate-500">Kbps</span></p>
            </div>
        </div>
    </div>

    @if ($points === [])
        <p class="px-5 py-10 text-sm text-slate-500">No devices are registered, so there is no data use to show.</p>
    @else
        <ul class="divide-y divide-slate-800">
            @foreach ($points as $point)
                @php
                    $width = $point['percent'] === null ? 0 : min(100, $point['percent']);
                @endphp
                <li class="px-5 py-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-white font-medium">{{ $point['label'] }}</p>
                        <p class="font-mono text-lg text-sky-300">{{ number_format($point['used']) }} <span class="text-sm text-slate-500">Kbps</span></p>
                    </div>
                    <div class="mt-2 flex items-center gap-3">
                        <div class="h-2.5 flex-1 rounded-full bg-slate-800 overflow-hidden">
                            <div class="h-full rounded-full {{ $width >= 100 ? 'bg-red-400' : 'bg-sky-400' }}" style="width: {{ $width }}%"></div>
                        </div>
                        <p class="w-28 shrink-0 text-right font-mono text-sm text-emerald-300">
                            @if ($point['allocated'] > 0)
                                {{ number_format($point['allocated']) }} Kbps
                            @else
                                <span class="text-slate-500">No queue</span>
                            @endif
                        </p>
                        <p class="w-12 shrink-0 text-right font-mono text-sm text-slate-300">
                            {{ $point['percent'] === null ? '—' : $point['percent'].'%' }}
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
