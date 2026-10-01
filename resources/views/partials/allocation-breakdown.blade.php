<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">How use affects allocation</h2>
        <p class="text-sm text-slate-300 mt-1">
            Users are moving
            <span class="font-mono text-sky-400">{{ number_format($allocation['total_throughput_kbps'] ?? 0) }} Kbps</span>.
            The engine has allocated
            <span class="font-mono text-emerald-400">{{ number_format($allocation['total_allocated_kbps'] ?? 0) }} Kbps</span>
            of a
            <span class="font-mono text-white">{{ $allocation['pool_display'] }}</span>
            pool.
            <span class="text-slate-400">
                {{ number_format($allocation['usage_of_allocated_percent'] ?? 0) }}% of that allocation is in use
                @if (($allocation['users_at_limit'] ?? 0) > 0)
                    · {{ $allocation['users_at_limit'] }} at the queue limit
                @endif
                @if (($allocation['headroom_kbps'] ?? 0) > 0)
                    · {{ number_format($allocation['headroom_kbps']) }} Kbps still free inside the queues
                @endif
            </span>
        </p>
        <p class="text-xs text-slate-500 mt-2">
            The queue comes from the role weight of each device that is using bandwidth now.
            When live use fills that queue, the limit is what holds the extra traffic back.
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
                        <th class="px-5 py-3 font-medium">Data in use</th>
                        <th class="px-5 py-3 font-medium text-right">Moving now</th>
                        <th class="px-5 py-3 font-medium text-right">Allocated</th>
                        <th class="px-5 py-3 font-medium">Used of allocation</th>
                        <th class="px-5 py-3 font-medium">Impact</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($allocation['users'] as $row)
                        @php
                            $used = (int) ($row->throughput_total_kbps ?? 0);
                            $allocated = (int) ($row->share_kbps ?? 0);
                            $usagePercent = (int) ($row->usage_percent ?? 0);
                            $barWidth = min(100, max(0, $usagePercent));
                            $impact = $row->impact ?? 'none';
                            $barClass = match ($impact) {
                                'capped' => 'bg-red-400',
                                'tight' => 'bg-amber-400',
                                'within' => 'bg-sky-400',
                                'spare' => 'bg-slate-500',
                                default => 'bg-slate-600',
                            };
                            $impactClass = match ($impact) {
                                'capped' => 'bg-red-500/20 text-red-300',
                                'tight' => 'bg-amber-500/20 text-amber-300',
                                'within' => 'bg-sky-500/20 text-sky-300',
                                'spare' => 'bg-slate-700 text-slate-300',
                                default => 'bg-slate-700 text-slate-400',
                            };
                        @endphp
                        <tr @class([
                            'hover:bg-slate-800/50 transition-colors',
                            'opacity-50' => $impact === 'none' && $used <= 0,
                        ])>
                            <td class="px-5 py-3 text-white font-medium">
                                {{ $row->user->name }}
                                <span class="block text-xs text-slate-500 font-mono">{{ $row->user->ip_address }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="text-white">{{ $row->task_label ?? ($row->task_type ?: 'General use') }}</span>
                                <span class="block text-xs text-slate-500 mt-0.5">{{ $row->task_effect ?? $row->activity_label }}</span>
                                <span class="block text-xs text-slate-600 mt-0.5">↓ {{ number_format($row->throughput_down_kbps ?? 0) }} · ↑ {{ number_format($row->throughput_up_kbps ?? 0) }} Kbps</span>
                            </td>
                            <td class="px-5 py-3 text-right font-mono font-semibold {{ $used > 0 ? 'text-sky-300' : 'text-slate-500' }}">
                                {{ number_format($used) }}
                                <span class="block text-xs font-normal text-slate-500">Kbps</span>
                            </td>
                            <td class="px-5 py-3 text-right font-mono font-semibold {{ $allocated > 0 ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ number_format($allocated) }}
                                <span class="block text-xs font-normal text-slate-500">
                                    {{ $row->share_percent > 0 ? $row->share_percent.'% share' : 'Kbps' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 min-w-[160px]">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full {{ $barClass }}" style="width: {{ $barWidth }}%"></div>
                                    </div>
                                    <span class="font-mono text-xs text-slate-300 w-12 text-right">{{ $allocated > 0 ? $usagePercent.'%' : '—' }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $impactClass }}" title="{{ $row->impact_detail ?? '' }}">
                                    {{ $row->impact_label ?? 'No allocation' }}
                                </span>
                                <span class="block text-xs text-slate-500 mt-1 max-w-[220px]">{{ $row->impact_detail ?? '' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
