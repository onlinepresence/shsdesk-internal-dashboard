@props(['title', 'message' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-2 px-4 py-10 text-center']) }}>
    <x-lucide-inbox class="h-10 w-10 text-slate-300 dark:text-slate-200" aria-hidden="true" />
    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
    @if ($message)
        <p class="max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $message }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-2 flex items-center gap-2">{{ $slot }}</div>
    @endif
</div>
