<?php

use App\Models\Deployment;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);
});

test('band preset fills the cap and typing a cap reselects its band', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    $component = Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('student_band', '501-1000');

    expect($component->get('max_students'))->toBe(1000);

    $component->set('student_band', '3500+');

    expect($component->get('max_students'))->toBe(3501);

    $component->set('student_band', '')->set('max_students', 650);

    expect($component->get('max_students'))->toBe(650);
    expect($component->get('student_band'))->toBe('501-1000');
});

test('create invoice stores the quote and opens the printable invoice', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('modules', ['module_finance'])
        ->set('max_students', 500)
        ->call('createInvoice')
        ->assertHasNoErrors()
        ->assertDispatched('open-invoice');

    $invoice = Invoice::where('deployment_id', $deployment->id)->firstOrFail();

    expect($invoice->invoice_no)->toStartWith('FE-');
    expect((float) $invoice->pricing['upfront_total'])->toBe(7900.0);
    expect($invoice->pricing['granted_modules'])->toBe(['module_finance']);
    expect($invoice->contact['college_name'])->toBe($deployment->school_name);
});

test('stored invoice locks priced inputs on save', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'modules' => ['module_reports' => true],
        'hosting_mode' => 'managed',
    ]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('createInvoice')
        ->assertHasNoErrors();

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->set('modules', ['module_finance'])
        ->set('hosting_mode', 'none')
        ->set('training_admin', 5)
        ->set('notes', 'Still editable')
        ->call('save')
        ->assertHasNoErrors();

    $licence = Licence::where('deployment_id', $deployment->id)->firstOrFail();

    expect($licence->modules)->toBe(['module_reports' => true]);
    expect($licence->hosting_mode)->toBe('managed');
    expect($licence->training_admin)->toBe(0);
    expect($licence->notes)->toBe('Still editable');
});

test('stored invoice page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($deployment)->create([
        'invoice_no' => 'FE-20250101-0001',
        'pricing' => Licence::priceSnapshot(['module_finance' => true], 500),
        'contact' => ['college_name' => $deployment->school_name],
    ]);

    $this->get(route('licences.invoices.show', [$deployment->uuid, $invoice->id]))
        ->assertOk()
        ->assertSee('FE-20250101-0001')
        ->assertSee('Financial Portal');
});

test('invoice page hides other deployments invoices', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $other = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($other)->create();

    $this->get(route('licences.invoices.show', [$deployment->uuid, $invoice->id]))
        ->assertNotFound();
});
