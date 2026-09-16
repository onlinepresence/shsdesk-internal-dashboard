@props([])

<section {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-deep']) }}>
    @if (! empty($title) || ! empty($actions))
        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 dark:border-white/10 sm:px-6">
            @if (! empty($title))
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
            @endif
            @if (! empty($actions))
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endif
        </header>
    @endif

    <div class="px-4 py-5 text-slate-700 dark:text-slate-200 sm:p-6">
        {{ $slot }}
    </div>

    @if (! empty($footer))
        <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 sm:px-6">
            {{ $footer }}
        </footer>
    @endif
</section>
