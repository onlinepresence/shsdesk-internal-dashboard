<?php

use App\Models\Deployment;
use App\Models\Invoice;
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

    Volt::route('licences', 'pages.licences.index')
        ->name('licences.index');

    Volt::route('deployments/{deployment:uuid}/licence', 'pages.licences.edit')
        ->name('licences.edit');

    Route::get('deployments/{deployment:uuid}/licence/invoices/{invoice}', function (Deployment $deployment, Invoice $invoice) {
        abort_unless($invoice->deployment_id === $deployment->id, 404);

        return view('licences.invoice', [
            'deployment' => $deployment,
            'pricing' => $invoice->pricing ?? [],
            'contact' => $invoice->contact ?? ['college_name' => $deployment->school_name],
            'invoiceNo' => $invoice->invoice_no ?? 'FE-DRAFT',
            'issuedAt' => $invoice->created_at,
        ]);
    })->name('licences.invoices.show');
});

Route::middleware(['auth', 'verified', 'can:manage-catalogue'])->group(function () {
    Volt::route('catalogue', 'pages.catalogue.index')
        ->name('catalogue.index');
});

Route::get('ui', function () {
    abort_unless(app()->isLocal(), 404);

    return view('ui-gallery');
})->middleware(['auth'])->name('ui-gallery');

require __DIR__.'/auth.php';
