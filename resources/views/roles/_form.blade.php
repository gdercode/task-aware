@php $role = $role ?? null; @endphp

<div>
    <label for="name" class="block text-xs font-medium text-slate-400 mb-1">Name</label>
    <input type="text" name="name" id="name" value="{{ old('name', $role?->name) }}" required
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
           placeholder="Guest, Staff, Dean">
    @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label for="percentage" class="block text-xs font-medium text-slate-400 mb-1">Percentage</label>
    <input type="number" name="percentage" id="percentage" value="{{ old('percentage', $role?->percentage ?? 10) }}" required min="0" max="100"
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
    <p class="text-xs text-slate-500 mt-1">This percentage is the device's weight. Devices using bandwidth now have their weights added together, and each one's share is its weight divided by that total.</p>
    @error('percentage')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<label class="flex items-center gap-2 text-sm text-slate-300">
    <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $role?->is_default))
           class="rounded border-slate-600 bg-slate-800 text-emerald-500 focus:ring-emerald-500">
    Use as the default role for new devices
</label>
