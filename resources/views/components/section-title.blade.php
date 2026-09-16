@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'flex items-start justify-between gap-4']) }}>
    <div class="min-w-0">
        <h2 class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
        @endif
    </div>
    @if (! $slot->isEmpty())
        <div class="flex shrink-0 items-center gap-2">{{ $slot }}</div>
    @endif
</div>
