<form method="POST" action="{{ route('mikrotik-settings.update') }}" class="pt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
    @csrf
    <div>
        <label for="host" class="block text-xs font-medium text-slate-400 mb-1">Router address</label>
        <input
            type="text"
            name="host"
            id="host"
            value="{{ old('host', $mikrotikSettings->host) }}"
            required
            class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
            placeholder="192.168.88.1"
        >
        @error('host')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
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
        @error('port')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
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
                        {{ $iface['name'] }}@if ($iface['kbps'] !== null) — {{ $iface['kbps'] }} Kbps @endif
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
                placeholder="ether1"
            >
        @endif
        @error('monitor_interface')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>
    <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
        Save
    </button>
</form>
