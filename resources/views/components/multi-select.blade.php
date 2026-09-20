@props(['model' => null, 'options' => [], 'placeholder' => 'Select…', 'disabled' => false])

{{-- Designed multi-select. Binds to a Livewire array property by name
    through Alpine entangle, e.g. <x-multi-select model="scope"
    :options="['a' => 'Alpha']" />. Selects instantly, syncs to the
    server on the next Livewire request. --}}
<div
    x-data="{
        open: false,
        search: '',
        selected: $wire.entangle('{{ $model }}'),
        allOptions: @js($options),
        toggle(value) {
            if (this.selected.includes(value)) {
                this.selected = this.selected.filter((v) => v !== value);
            } else {
                this.selected.push(value);
            }
        },
        clear() { this.selected = []; },
        get filtered() {
            const term = this.search.trim().toLowerCase();
            return Object.entries(this.allOptions).filter(([value, label]) =>
                term === '' || label.toLowerCase().includes(term) || value.toLowerCase().includes(term)
            );
        },
        get selectedLabels() {
            return this.selected.map((value) => this.allOptions[value] ?? value);
        },
    }"
    class="relative"
    @keydown.escape="open = false"
>
    <button
        type="button"
        @click="open = ! open"
        @disabled($disabled)
        class="flex min-h-10 w-full items-center gap-2 rounded-md border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-brand focus:ring-brand disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/15 dark:bg-ink dark:text-slate-100 dark:focus:border-accent dark:focus:ring-accent"
    >
        <span class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
            <span x-show="selected.length === 0" class="text-slate-400 dark:text-slate-500">{{ $placeholder }}</span>
            <template x-for="(label, index) in selectedLabels.slice(0, 2)" :key="index">
                <span class="inline-flex max-w-40 items-center truncate rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand dark:bg-brand/40 dark:text-white">
                    <span class="truncate" x-text="label"></span>
                </span>
            </template>
            <span x-show="selected.length > 2" class="text-xs text-slate-500 dark:text-slate-400" x-text="'+' + (selected.length - 2) + ' more'"></span>
        </span>
        <span x-show="selected.length > 0" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300" x-text="selected.length"></span>
        <x-lucide-chevron-down class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />
    </button>

    <div
        x-show="open"
        x-transition.opacity
        @click.outside="open = false"
        class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-white/10 dark:bg-deep"
    >
        <div class="sticky top-0 flex items-center gap-2 border-b border-slate-200 bg-white p-2 dark:border-white/10 dark:bg-deep">
            <x-lucide-search class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden="true" />
            <input x-model="search" type="text" placeholder="Search…" aria-label="Search options" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 focus:ring-0 dark:text-slate-100 dark:placeholder:text-slate-500" />
            <button type="button" @click="clear()" x-show="selected.length > 0" class="shrink-0 text-xs font-medium text-brand hover:text-deep dark:text-slate-200 dark:hover:text-white">Clear</button>
        </div>
        <ul class="p-1">
            <template x-for="[value, label] in filtered" :key="value">
                <li>
                    <button
                        type="button"
                        @click="toggle(value)"
                        class="flex w-full items-center gap-2.5 rounded px-2 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-white/10"
                    >
                        <span
                            class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                            :class="selected.includes(value)
                                ? 'border-brand bg-brand text-white dark:border-accent dark:bg-accent dark:text-deep'
                                : 'border-slate-300 bg-white dark:border-white/25 dark:bg-transparent'"
                        >
                            <x-lucide-check x-show="selected.includes(value)" class="h-3 w-3" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-700 dark:text-slate-200" x-text="label"></span>
                            <span class="block truncate font-mono text-xs text-slate-400 dark:text-slate-500" x-text="value"></span>
                        </span>
                    </button>
                </li>
            </template>
            <li x-show="filtered.length === 0" class="px-2 py-3 text-sm text-slate-500 dark:text-slate-400">No matches.</li>
        </ul>
    </div>
</div>
