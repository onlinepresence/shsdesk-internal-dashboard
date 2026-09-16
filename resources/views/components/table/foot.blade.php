@props([])

<tfoot {{ $attributes->merge(['class' => 'border-t border-slate-200 bg-slate-50 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300']) }}>
    {{ $slot }}
</tfoot>
