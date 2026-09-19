@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-slate-300 shadow-sm focus:border-brand focus:ring-brand dark:border-white/15 dark:bg-ink dark:text-slate-100 dark:focus:border-accent dark:focus:ring-accent']) }}>
    {{ $slot }}
</select>
