<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">Live allocation breakdown</h2>
        <p class="text-xs text-slate-500 mt-1">
            Pool <span class="font-mono text-emerald-400">{{ $allocation['pool_display'] }}</span>
            ({{ $allocation['pool_kbps'] }} Kbps total)
            — shared only among <span class="text-emerald-400">{{ $allocation['online_count'] }} online</span> device(s).
            Offline devices get 0 Kbps.
        </p>
    </div>
    <div class="overflow-x-auto">
        @if ($allocation['users']->isEmpty())
            <div class="px-5 py-10 text-center text-slate-500 text-sm">
                @if ($allocation['pool_kbps'] <= 0)
                    No bandwidth pool measured. Traffic on the monitor interface is 0 Kbps right now.
                @else
                    No monitored users with IP addresses. Add users and match their IPs to router ARP/connections.
                @endif
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-800">
                        <th class="px-5 py-3 font-medium">User</th>
                        <th class="px-5 py-3 font-medium">Device</th>
                        <th class="px-5 py-3 font-medium">Role</th>
                        <th class="px-5 py-3 font-medium text-right">Score</th>
                        <th class="px-5 py-3 font-medium text-right">Share</th>
                        <th class="px-5 py-3 font-medium text-right">Kbps</th>
                        <th class="px-5 py-3 font-medium text-right">Queue limit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($allocation['users'] as $row)
                        <tr @class([
                            'hover:bg-slate-800/50 transition-colors',
                            'opacity-50' => ! $row->is_online || $row->share_kbps <= 0,
                        ])>
                            <td class="px-5 py-3 text-white font-medium">{{ $row->user->name }}</td>
                            <td class="px-5 py-3">
                                @if ($row->is_online)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300">Online</span>
                                    <span class="block text-xs text-slate-500 mt-0.5 font-mono">{{ $row->user->ip_address }}</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-400">Offline</span>
                                    <span class="block text-xs text-slate-500 mt-0.5 font-mono">{{ $row->user->ip_address }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @include('partials.role-badge', ['role' => $row->user->role])
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $row->score > 0 ? $row->score : '—' }}</td>
                            <td class="px-5 py-3 text-right text-slate-400">
                                {{ $row->is_online && $row->share_percent > 0 ? $row->share_percent.'%' : '—' }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono font-semibold {{ $row->share_kbps > 0 ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ number_format($row->share_kbps) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-slate-400">{{ $row->bandwidth }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-800 bg-slate-800/30">
                        <td colspan="4" class="px-5 py-3 text-slate-400">
                            Online score total: {{ $allocation['total_score'] }}
                            · {{ $allocation['offline_count'] }} offline
                        </td>
                        <td class="px-5 py-3 text-right text-slate-400">100%</td>
                        <td class="px-5 py-3 text-right font-mono text-emerald-400 font-semibold">{{ number_format($allocation['pool_kbps']) }}</td>
                        <td class="px-5 py-3 text-right font-mono text-emerald-400 font-medium">{{ $allocation['pool_label'] }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
</div>
