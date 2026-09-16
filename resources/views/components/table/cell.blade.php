@props([])

<td {{ $attributes->merge(['class' => 'whitespace-nowrap px-3 py-4 text-sm text-slate-500 first:pl-4 first:font-medium first:text-slate-900 last:pr-4 dark:text-slate-300 dark:first:text-white sm:first:pl-6 sm:last:pr-6']) }}>
    {{ $slot }}
</td>
