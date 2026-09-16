<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white dark:bg-white/5 border border-slate-300 dark:border-white/15 rounded-md font-semibold text-xs text-slate-700 dark:text-slate-200 uppercase tracking-widest shadow-sm hover:bg-slate-50 dark:hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-deep disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
