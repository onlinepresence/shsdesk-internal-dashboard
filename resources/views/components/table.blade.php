@props([])

@php
$hasRows = isset($body) && ! $body->isEmpty();
$hasEmptyState = isset($empty) && ! $empty->isEmpty();
@endphp

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-deep']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-white/10">
            <thead class="bg-slate-50 dark:bg-white/5 [&_th]:whitespace-nowrap [&_th]:px-3 [&_th]:py-3.5 [&_th]:text-left [&_th]:text-sm [&_th]:font-semibold [&_th]:text-slate-900 dark:[&_th]:text-slate-100 [&_th:first-child]:pl-4 sm:[&_th:first-child]:pl-6 [&_th:last-child]:pr-4 sm:[&_th:last-child]:pr-6">
                <tr>{{ $head ?? '' }}</tr>
            </thead>
            @if ($hasRows)
                <tbody class="divide-y divide-slate-200 dark:divide-white/10 [&_td]:whitespace-nowrap [&_td]:px-3 [&_td]:py-4 [&_td]:text-sm [&_td]:text-slate-500 dark:[&_td]:text-slate-300 [&_td:first-child]:pl-4 [&_td:first-child]:font-medium [&_td:first-child]:text-slate-900 dark:[&_td:first-child]:text-white sm:[&_td:first-child]:pl-6 [&_td:last-child]:pr-4 sm:[&_td:last-child]:pr-6 [&_tr]:hover:bg-slate-50 dark:[&_tr]:hover:bg-white/5">
                    {{ $body }}
                </tbody>
            @elseif ($hasEmptyState)
                <tbody>
                    <tr>
                        <td colspan="100" class="px-4 py-8 sm:px-6">{{ $empty }}</td>
                    </tr>
                </tbody>
            @endif
        </table>
    </div>
</div>
