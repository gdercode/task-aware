@php $role = $role ?? 'unknown'; @endphp
<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-slate-700 text-slate-200">
    {{ \App\Models\Role::labelFor($role) }}
</span>
