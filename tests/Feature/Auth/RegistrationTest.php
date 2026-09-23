<?php

namespace Tests\Feature\Auth;

use Livewire\Volt\Volt;

test('registration screen is disabled', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('registration component denies direct calls', function () {
    Volt::test('pages.auth.register')->assertNotFound();

    $this->assertGuest();
});

test('password reset stays available for existing staff', function () {
    $response = $this->get('/forgot-password');

    $response->assertOk()->assertSeeVolt('pages.auth.forgot-password');
});
