@php $role = $role ?? 'unknown'; @endphp
<span @class([
    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize',
    'bg-purple-500/20 text-purple-300' => $role === 'dean',
    'bg-blue-500/20 text-blue-300' => $role === 'lecturer',
    'bg-slate-500/20 text-slate-300' => $role === 'student',
    'bg-slate-700 text-slate-400' => !in_array($role, ['dean', 'lecturer', 'student']),
])>{{ $role }}</span>
