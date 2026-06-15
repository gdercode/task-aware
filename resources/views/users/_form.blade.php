@php
    $user = $user ?? null;
    $isEdit = $user !== null;
@endphp

<div>
    <label for="name" class="block text-xs font-medium text-slate-400 mb-1">Name</label>
    <input type="text" name="name" id="name" value="{{ old('name', $user?->name) }}" required
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
    @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label for="email" class="block text-xs font-medium text-slate-400 mb-1">Email</label>
    <input type="email" name="email" id="email" value="{{ old('email', $user?->email) }}" required
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
    @error('email')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label for="password" class="block text-xs font-medium text-slate-400 mb-1">
        Password @if($isEdit)<span class="text-slate-500">(leave blank to keep current)</span>@endif
    </label>
    <input type="password" name="password" id="password" {{ $isEdit ? '' : 'required' }}
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
    @error('password')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label for="role" class="block text-xs font-medium text-slate-400 mb-1">Role</label>
    <select name="role" id="role" required
            class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
        @foreach ($roles as $role)
            <option value="{{ $role }}" @selected(old('role', $user?->role) === $role)>{{ ucfirst($role) }}</option>
        @endforeach
    </select>
    @error('role')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>

<div>
    <label for="ip_address" class="block text-xs font-medium text-slate-400 mb-1">IP Address</label>
    <input type="text" name="ip_address" id="ip_address" value="{{ old('ip_address', $user?->ip_address) }}"
           placeholder="192.168.1.20"
           class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
    <p class="text-xs text-slate-500 mt-1">Must match the IP MikroTik sees for this user on the network.</p>
    @error('ip_address')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
</div>
