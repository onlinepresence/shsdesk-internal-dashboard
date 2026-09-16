<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-brand dark:bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-deep dark:hover:bg-accent focus:bg-deep dark:focus:bg-accent active:bg-ink dark:active:bg-accent focus:outline-none focus:ring-2 focus:ring-brand dark:focus:ring-accent focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-deep transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
