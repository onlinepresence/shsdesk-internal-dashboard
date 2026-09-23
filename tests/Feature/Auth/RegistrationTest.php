<?php

namespace Tests\Feature\Auth;

use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('registration screen is disabled', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('registration component denies direct calls', function () {
    expect(fn () => Volt::test('pages.auth.register'))->toThrow(NotFoundHttpException::class);

    $this->assertGuest();
});

test('password reset stays available for existing staff', function () {
    $response = $this->get('/forgot-password');

    $response->assertOk()->assertSeeVolt('pages.auth.forgot-password');
});
