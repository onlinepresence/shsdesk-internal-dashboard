<?php

it('sends guests to login and staff to the dashboard', function () {
    $this->get('/')->assertRedirect(route('login'));

    $this->actingAs(owner());

    $this->get('/')->assertRedirect(route('dashboard'));
});
