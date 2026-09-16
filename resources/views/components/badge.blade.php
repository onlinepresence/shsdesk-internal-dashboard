@props(['tone' => 'muted'])

@php
$tones = [
    'active' => 'bg-brand/10 text-brand ring-brand/25 dark:bg-brand/40 dark:text-white dark:ring-white/25',
    'success' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-400/25',
    'warn' => 'bg-amber-50 text-amber-800 ring-amber-600/25 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/25',
    'danger' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/25',
    'muted' => 'bg-slate-100 text-slate-600 ring-slate-500/15 dark:bg-white/10 dark:text-slate-300 dark:ring-white/15',
];

$classes = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.($tones[$tone] ?? $tones['muted']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
