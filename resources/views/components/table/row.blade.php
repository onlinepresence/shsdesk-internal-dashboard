@props(['hover' => true])

<tr {{ $attributes->merge(['class' => $hover ? 'hover:bg-slate-50 dark:hover:bg-white/5' : '']) }}>
    {{ $slot }}
</tr>
