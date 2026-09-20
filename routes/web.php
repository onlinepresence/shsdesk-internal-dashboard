<?php

use App\Models\Deployment;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Setting;
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

    Volt::route('invoices', 'pages.invoices.index')
        ->name('invoices.index');

    Volt::route('deployments/{deployment:uuid}/licence', 'pages.licences.edit')
        ->name('licences.edit');

    Route::get('deployments/{deployment:uuid}/licence/invoices/{invoice}', function (Deployment $deployment, Invoice $invoice) {
        abort_unless($invoice->deployment_id === $deployment->id, 404);

        $issuer = $invoice->issuer ?? [];

        return view('licences.invoice', [
            'deployment' => $deployment,
            'pricing' => $invoice->pricing ?? [],
            'contact' => $invoice->contact ?? ['college_name' => $deployment->school_name],
            'invoiceNo' => $invoice->invoice_no ?? 'FE-DRAFT',
            'issuedAt' => $invoice->created_at,
            'docTitle' => $invoice->doc_title ?? Setting::get(Setting::INVOICE_DOC_TITLE, 'Proforma Invoice'),
            'issuer' => [
                'company' => $issuer['company'] ?? Setting::get(Setting::INVOICE_COMPANY, 'Matme Inc.'),
                'department' => $issuer['department'] ?? Setting::get(Setting::INVOICE_DEPARTMENT),
                'email' => $issuer['email'] ?? Setting::get(Setting::INVOICE_EMAIL),
                'phone' => $issuer['phone'] ?? Setting::get(Setting::INVOICE_PHONE),
                'location' => $issuer['location'] ?? Setting::get(Setting::INVOICE_LOCATION),
            ],
            'dueAt' => $invoice->due_at,
            'nextPaymentAt' => $invoice->next_payment_at,
        ]);
    })->name('licences.invoices.show');

    Route::get('deployments/{deployment:uuid}/licence/export', function (Deployment $deployment) {
        $licence = $deployment->latestLicence;

        abort_if($licence === null || $licence->isExpired(), 404);
        abort_if(empty(config('licence-export.signing_key')), 500, 'Licence signing key is not configured. Set LICENCE_SIGNING_KEY.');

        activity('licences')
            ->performedOn($licence)
            ->causedBy(auth()->user())
            ->log('licence.exported');

        return response(
            Licence::exportFileJson($licence),
            200,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"licence-{$deployment->uuid}.json\"",
            ]
        );
    })->name('licences.export');
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
