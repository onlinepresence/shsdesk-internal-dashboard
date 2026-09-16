<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('deployments', 'pages.deployments.index')
        ->name('deployments.index');

    Volt::route('deployments/create', 'pages.deployments.create')
        ->name('deployments.create');

    Volt::route('deployments/{deployment:uuid}', 'pages.deployments.show')
        ->name('deployments.show');
});

Route::get('ui', function () {
    abort_unless(app()->isLocal(), 404);

    return view('ui-gallery');
})->middleware(['auth'])->name('ui-gallery');

require __DIR__.'/auth.php';
