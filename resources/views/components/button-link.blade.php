@props(['variant' => 'primary', 'href' => '#'])

@php
$variants = [
    'primary' => 'inline-flex items-center px-4 py-2 bg-brand dark:bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-deep dark:hover:bg-accent focus:bg-deep dark:focus:bg-accent active:bg-ink dark:active:bg-accent focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-deep transition ease-in-out duration-150',
    'secondary' => 'inline-flex items-center px-4 py-2 bg-white dark:bg-white/5 border border-slate-300 dark:border-white/15 rounded-md font-semibold text-xs text-slate-700 dark:text-slate-200 uppercase tracking-widest shadow-sm hover:bg-slate-50 dark:hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-deep disabled:opacity-25 transition ease-in-out duration-150',
    'danger' => 'inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150',
    'tertiary' => 'inline-flex items-center px-4 py-2 bg-transparent dark:bg-transparent border border-mist/25 dark:border-white/15 rounded-md font-semibold text-xs text-mist dark:text-slate-300 uppercase tracking-widest hover:bg-mist/10 hover:border-mist/40 hover:text-ink dark:hover:bg-white/10 dark:hover:border-white/25 dark:hover:text-white active:bg-mist/15 dark:active:bg-white/15 focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-deep disabled:opacity-25 transition ease-in-out duration-150',
];

$classes = $variants[$variant] ?? $variants['primary'];
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
