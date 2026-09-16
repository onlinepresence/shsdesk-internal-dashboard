@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-300 dark:border-white/15 dark:bg-ink dark:text-slate-100 focus:border-brand dark:focus:border-accent focus:ring-brand dark:focus:ring-accent rounded-md shadow-sm']) }}>
