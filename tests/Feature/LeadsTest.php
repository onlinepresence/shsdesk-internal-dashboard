<?php

use App\Models\Deployment;
use App\Models\Feature;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(ProductSeeder::class);
});

function leadPayload(array $overrides = []): array
{
    return array_merge([
        'product_slug' => 'flowedu',
        'contact' => [
            'name' => 'Ama Serwaa',
            'email' => 'ama@example.com',
            'role' => 'Bursar',
            'phone' => '0240000000',
            'school' => 'Tema College',
        ],
        'band' => '1-500',
        'modules' => ['finance', 'staff_hr'],
        'quote' => [
            'upfront' => 9300.00,
            'renewal' => 2100.00,
            'lines' => [['label' => 'Core Academic License', 'amount' => 4500.00]],
        ],
    ], $overrides);
}

test('intake maps FlowEdu module keys to canonical db_column keys', function () {
    $this->postJson('/api/v1/leads', leadPayload())
        ->assertCreated();

    $lead = Lead::where('contact_email', 'ama@example.com')->firstOrFail();

    expect($lead->modules)->toBe(['module_finance', 'module_staff_hr']);
    expect($lead->band)->toBe('1-500');
    expect($lead->contact_role)->toBe('Bursar');
    expect($lead->school)->toBe('Tema College');
});

test('intake rejects unknown module strings without storing', function () {
    $this->postJson('/api/v1/leads', leadPayload(['modules' => ['finance', 'teleportation']]))
        ->assertUnprocessable();

    expect(Lead::query()->count())->toBe(0);
});

test('accepted band values equal the FlowEdu core_pricing keys', function () {
    expect(array_column(Setting::corePricing(), 'key'))
        ->toBe(['1-500', '501-1000', '1001-2000', '2001-3500', '3500+']);
});

test('intake rejects unknown bands without storing', function () {
    $this->postJson('/api/v1/leads', leadPayload(['band' => '1-100']))
        ->assertUnprocessable();

    expect(Lead::query()->count())->toBe(0);
});

test('intake maps every FlowEdu module key in the explicit map', function () {
    $flowKeys = array_keys(Feature::FLOWEDU_MODULE_MAP);

    expect($flowKeys)->toEqualCanonicalizing([
        'finance', 'staff_hr', 'reports', 'evaluations', 'student_welfare',
        'progression', 'system_admin', 'teacher_tools', 'messaging', 'practicum',
    ]);

    $this->postJson('/api/v1/leads', leadPayload(['modules' => $flowKeys]))
        ->assertCreated();

    expect(Lead::where('contact_email', 'ama@example.com')->firstOrFail()->modules)
        ->toEqualCanonicalizing(array_values(Feature::FLOWEDU_MODULE_MAP));
});

test('contact college falls back to school when school is absent', function () {
    $contact = leadPayload()['contact'];
    unset($contact['school']);
    $contact['college'] = 'Accra Academy';

    $this->postJson('/api/v1/leads', leadPayload(['contact' => $contact]))
        ->assertCreated();

    expect(Lead::where('contact_email', 'ama@example.com')->firstOrFail()->school)
        ->toBe('Accra Academy');
});

test('resubmission refreshes modules and role instead of duplicating', function () {
    $this->postJson('/api/v1/leads', leadPayload())->assertCreated();

    $this->postJson('/api/v1/leads', leadPayload([
        'modules' => ['reports'],
        'contact' => [
            'name' => 'Ama Serwaa',
            'email' => 'ama@example.com',
            'role' => 'Headmistress',
        ],
    ]))
        ->assertOk()
        ->assertJsonPath('deduped', true);

    $leads = Lead::where('contact_email', 'ama@example.com')->get();

    expect($leads)->toHaveCount(1);
    expect($leads->first()->modules)->toBe(['module_reports']);
    expect($leads->first()->contact_role)->toBe('Headmistress');
});

test('contact role is shown on the lead detail modal', function () {
    $this->actingAs(User::factory()->create());

    $lead = Lead::factory()->create(['contact_role' => 'Bursar']);

    Volt::test('pages.leads.index')
        ->set('viewingId', $lead->id)
        ->assertSee('Bursar')
        ->assertSee($lead->contact_email);
});

test('convert carries quoted modules and contact notes into licence prefill', function () {
    $this->actingAs(User::factory()->create());

    $lead = Lead::factory()->create([
        'modules' => ['module_finance', 'module_reports'],
        'contact_name' => 'Ama Serwaa',
        'contact_role' => 'Bursar',
        'contact_email' => 'ama@example.com',
    ]);

    Volt::test('pages.leads.index')
        ->call('convert', $lead->id)
        ->assertRedirect(route('deployments.create'));

    expect(session('lead_prefill.modules'))->toBe(['module_finance', 'module_reports']);

    $deployment = Volt::test('pages.deployments.create')
        ->set('url', 'https://tema.example.com')
        ->call('register')
        ->get('createdDeployment');

    expect($deployment)->toBeInstanceOf(Deployment::class);

    $component = Volt::test('pages.licences.edit', ['deployment' => $deployment]);

    expect($component->get('modules'))->toEqualCanonicalizing(['module_finance', 'module_reports']);
    expect($component->get('notes'))->toContain('Bursar')->toContain('ama@example.com');
});
