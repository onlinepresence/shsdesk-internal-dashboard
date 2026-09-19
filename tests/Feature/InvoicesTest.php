<?php

use App\Models\Deployment;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Setting;
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
        ->call('openInvoiceModal')
        ->call('createInvoice')
        ->assertHasNoErrors()
        ->assertDispatched('open-invoice');

    $invoice = Invoice::where('deployment_id', $deployment->id)->firstOrFail();

    expect($invoice->invoice_no)->toStartWith('FE-');
    expect((float) $invoice->pricing['upfront_total'])->toBe(7900.0);
    expect($invoice->pricing['granted_modules'])->toBe(['module_finance']);
    expect($invoice->contact['college_name'])->toBe($deployment->school_name);
    expect($invoice->due_at->toDateString())->toBe(today()->addDays(30)->toDateString());
    expect($invoice->next_payment_at->toDateString())->toBe(today()->addYear()->toDateString());
    expect($invoice->doc_title)->toBe('Proforma Invoice');
});

test('stored invoice locks priced inputs on save', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create([
        'modules' => ['module_reports' => true],
        'hosting_mode' => 'managed',
    ]);

    Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('openInvoiceModal')
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

test('invoices index lists every bill for authenticated users', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($deployment)->create(['invoice_no' => 'FE-20250101-0001']);

    $this->get(route('invoices.index'))
        ->assertOk()
        ->assertSee('FE-20250101-0001')
        ->assertSee($deployment->school_name);
});

test('invoices index filters to one deployment bills', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $other = Deployment::factory()->create();
    Invoice::factory()->for($deployment)->create(['invoice_no' => 'FE-20250101-0001']);
    Invoice::factory()->for($other)->create(['invoice_no' => 'FE-20250101-0002']);

    $this->get(route('invoices.index', ['deployment' => $deployment->uuid]))
        ->assertOk()
        ->assertSee('FE-20250101-0001')
        ->assertDontSee('FE-20250101-0002');

    Volt::test('pages.invoices.index')
        ->set('deployment', $other->uuid)
        ->assertSee('FE-20250101-0002')
        ->assertDontSee('FE-20250101-0001');
});

test('licence page links to their invoices', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    $this->get(route('licences.edit', $deployment->uuid))
        ->assertOk()
        ->assertSee(route('invoices.index', ['deployment' => $deployment->uuid]), false)
        ->assertSee('Invoices')
        ->assertDontSee('All bills');
});

test('invoice modal prefills due dates and accepts an override', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();

    $component = Volt::test('pages.licences.edit', ['deployment' => $deployment])
        ->call('openInvoiceModal');

    expect($component->get('due_at'))->toBe(today()->addDays(30)->toDateString());
    expect($component->get('next_payment_at'))->toBe(today()->addYear()->toDateString());

    $component
        ->set('due_at', today()->addDays(7)->toDateString())
        ->call('createInvoice')
        ->assertHasNoErrors();

    expect(Invoice::where('deployment_id', $deployment->id)->firstOrFail()->due_at->toDateString())
        ->toBe(today()->addDays(7)->toDateString());
});

test('pending invoice can be deleted and the deletion is logged', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($deployment)->create(['invoice_no' => 'FE-20250101-0001']);

    Volt::test('pages.invoices.index')
        ->call('confirmDelete', $invoice->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Invoice::find($invoice->id))->toBeNull();
    $this->assertDatabaseHas('activity_log', ['description' => 'invoice.deleted']);
});

test('non-pending invoices cannot be deleted', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($deployment)->create(['status' => Invoice::STATUS_PAID]);

    Volt::test('pages.invoices.index')
        ->call('confirmDelete', $invoice->id)
        ->assertHasErrors(['invoice']);

    expect(Invoice::find($invoice->id))->not->toBeNull();
});

test('invoice settings save from catalogue and log the change', function () {
    $this->actingAs(actingSuperAdmin());

    Volt::test('pages.catalogue.index')
        ->set('doc_title', 'Tax Invoice')
        ->set('company', 'Acme Ltd')
        ->set('invoice_email', 'billing@acme.test')
        ->set('due_days', 14)
        ->call('saveInvoiceSettings')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(Setting::get(Setting::INVOICE_DOC_TITLE))->toBe('Tax Invoice');
    expect(Setting::get(Setting::INVOICE_COMPANY))->toBe('Acme Ltd');
    expect(Setting::get(Setting::INVOICE_DUE_DAYS))->toBe('14');
    $this->assertDatabaseHas('activity_log', ['description' => 'invoice-settings.updated']);
});

test('settings seeder provides invoice defaults', function () {
    expect(Setting::get(Setting::INVOICE_DOC_TITLE))->toBe('Proforma Invoice');
    expect(Setting::get(Setting::INVOICE_COMPANY))->toBe('Matme Inc.');
    expect(Setting::get(Setting::INVOICE_DUE_DAYS))->toBe('30');
});

test('stored invoice prints its snapshot title, issuer, and dates', function () {
    $this->actingAs(User::factory()->create());
    $deployment = Deployment::factory()->create();
    $invoice = Invoice::factory()->for($deployment)->create([
        'invoice_no' => 'FE-20250101-0001',
        'pricing' => Licence::priceSnapshot(['module_finance' => true], 500),
        'contact' => ['college_name' => $deployment->school_name],
        'doc_title' => 'Tax Invoice',
        'issuer' => ['company' => 'Acme Ltd', 'email' => 'billing@acme.test'],
        'due_at' => today()->addDays(14)->toDateString(),
        'next_payment_at' => today()->addYear()->toDateString(),
    ]);

    $this->get(route('licences.invoices.show', [$deployment->uuid, $invoice->id]))
        ->assertOk()
        ->assertSee('Tax Invoice')
        ->assertSee('Acme Ltd')
        ->assertSee(today()->addDays(14)->format('M d, Y'));
});
