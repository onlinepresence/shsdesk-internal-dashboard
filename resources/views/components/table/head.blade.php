@props([])

<thead {{ $attributes->merge(['class' => 'bg-slate-50 dark:bg-white/5']) }}>
    {{ $slot }}
</thead>
