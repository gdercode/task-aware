<div class="rounded-xl border border-slate-800 bg-slate-900 p-5">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
        <div class="flex-1">
            <h2 class="text-lg font-semibold text-white">MikroTik Router</h2>
            <p class="text-sm text-slate-400 mt-1">Address used for bandwidth measurement and queue updates.</p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <div class="rounded-lg border border-slate-700 bg-slate-800/50 px-4 py-2">
                    <p class="text-xs text-slate-500">Current address</p>
                    <p class="font-mono text-sm text-white mt-0.5">{{ $mikrotikSettings->host }}:{{ $mikrotikSettings->port }}</p>
                </div>
                @if ($mikrotikConnected)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Connected
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-red-500/20 text-red-300 border border-red-500/30">
                        <span class="h-2 w-2 rounded-full bg-red-500"></span>
                        Unreachable
                    </span>
                @endif
            </div>

            @if ($mikrotikConnected && !empty($poolMeasure))
                <div class="mt-4 rounded-lg border border-slate-700 bg-slate-800/30 px-4 py-3 text-xs text-slate-400 space-y-1">
                    <p>
                        Pool source:
                        <span class="text-white font-medium">{{ $poolMeasure['source'] }}</span>
                        · Interface <span class="font-mono text-emerald-400">{{ $poolMeasure['interface'] }}</span>:
                        <span class="font-mono">{{ $poolMeasure['interface_kbps'] }} Kbps</span>
                        · Connections:
                        <span class="font-mono">{{ $poolMeasure['connection_kbps'] }} Kbps</span>
                    </p>
                </div>
            @endif

            @if ($mikrotikConnected && !empty($interfaceTraffic))
                <div class="mt-3">
                    <p class="text-xs text-slate-500 mb-2">Live traffic per interface (pick one with traffic for Monitor interface):</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($interfaceTraffic as $iface)
                            @php
                                $isSelected = ($mikrotikSettings->monitor_interface ?? '') === $iface['name'];
                                $kbps = $iface['kbps'];
                            @endphp
                            <span @class([
                                'inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-mono border',
                                'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' => $isSelected,
                                'border-slate-700 bg-slate-800 text-slate-400' => ! $isSelected,
                            ])>
                                {{ $iface['name'] }}:
                                @if ($kbps === null)
                                    ?
                                @else
                                    {{ $kbps }} Kbps
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('mikrotik-settings.update') }}" class="w-full lg:w-auto lg:min-w-[320px] space-y-3">
            @csrf
            <div>
                <label for="host" class="block text-xs font-medium text-slate-400 mb-1">Router IP / hostname</label>
                <input
                    type="text"
                    name="host"
                    id="host"
                    value="{{ old('host', $mikrotikSettings->host) }}"
                    required
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                    placeholder="192.168.88.1"
                >
                @error('host')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="port" class="block text-xs font-medium text-slate-400 mb-1">API port</label>
                <input
                    type="number"
                    name="port"
                    id="port"
                    value="{{ old('port', $mikrotikSettings->port) }}"
                    required
                    min="1"
                    max="65535"
                    class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                >
                @error('port')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="monitor_interface" class="block text-xs font-medium text-slate-400 mb-1">Monitor interface</label>
                @if ($mikrotikConnected && !empty($interfaceTraffic))
                    <select
                        name="monitor_interface"
                        id="monitor_interface"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                    >
                        @foreach ($interfaceTraffic as $iface)
                            <option value="{{ $iface['name'] }}" @selected(old('monitor_interface', $mikrotikSettings->monitor_interface) === $iface['name'])>
                                {{ $iface['name'] }}
                                @if ($iface['kbps'] !== null)
                                    — {{ $iface['kbps'] }} Kbps now
                                @endif
                            </option>
                        @endforeach
                    </select>
                @else
                    <input
                        type="text"
                        name="monitor_interface"
                        id="monitor_interface"
                        value="{{ old('monitor_interface', $mikrotikSettings->monitor_interface ?? 'ether1') }}"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        placeholder="ether1, wlan1, bridge"
                    >
                @endif
                <p class="text-xs text-slate-500 mt-1">Use LAN/WLAN (e.g. wlan1, bridge), not only WAN, if clients are on Wi‑Fi.</p>
                @error('monitor_interface')
                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="w-full px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
                Save MikroTik settings
            </button>
        </form>
    </div>
</div>
