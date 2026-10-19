@extends('layouts.app')

@section('title', 'Live use')

@push('head')
    <script>
        setInterval(function () {
            location.reload();
        }, 5000);
    </script>
@endpush

@section('content')
<div class="min-h-screen">
    <header class="border-b border-slate-800 bg-slate-900/50">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <p class="text-xs font-medium uppercase tracking-wider text-emerald-400">Live report</p>
            <h1 class="text-xl sm:text-2xl font-semibold text-white">PCs using bandwidth</h1>
            @if ($live && $updatedAt)
                <p class="text-sm text-slate-400 mt-1">Updated {{ $updatedAt->diffForHumans() }}</p>
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
                <h2 class="text-lg font-semibold text-white">Using bandwidth now</h2>
                <p class="text-sm text-slate-400 mt-1">{{ count($pcs) }} {{ count($pcs) === 1 ? 'PC' : 'PCs' }}</p>
            </div>

            @if (! $live)
                <p class="px-5 py-10 text-sm text-slate-400">
                    The live report is not ready.
                    Start <code class="text-slate-200">php artisan bandwidth:run</code> to see the PCs that are using bandwidth.
                </p>
            @elseif ($pcs === [])
                <p class="px-5 py-10 text-sm text-slate-400">No PC is using bandwidth right now.</p>
            @else
                <ul class="divide-y divide-slate-800">
                    @foreach ($pcs as $pc)
                        <li class="px-5 py-4 flex items-baseline justify-between gap-4">
                            <div>
                                <p class="text-white font-medium">{{ $pc['name'] }}</p>
                                @if ($pc['ip'] !== '' && $pc['ip'] !== $pc['name'])
                                    <p class="text-xs font-mono text-slate-500 mt-0.5">{{ $pc['ip'] }}</p>
                                @endif
                            </div>
                            <p class="font-mono text-lg text-sky-300">{{ number_format($pc['used']) }} <span class="text-sm text-slate-500">Kbps</span></p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </main>
</div>
@endsection
