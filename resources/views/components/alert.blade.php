@props(['tone' => 'info', 'title' => null, 'flash' => null, 'dismissible' => true])

@php
$tones = [
    'success' => 'border-green-200 bg-green-50 text-green-800 dark:border-green-400/20 dark:bg-green-500/10 dark:text-green-200',
    'danger' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-400/20 dark:bg-red-500/10 dark:text-red-200',
    'warn' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-400/20 dark:bg-amber-500/10 dark:text-amber-200',
    'info' => 'border-brand/20 bg-brand/5 text-brand dark:border-brand/40 dark:bg-brand/20 dark:text-slate-100',
];

$icons = [
    'success' => 'lucide-circle-check',
    'danger' => 'lucide-circle-alert',
    'warn' => 'lucide-triangle-alert',
    'info' => 'lucide-info',
];

$classes = 'flex items-start gap-3 rounded-lg border p-4 '.($tones[$tone] ?? $tones['info']);
$flashMessage = $flash ? session($flash) : null;
@endphp

@if ($flashMessage || ! $slot->isEmpty() || $title)
    <div x-data="{ show: true }" x-show="show" {{ $attributes->merge(['class' => $classes]) }} role="alert">
        <x-dynamic-component :component="$icons[$tone] ?? $icons['info']" class="h-5 w-5 shrink-0" aria-hidden="true" />
        <div class="min-w-0 flex-1">
            @if ($title)
                <p class="text-sm font-semibold">{{ $title }}</p>
            @endif
            <div class="text-sm">{{ $flashMessage ?? $slot }}</div>
            @if (! empty($actions))
                <div class="mt-3 flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
        @if ($dismissible)
            <button type="button" @click="show = false" aria-label="Dismiss" class="shrink-0 rounded-md p-1 opacity-70 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent">
                <x-lucide-x class="h-4 w-4" aria-hidden="true" />
            </button>
        @endif
    </div>
@endif
