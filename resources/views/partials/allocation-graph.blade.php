@php
    $rows = collect($allocation['users'] ?? []);
    $points = [];

    if ($rows->isNotEmpty()) {
        foreach ($rows as $row) {
            $points[] = [
                'label' => $row->user->name,
                'down' => (int) ($row->throughput_down_kbps ?? 0),
                'up' => (int) ($row->throughput_up_kbps ?? 0),
                'used' => (int) ($row->throughput_total_kbps ?? 0),
                'allocated' => (int) ($row->share_kbps ?? 0),
            ];
        }
    } else {
        foreach ($users ?? [] as $user) {
            $points[] = [
                'label' => $user->name,
                'down' => 0,
                'up' => 0,
                'used' => 0,
                'allocated' => 0,
            ];
        }
    }

    usort($points, function (array $a, array $b) {
        return [$b['used'], $a['label']] <=> [$a['used'], $b['label']];
    });

    $totalDown = array_sum(array_column($points, 'down'));
    $totalUp = array_sum(array_column($points, 'up'));
    $totalUsed = array_sum(array_column($points, 'used'));
    $peak = 0;
    foreach ($points as $point) {
        $peak = max($peak, $point['used'], $point['allocated']);
    }
    $axisMax = $peak > 0 ? (int) max($peak, ceil($peak / 4) * 4) : 100;

    $plotLeft = 52;
    $plotRight = 16;
    $plotTop = 16;
    $plotBottom = 78;
    $width = 720;
    $height = 300;
    $plotWidth = $width - $plotLeft - $plotRight;
    $plotHeight = $height - $plotTop - $plotBottom;
    $count = max(1, count($points));
    $slot = $plotWidth / $count;
    $barWidth = min(28, $slot * 0.28);
@endphp

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800 flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-white">How data is used</h2>
            <p class="text-sm text-slate-400 mt-1">Download and upload are moving now. The green column is the queue that device was given.</p>
        </div>
        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
            <div>
                <dt class="text-slate-500">Download</dt>
                <dd class="font-mono text-sky-300">{{ number_format($totalDown) }} Kbps</dd>
            </div>
            <div>
                <dt class="text-slate-500">Upload</dt>
                <dd class="font-mono text-violet-300">{{ number_format($totalUp) }} Kbps</dd>
            </div>
            <div>
                <dt class="text-slate-500">In use</dt>
                <dd class="font-mono text-white">{{ number_format($totalUsed) }} Kbps</dd>
            </div>
        </dl>
    </div>

    @if ($points === [])
        <p class="px-5 py-10 text-sm text-slate-500">No devices are registered, so there is no data use to show.</p>
    @else
        <div class="px-5 pt-3 flex flex-wrap gap-4 text-xs text-slate-400">
            <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-sky-400"></span> Download</span>
            <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-violet-400"></span> Upload</span>
            <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-400"></span> Allocated queue</span>
        </div>
        <div class="px-3 pt-2 pb-2 overflow-x-auto">
            <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[520px] h-72" role="img" aria-label="Data in use compared with each device queue">
                @foreach ([0, 0.25, 0.5, 0.75, 1] as $fraction)
                    @php
                        $y = $plotTop + $plotHeight - ($fraction * $plotHeight);
                        $tick = (int) round($axisMax * $fraction);
                    @endphp
                    <line x1="{{ $plotLeft }}" y1="{{ $y }}" x2="{{ $width - $plotRight }}" y2="{{ $y }}" stroke="#1e293b" stroke-width="1"></line>
                    <text x="{{ $plotLeft - 8 }}" y="{{ $y + 4 }}" text-anchor="end" fill="#64748b" font-size="12" font-family="ui-sans-serif, system-ui, sans-serif">{{ number_format($tick) }}</text>
                @endforeach

                @foreach ($points as $index => $point)
                    @php
                        $center = $plotLeft + ($index * $slot) + ($slot / 2);
                        $usedX = $center - $barWidth - 3;
                        $allocatedX = $center + 3;
                        $downHeight = $axisMax > 0 ? ($point['down'] / $axisMax) * $plotHeight : 0;
                        $upHeight = $axisMax > 0 ? ($point['up'] / $axisMax) * $plotHeight : 0;
                        $allocatedHeight = $axisMax > 0 ? ($point['allocated'] / $axisMax) * $plotHeight : 0;
                        $base = $plotTop + $plotHeight;
                    @endphp
                    <rect x="{{ $usedX }}" y="{{ $base - $downHeight }}" width="{{ $barWidth }}" height="{{ max($downHeight, 0) }}" rx="4" fill="#38bdf8"></rect>
                    <rect x="{{ $usedX }}" y="{{ $base - $downHeight - $upHeight }}" width="{{ $barWidth }}" height="{{ max($upHeight, 0) }}" rx="4" fill="#a78bfa"></rect>
                    <rect x="{{ $allocatedX }}" y="{{ $base - $allocatedHeight }}" width="{{ $barWidth }}" height="{{ max($allocatedHeight, 0) }}" rx="4" fill="#34d399"></rect>
                    <text x="{{ $center }}" y="{{ $base + 22 }}" text-anchor="middle" fill="#e2e8f0" font-size="13" font-family="ui-sans-serif, system-ui, sans-serif">{{ \Illuminate\Support\Str::limit($point['label'], 14) }}</text>
                    <text x="{{ $center }}" y="{{ $base + 40 }}" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="ui-monospace, monospace">{{ number_format($point['used']) }} Kbps</text>
                @endforeach
            </svg>
        </div>
        @if ($totalUsed === 0)
            <p class="px-5 pb-4 text-sm text-slate-500">No data is moving on these devices right now.</p>
        @endif
    @endif
</div>
