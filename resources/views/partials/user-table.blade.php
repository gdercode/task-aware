@php
    $isActive = ($status ?? 'active') === 'active';
@endphp

<div @class([
    'rounded-xl border bg-slate-900 overflow-hidden',
    'border-emerald-900/50' => $isActive,
    'border-slate-800' => !$isActive,
])>
    <div class="px-5 py-4 border-b border-slate-800 flex items-center gap-3">
        @if ($isActive)
            <span class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
            </span>
        @else
            <span class="inline-flex rounded-full h-2.5 w-2.5 bg-slate-600"></span>
        @endif
        <h2 class="text-lg font-semibold text-white">{{ $title }}</h2>
    </div>
    <div class="overflow-x-auto">
        @if ($users->isEmpty())
            <div class="px-5 py-10 text-center text-slate-500 text-sm">{{ $emptyMessage }}</div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-800">
                        <th class="px-5 py-3 font-medium">User</th>
                        <th class="px-5 py-3 font-medium">Role</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        @if ($isActive)
                            <th class="px-5 py-3 font-medium text-right">Score</th>
                            <th class="px-5 py-3 font-medium text-right">Share</th>
                            <th class="px-5 py-3 font-medium text-right">Kbps</th>
                            <th class="px-5 py-3 font-medium text-right">Queue</th>
                        @endif
                        <th class="px-5 py-3 font-medium text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($users as $row)
                        <tr @class([
                            'hover:bg-slate-800/50 transition-colors',
                            'opacity-80' => !$isActive,
                        ])>
                            <td class="px-5 py-3 text-white font-medium">{{ $row->user->name }}</td>
                            <td class="px-5 py-3">
                                @include('partials.role-badge', ['role' => $row->user->role])
                            </td>
                            <td class="px-5 py-3">
                                @if ($isActive)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300">Getting bandwidth</span>
                                @elseif (isset($row->is_online) && ! $row->is_online)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-300">Device offline</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-400">
                                        {{ $row->offline_reason ?? 'Not using' }}
                                    </span>
                                @endif
                            </td>
                            @if ($isActive)
                                <td class="px-5 py-3 text-right font-mono text-amber-400 font-medium">
                                    {{ $row->score ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-400">
                                    {{ isset($row->share_percent) ? $row->share_percent.'%' : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <span class="font-mono font-semibold text-emerald-400">{{ number_format($row->share_kbps ?? 0) }}</span>
                                    <span class="text-xs text-slate-500"> Kbps</span>
                                    @if (!empty($row->bandwidth))
                                        <span class="block text-xs text-slate-500 mt-0.5 font-mono">{{ $row->bandwidth }}</span>
                                    @endif
                                    @if ($row->last_seen_at ?? null)
                                        <span class="block text-xs text-slate-500 mt-0.5">{{ $row->last_seen_at->format('M j, H:i') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-slate-400 text-xs">
                                    {{ $row->bandwidth ?? '0k/0k' }}
                                </td>
                            @endif
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('allocation-reports', $row->user) }}"
                                   class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors border border-slate-700">
                                    More details
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
