<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">Live allocation breakdown</h2>
        <p class="text-xs text-slate-500 mt-1">
            Pool <span class="font-mono text-emerald-400">{{ $allocation['pool_display'] }}</span>
            ({{ number_format($allocation['pool_kbps']) }} Kbps)
            — shared only among online, active users.
            Idle/offline devices get 0 Kbps; low-usage users get a minimized score.
        </p>
        @if (!empty($allocation['activity']))
            <p class="text-xs text-slate-500 mt-1">
                Activity:
                <span class="text-emerald-400">{{ $allocation['activity']['active'] ?? 0 }} active</span>,
                <span class="text-amber-400">{{ $allocation['activity']['low_usage'] ?? 0 }} low usage</span>,
                <span class="text-slate-400">{{ $allocation['activity']['idle'] ?? 0 }} idle</span>,
                <span class="text-red-400">{{ $allocation['activity']['offline'] ?? 0 }} offline</span>
            </p>
        @endif
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
                        <th class="px-5 py-3 font-medium">Activity</th>
                        <th class="px-5 py-3 font-medium">Task</th>
                        <th class="px-5 py-3 font-medium text-right">Base</th>
                        <th class="px-5 py-3 font-medium text-right">Effective</th>
                        <th class="px-5 py-3 font-medium text-right">Share</th>
                        <th class="px-5 py-3 font-medium text-right">Kbps</th>
                        <th class="px-5 py-3 font-medium text-right">Queue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($allocation['users'] as $row)
                        <tr @class([
                            'hover:bg-slate-800/50 transition-colors',
                            'opacity-50' => $row->share_kbps <= 0,
                        ])>
                            <td class="px-5 py-3 text-white font-medium">
                                {{ $row->user->name }}
                                <span class="block text-xs text-slate-500 font-mono">{{ $row->user->ip_address }}</span>
                            </td>
                            <td class="px-5 py-3">
                                @php
                                    $activityClass = match ($row->activity_status) {
                                        'active' => 'bg-emerald-500/20 text-emerald-300',
                                        'low_usage' => 'bg-amber-500/20 text-amber-300',
                                        'idle' => 'bg-slate-700 text-slate-400',
                                        'offline' => 'bg-red-500/20 text-red-300',
                                        default => 'bg-slate-700 text-slate-400',
                                    };
                                @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $activityClass }}">
                                    {{ $row->activity_label }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-400 text-xs">{{ $row->task_type ?? '—' }}</td>
                            <td class="px-5 py-3 text-right font-mono text-slate-500">{{ $row->base_score > 0 ? $row->base_score : '—' }}</td>
                            <td class="px-5 py-3 text-right font-mono text-amber-400">{{ $row->score > 0 ? $row->score : '—' }}</td>
                            <td class="px-5 py-3 text-right text-slate-400">
                                {{ $row->share_percent > 0 ? $row->share_percent.'%' : '—' }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono font-semibold {{ $row->share_kbps > 0 ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ number_format($row->share_kbps) }}
                            </td>
                            <td class="px-5 py-3 text-right font-mono text-slate-400 text-xs">{{ $row->bandwidth }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-800 bg-slate-800/30">
                        <td colspan="4" class="px-5 py-3 text-slate-400">
                            Effective score total: {{ $allocation['total_score'] }}
                        </td>
                        <td colspan="2" class="px-5 py-3 text-right text-slate-400">100%</td>
                        <td class="px-5 py-3 text-right font-mono text-emerald-400 font-semibold">{{ number_format($allocation['pool_kbps']) }}</td>
                        <td class="px-5 py-3 text-right font-mono text-emerald-400 font-medium">{{ $allocation['pool_label'] }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
</div>
