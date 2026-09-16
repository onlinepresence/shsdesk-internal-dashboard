@props([])

<th {{ $attributes->merge(['class' => 'whitespace-nowrap px-3 py-3.5 text-left text-sm font-semibold text-slate-900 first:pl-4 last:pr-4 dark:text-slate-100 sm:first:pl-6 sm:last:pr-6']) }}>
    {{ $slot }}
</th>
