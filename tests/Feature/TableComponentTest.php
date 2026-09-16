<?php

test('table renders rows when body slot is provided', function () {
    $view = $this->blade(
        '<x-table><x-slot name="head"><th>Name</th></x-slot><x-slot name="body"><tr><td>Acme</td></tr></x-slot></x-table>'
    );

    $view->assertSee('Acme');
});

test('table renders empty slot without body slot', function () {
    $view = $this->blade(
        '<x-table><x-slot name="head"><th>Name</th></x-slot><x-slot name="empty">No rows yet</x-slot></x-table>'
    );

    $view->assertSee('No rows yet');
});
