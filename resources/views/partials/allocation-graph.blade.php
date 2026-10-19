@php
    $live = ($reportState ?? '') === 'live';
    $points = [];

    if ($live) {
        $allocationRows = collect($allocation['users'] ?? []);
        $byUser = $allocationRows->keyBy(fn ($row) => $row->user->id);
        $seenUsers = [];

        foreach ($detection['devices'] ?? [] as $device) {
            if (! ($device['using_bandwidth'] ?? false)) {
                continue;
            }

            $row = isset($device['user_id']) ? $byUser->get($device['user_id']) : null;
            $used = (int) ($device['rate_kbps'] ?? 0);
            if ($used <= 0 && $row) {
                $used = (int) ($row->throughput_total_kbps ?? 0);
            }

            if ($row) {
                $seenUsers[$row->user->id] = true;
            }

            $points[] = [
                'label' => $device['registered_name'] ?: ($device['hostname'] ?: $device['ip']),
                'address' => $device['ip'] ?? '',
                'used' => $used,
                'allocated' => $row ? (int) $row->share_kbps : 0,
                'share' => $row ? (float) $row->share_percent : 0.0,
            ];
        }

        foreach ($allocationRows as $row) {
            if (isset($seenUsers[$row->user->id])) {
                continue;
            }

            $used = (int) ($row->throughput_total_kbps ?? 0);
            if ($used <= 0 && ! ($row->using_bandwidth ?? false)) {
                continue;
            }

            $points[] = [
                'label' => $row->user->name,
                'address' => $row->user->ip_address,
                'used' => $used,
                'allocated' => (int) ($row->share_kbps ?? 0),
                'share' => (float) ($row->share_percent ?? 0),
            ];
        }
    }

    usort($points, function (array $a, array $b) {
        return [$b['share'], $b['allocated'], $a['label']] <=> [$a['share'], $a['allocated'], $b['label']];
    });

    $totalUsed = array_sum(array_column($points, 'used'));
    $widest = $points === [] ? 0 : max(array_column($points, 'share'));
@endphp

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-white">How data is used</h2>
            <p class="text-sm text-slate-400 mt-1">
                @if ($live && $reportUpdatedAt)
                    Live numbers from the report {{ $reportUpdatedAt->diffForHumans() }}.
                @elseif ($live)
                    Live numbers from the latest report.
                @else
                    Waiting for a live report.
                @endif
            </p>
        </div>
        <div>
            <p class="text-xs text-slate-500">In use now</p>
            <p class="text-2xl font-semibold font-mono text-sky-300">{{ $live ? number_format($totalUsed) : '—' }} <span class="text-sm font-normal text-slate-500">Kbps</span></p>
        </div>
    </div>

    @if (! $live)
        <p class="px-5 py-10 text-sm text-slate-400">
            This graph shows only the latest live report.
            Start <code class="text-slate-200">php artisan bandwidth:run</code> so the numbers come from the router.
        </p>
    @elseif ($points === [])
        <p class="px-5 py-10 text-sm text-slate-400">No device is moving data in this report.</p>
    @else
        <ul class="divide-y divide-slate-800">
            @foreach ($points as $point)
                @php
                    $width = $widest > 0 ? (int) round(($point['share'] / $widest) * 100) : 0;
                @endphp
                <li class="px-5 py-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-white font-medium">
                            {{ $point['label'] }}
                            @if ($point['address'] !== '' && $point['address'] !== $point['label'])
                                <span class="block text-xs font-normal font-mono text-slate-500">{{ $point['address'] }}</span>
                            @endif
                        </p>
                        <p class="text-right">
                            <span class="font-mono text-lg text-sky-300">{{ (int) round($point['share']) }}%</span>
                            <span class="block text-xs font-mono text-slate-500">using {{ number_format($point['used']) }} Kbps</span>
                        </p>
                    </div>
                    <div class="mt-2 flex items-center gap-3">
                        <div class="h-2.5 flex-1 rounded-full bg-slate-800 overflow-hidden">
                            <div class="h-full rounded-full bg-sky-400" style="width: {{ $width }}%"></div>
                        </div>
                        <p class="w-32 shrink-0 text-right font-mono text-sm text-emerald-300">
                            @if ($point['allocated'] > 0)
                                queue {{ number_format($point['allocated']) }}
                            @else
                                <span class="text-slate-500">no queue</span>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
