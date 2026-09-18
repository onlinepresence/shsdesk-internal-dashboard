@props(['loadingExcept' => null])

<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-deep']) }}>
    <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-60" @if($loadingExcept) wire:target.except="{{ $loadingExcept }}" @endif>
        <table class="min-w-full divide-y divide-slate-200 dark:divide-white/10">
            {{ $slot }}
        </table>
    </div>
    <div class="absolute inset-0 z-10 hidden items-center justify-center gap-2 bg-white/70 dark:bg-ink/70" wire:loading.flex @if($loadingExcept) wire:target.except="{{ $loadingExcept }}" @endif>
        <x-lucide-loader-circle class="h-5 w-5 animate-spin text-brand dark:text-slate-200" aria-hidden="true" />
        <span class="text-sm font-medium text-slate-600 dark:text-slate-300">Loading…</span>
    </div>
</div>
