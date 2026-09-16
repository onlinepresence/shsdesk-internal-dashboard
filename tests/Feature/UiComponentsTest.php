<?php

test('button link renders an anchor with the primary variant by default', function () {
    $view = $this->blade(
        '<x-button-link href="/deployments/create">Register</x-button-link>'
    );

    $view->assertSee('<a href="/deployments/create"', false);
    $view->assertSee('bg-brand', false);
});

test('button link renders the requested variant', function () {
    $view = $this->blade(
        '<x-button-link href="/deployments" variant="tertiary">Cancel</x-button-link>'
    );

    $view->assertSee('text-mist', false);
});

test('select merges attributes and renders options', function () {
    $view = $this->blade(
        '<x-select wire:model.live="status" class="block w-full"><option value="all">All</option></x-select>'
    );

    $view->assertSee('<select', false);
    $view->assertSee('wire:model.live="status"', false);
    $view->assertSee('<option value="all">All</option>', false);
});
