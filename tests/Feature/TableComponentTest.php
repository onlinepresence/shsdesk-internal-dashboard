<?php

test('table composes headings, rows, and cells with per-row hover', function () {
    $view = $this->blade(
        '<x-table><x-table.head><x-table.row :hover="false"><x-table.heading>Name</x-table.heading></x-table.row></x-table.head><x-table.body><x-table.row><x-table.cell>Acme</x-table.cell></x-table.row></x-table.body></x-table>'
    );

    $view->assertSee('Acme');
    $view->assertSee('hover:bg-slate-50', false);
});

test('table header row opts out of hover', function () {
    $view = $this->blade(
        '<x-table><x-table.head><x-table.row :hover="false"><x-table.heading>Name</x-table.heading></x-table.row></x-table.head></x-table>'
    );

    $view->assertDontSee('hover:bg-slate-50');
});

test('table empty sub-component renders an explicit state', function () {
    $view = $this->blade(
        '<x-table><x-table.head><x-table.row :hover="false"><x-table.heading>Name</x-table.heading></x-table.row></x-table.head><x-table.empty>No rows yet</x-table.empty></x-table>'
    );

    $view->assertSee('No rows yet');
});

test('table renders a loading overlay for fetch waits', function () {
    $view = $this->blade(
        '<x-table><x-table.head><x-table.row :hover="false"><x-table.heading>Name</x-table.heading></x-table.row></x-table.head></x-table>'
    );

    $view->assertSee('wire:loading', false);
    $view->assertSee('Loading…');
});
