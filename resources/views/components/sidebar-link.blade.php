@props(['label', 'route', 'icon' => 'fallback', 'params' => [], 'active' => false])

{{-- Sidebar nav link. Icons are Lucide components keyed by name; unknown keys fall back to a circle so new config entries render without markup changes. --}}
@php
$iconComponents = [
    'dashboard' => 'lucide-layout-dashboard',
    'user' => 'lucide-user',
    'server' => 'lucide-server',
    'key' => 'lucide-key-round',
    'package' => 'lucide-package',
    'inbox' => 'lucide-inbox',
    'ticket' => 'lucide-ticket',
    'receipt' => 'lucide-receipt',
    'sliders' => 'lucide-sliders-horizontal',
    'users' => 'lucide-users',
];

$iconComponent = $iconComponents[$icon] ?? 'lucide-circle';

$classes = ($active ?? false)
    ? 'relative flex items-center gap-3 rounded-md bg-white/10 px-3 py-2 text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-accent transition duration-150 ease-in-out'
    : 'relative flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-300/80 hover:bg-white/5 hover:text-white focus:outline-none focus:ring-2 focus:ring-accent transition duration-150 ease-in-out';
@endphp

<a href="{{ route($route, $params) }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($active ?? false)
        <span class="absolute inset-y-0 left-0 w-1 rounded-r bg-accent" aria-hidden="true"></span>
    @endif
    <x-dynamic-component :component="$iconComponent" class="h-5 w-5 shrink-0" aria-hidden="true" />
    <span class="truncate">{{ $label }}</span>
</a>
