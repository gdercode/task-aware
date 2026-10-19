@extends('layouts.app')

@section('title', 'Live use')

@push('head')
    <script>
        setInterval(function () {
            location.reload();
        }, 3000);
    </script>
@endpush

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Live report</p>
            <h1 class="text-xl sm:text-2xl font-semibold text-white">PCs on the internet</h1>
            @if ($live && $updatedAt)
                <p class="text-sm text-slate-400 mt-1">Updated {{ $updatedAt->diffForHumans() }}. This page refreshes every 3 seconds.</p>
            @endif
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        <div class="rounded-xl border border-slate-800 bg-slate-900 px-5 py-5">
            <p class="text-sm text-slate-400">Total bandwidth</p>
            <p class="mt-1 text-4xl font-semibold font-mono text-white">
                {{ $totalKbps === null ? '—' : number_format($totalKbps) }}
                <span class="text-base font-normal text-slate-500">Kbps</span>
            </p>
        </div>

        <div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800">
                <h2 class="text-lg font-semibold text-white">Who is using it</h2>
                <p class="text-sm text-slate-400 mt-1">
                    {{ count($pcs) }} {{ count($pcs) === 1 ? 'PC' : 'PCs' }} on the line.
                    The percent is the share that priority allows. A lower priority cannot be given a larger share.
                </p>
            </div>

            @if (! $live)
                <p class="px-5 py-10 text-sm text-slate-400">
                    The live report is not ready.
                    Start <code class="text-slate-200">php artisan bandwidth:run</code> and a PC shows here as soon as it reaches the internet.
                </p>
            @elseif ($pcs === [])
                <p class="px-5 py-10 text-sm text-slate-400">No PC is accessing the internet right now.</p>
            @else
                @php
                    $plotLeft = 36;
                    $plotTop = 28;
                    $plotBottom = 64;
                    $width = 640;
                    $height = 240;
                    $plotHeight = $height - $plotTop - $plotBottom;
                    $count = max(1, count($pcs));
                    $slot = ($width - $plotLeft - 16) / $count;
                    $barWidth = min(48, $slot * 0.5);
                @endphp
                <div class="px-3 pt-4 overflow-x-auto">
                    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full min-w-[480px] h-60" role="img" aria-label="Each PC compared with the total bandwidth">
                        <line x1="{{ $plotLeft }}" y1="{{ $plotTop + $plotHeight }}" x2="{{ $width - 16 }}" y2="{{ $plotTop + $plotHeight }}" stroke="#334155" stroke-width="1"></line>
                        @foreach ($pcs as $index => $pc)
                            @php
                                $barHeight = ($pc['share'] / 100) * $plotHeight;
                                $x = $plotLeft + ($index * $slot) + (($slot - $barWidth) / 2);
                                $y = $plotTop + $plotHeight - $barHeight;
                                $center = $x + ($barWidth / 2);
                            @endphp
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ max($barHeight, 0) }}" rx="6" fill="#38bdf8"></rect>
                            <text x="{{ $center }}" y="{{ max(16, $y - 8) }}" text-anchor="middle" fill="#e2e8f0" font-size="13" font-family="ui-sans-serif, system-ui, sans-serif" font-weight="600">{{ $pc['share'] }}%</text>
                            <text x="{{ $center }}" y="{{ $plotTop + $plotHeight + 22 }}" text-anchor="middle" fill="#e2e8f0" font-size="13" font-family="ui-sans-serif, system-ui, sans-serif">{{ \Illuminate\Support\Str::limit($pc['name'], 14) }}</text>
                            <text x="{{ $center }}" y="{{ $plotTop + $plotHeight + 40 }}" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="ui-monospace, monospace">{{ $pc['share'] }}% share</text>
                        @endforeach
                    </svg>
                </div>
                <ul class="divide-y divide-slate-800 border-t border-slate-800">
                    @foreach ($pcs as $pc)
                        <li class="px-5 py-4">
                            <div class="flex items-baseline justify-between gap-4">
                                <p class="text-white font-medium">
                                    {{ $pc['name'] }}
                                    @if ($pc['ip'] !== '' && $pc['ip'] !== $pc['name'])
                                        <span class="block text-xs font-normal font-mono text-slate-500 mt-0.5">{{ $pc['ip'] }}</span>
                                    @endif
                                </p>
                                <p class="text-right">
                                    <span class="font-mono text-lg text-sky-300">{{ $pc['share'] }}%</span>
                                    <span class="block text-xs font-mono text-slate-500">using {{ number_format($pc['used']) }} Kbps</span>
                                    @if (($pc['allowed'] ?? null) !== null)
                                        <span class="block text-xs font-mono text-slate-500">allowed {{ number_format($pc['allowed']) }} Kbps</span>
                                    @endif
                                </p>
                            </div>
                            <div class="mt-2 flex items-center gap-3">
                                <div class="h-3 flex-1 rounded-full bg-slate-800 overflow-hidden" role="img" aria-label="{{ $pc['name'] }} is allowed {{ $pc['share'] }} percent">
                                    <div class="h-full rounded-full bg-sky-400" style="width: {{ max($pc['share'], 0) }}%"></div>
                                </div>
                                <p class="w-12 shrink-0 text-right font-mono text-sm text-slate-300">{{ $pc['share'] }}%</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </main>
</div>
@endsection
