@props(['label', 'route', 'icon' => 'fallback', 'params' => [], 'active' => false])

{{-- Sidebar nav link. Icons are inline SVGs keyed by name; unknown keys fall back to a dot so new config entries render without markup changes. --}}
@php
$iconPaths = [
    'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />',
    'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />',
];

$paths = $iconPaths[$icon] ?? '<circle cx="12" cy="12" r="4" />';

$classes = ($active ?? false)
    ? 'relative flex items-center gap-3 rounded-md bg-white/10 px-3 py-2 text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-accent transition duration-150 ease-in-out'
    : 'relative flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-300/80 hover:bg-white/5 hover:text-white focus:outline-none focus:ring-2 focus:ring-accent transition duration-150 ease-in-out';
@endphp

<a href="{{ route($route, $params) }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($active ?? false)
        <span class="absolute inset-y-0 left-0 w-1 rounded-r bg-accent" aria-hidden="true"></span>
    @endif
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0" aria-hidden="true">{!! $paths !!}</svg>
    <span class="truncate">{{ $label }}</span>
</a>
