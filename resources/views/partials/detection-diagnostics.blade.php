<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">How users are detected</h2>
        <p class="text-xs text-slate-500 mt-1">
            A user is <strong class="text-emerald-400">online</strong> when their IP in
            <a href="{{ route('users.index') }}" class="text-emerald-400 hover:underline">Users</a>
            exactly matches an IP seen on MikroTik via ARP, DHCP lease, firewall connection, or hotspot.
        </p>
    </div>

    <div class="px-5 py-4 grid grid-cols-2 sm:grid-cols-4 gap-3 border-b border-slate-800 bg-slate-800/20">
        @foreach (['arp' => 'ARP table', 'dhcp' => 'DHCP leases', 'connections' => 'Connections', 'hotspot' => 'Hotspot'] as $key => $label)
            @php $source = $detection['sources'][$key] ?? ['count' => 0, 'error' => null, 'ips' => []]; @endphp
            <div class="rounded-lg border border-slate-700 bg-slate-800/50 px-3 py-2">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="text-lg font-semibold text-white">{{ $source['count'] }} IP(s)</p>
                @if ($source['error'])
                    <p class="text-xs text-amber-400 mt-0.5" title="{{ $source['error'] }}">Unavailable</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-400 border-b border-slate-800">
                    <th class="px-5 py-3 font-medium">User</th>
                    <th class="px-5 py-3 font-medium">IP in database</th>
                    <th class="px-5 py-3 font-medium">Detected?</th>
                    <th class="px-5 py-3 font-medium">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @forelse ($detection['users'] as $row)
                    <tr class="hover:bg-slate-800/50">
                        <td class="px-5 py-3 text-white font-medium">{{ $row['name'] }}</td>
                        <td class="px-5 py-3 font-mono text-slate-300">{{ $row['configured_ip'] ?: '—' }}</td>
                        <td class="px-5 py-3">
                            @if ($row['detected'])
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300">Yes</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/20 text-red-300">No</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs text-slate-400">{{ $row['reason'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-slate-500">
                            No users with IP addresses.
                            <a href="{{ route('users.create') }}" class="text-emerald-400 hover:underline">Add users</a>
                            and set each device IP.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (count($detection['online_ips'] ?? []) > 0)
        <div class="px-5 py-4 border-t border-slate-800">
            <p class="text-xs text-slate-500 mb-2">All IPs currently seen on MikroTik:</p>
            <p class="text-xs font-mono text-slate-400 break-all">
                {{ implode(', ', array_keys($detection['online_ips'])) }}
            </p>
        </div>
    @else
        <div class="px-5 py-4 border-t border-slate-800 text-xs text-amber-300">
            MikroTik returned no client IPs. Check that devices are on the same LAN as the router and that
            firewall connection tracking is enabled (IP → Firewall → Connection Tracking).
        </div>
    @endif
</div>
