@props(['label', 'value', 'hint' => null])

<div {{ $attributes->merge(['class' => 'rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-deep sm:p-5']) }}>
    <p class="truncate text-sm font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
    {{ $slot }}
</div>
