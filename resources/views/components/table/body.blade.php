@props([])

<tbody {{ $attributes->merge(['class' => 'divide-y divide-slate-200 dark:divide-white/10']) }}>
    {{ $slot }}
</tbody>
