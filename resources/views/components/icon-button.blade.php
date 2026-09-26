{{-- Icon-only table action. Renders an anchor when href is given, a button
otherwise. Tone carries meaning at a glance: neutral for lookups, brand
for edits, success for activations, danger for destructive calls.
Label feeds both aria-label and title. --}}
@props(['tone' => 'neutral', 'label' => '', 'href' => null])

@php
$tones = [
    'neutral' => 'text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:ring-brand dark:text-slate-500 dark:hover:bg-white/10 dark:hover:text-slate-300 dark:focus:ring-accent',
    'brand' => 'text-brand hover:bg-brand/10 hover:text-deep focus:ring-brand dark:text-slate-200 dark:hover:bg-white/10 dark:hover:text-white dark:focus:ring-accent',
    'success' => 'text-green-600 hover:bg-green-50 hover:text-green-700 focus:ring-green-600 dark:text-green-400 dark:hover:bg-green-500/10 dark:hover:text-green-300 dark:focus:ring-accent',
    'danger' => 'text-red-500 hover:bg-red-50 hover:text-red-700 focus:ring-red-500 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300 dark:focus:ring-red-400',
];

$classes = 'rounded-md p-1.5 focus:outline-none focus:ring-2 transition duration-150 ease-in-out '.($tones[$tone] ?? $tones['neutral']);
@endphp

@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="button" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
