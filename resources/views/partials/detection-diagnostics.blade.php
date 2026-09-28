<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-hidden" data-device-register>
    <div class="px-5 py-4 border-b border-slate-800">
        <h2 class="text-lg font-semibold text-white">Devices on the LAN</h2>
        <p class="text-sm text-slate-400 mt-1">
            Only devices seen on the local network are listed. Last bandwidth use shows Now while traffic is moving, otherwise the last time that device used bandwidth.
        </p>
    </div>

    @php
        $devices = $detection['devices'] ?? [];
    @endphp

    <div class="overflow-x-auto">
        @if (! array_key_exists('devices', $detection))
            <div class="px-5 py-8 text-sm text-amber-200">
                The current report was saved before device addresses were tracked.
                Restart <code class="text-amber-100">php artisan bandwidth:run</code> so the next cycle lists them.
            </div>
        @elseif ($devices === [])
            <div class="px-5 py-8 text-sm text-slate-400">
                No devices are on the LAN right now. A phone or computer has to be connected to the local network before its address shows up here.
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-800">
                        <th class="px-5 py-3 font-medium">Address</th>
                        <th class="px-5 py-3 font-medium">Hardware address</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Last bandwidth use</th>
                        <th class="px-5 py-3 font-medium">Name</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($devices as $device)
                        <tr class="hover:bg-slate-800/50 align-top">
                            <td class="px-5 py-3">
                                <span class="font-mono text-white">{{ $device['ip'] }}</span>
                                @if (!empty($device['hostname']))
                                    <span class="block text-xs text-slate-500 mt-0.5">{{ $device['hostname'] }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 font-mono text-slate-300">
                                {{ $device['mac'] ?: '—' }}
                            </td>
                            <td class="px-5 py-3">
                                @if ($device['connected'] ?? true)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-300">On the LAN</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-300">Left the LAN</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-slate-200">
                                @if ($device['using_bandwidth'] ?? false)
                                    Now
                                @elseif (! empty($device['last_bandwidth_at']))
                                    {{ \Carbon\Carbon::parse($device['last_bandwidth_at'])->timezone(config('app.timezone'))->format('M j, H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if ($device['registered_name'])
                                    <a href="{{ route('users.edit', $device['user_id']) }}" class="text-emerald-300 hover:underline font-medium">
                                        {{ $device['registered_name'] }}
                                    </a>
                                @else
                                    <form method="POST" action="{{ route('devices.register') }}" class="flex flex-col sm:flex-row sm:items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="ip_address" value="{{ $device['ip'] }}">
                                        <input type="hidden" name="mac_address" value="{{ $device['mac'] }}">
                                        <input
                                            type="text"
                                            name="name"
                                            value="{{ old('ip_address') === $device['ip'] ? old('name') : ($device['hostname'] ?? '') }}"
                                            placeholder="Name this device"
                                            required
                                            class="w-full sm:w-40 rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500"
                                        >
                                        <select
                                            name="role"
                                            class="rounded-lg border border-slate-700 bg-slate-800 px-2 py-1.5 text-sm text-white focus:outline-none focus:border-emerald-500"
                                        >
                                            @foreach (\App\Models\Role::query()->orderByDesc('weight')->get() as $role)
                                                <option value="{{ $role->slug }}" @selected(old('ip_address') === $device['ip'] ? old('role') === $role->slug : $role->is_default)>{{ $role->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-medium">
                                            Register
                                        </button>
                                    </form>
                                    @if (old('ip_address') === $device['ip'])
                                        @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                                        @error('ip_address')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                                        @error('mac_address')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
