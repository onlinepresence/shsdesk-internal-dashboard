<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('ui', function () {
    abort_unless(app()->isLocal(), 404);

    return view('ui-gallery');
})->middleware(['auth'])->name('ui-gallery');

require __DIR__.'/auth.php';
