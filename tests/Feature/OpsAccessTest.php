<?php

use App\Models\DemoKey;
use App\Models\Deployment;
use App\Models\Invoice;
use App\Models\Licence;
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

/**
 * Every ops page route. File routes need the signing seed, so they
 * are listed separately.
 *
 * @return array<string, string>
 */
function opsPageUrls(Deployment $deployment, Invoice $invoice): array
{
    return [
        'deployments.index' => route('deployments.index'),
        'deployments.create' => route('deployments.create'),
        'deployments.show' => route('deployments.show', $deployment),
        'licences.index' => route('licences.index'),
        'licences.edit' => route('licences.edit', $deployment->uuid),
        'licences.invoices.show' => route('licences.invoices.show', [$deployment->uuid, $invoice->id]),
        'invoices.index' => route('invoices.index'),
        'products.index' => route('products.index'),
        'leads.index' => route('leads.index'),
        'demo-keys.index' => route('demo-keys.index'),
        'catalogue.index' => route('catalogue.index'),
    ];
}

/**
 * Signed file routes — same gates, plus the signing seed.
 *
 * @return array<string, string>
 */
function opsFileUrls(Deployment $deployment, DemoKey $demoKey): array
{
    return [
        'licences.export' => route('licences.export', $deployment->uuid),
        'demo-keys.download' => route('demo-keys.download', $demoKey),
    ];
}

function seedOpsFixtures(): array
{
    config()->set('licence-export.signing_key', str_repeat('a', 64));

    $deployment = Deployment::factory()->create();
    Licence::factory()->for($deployment)->create();
    $invoice = Invoice::factory()->for($deployment)->create();
    $demoKey = DemoKey::factory()->create();

    return [$deployment, $invoice, $demoKey];
}

test('guests are redirected to login on every ops route', function () {
    [$deployment, $invoice, $demoKey] = seedOpsFixtures();

    foreach ([...opsPageUrls($deployment, $invoice), ...opsFileUrls($deployment, $demoKey)] as $label => $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

test('bare users get 403 on every ops route', function () {
    [$deployment, $invoice, $demoKey] = seedOpsFixtures();

    $this->actingAs(User::factory()->create());

    foreach ([...opsPageUrls($deployment, $invoice), ...opsFileUrls($deployment, $demoKey)] as $label => $url) {
        $this->get($url)->assertForbidden();
    }
});

test('owners pass every ops route', function () {
    [$deployment, $invoice, $demoKey] = seedOpsFixtures();

    $this->actingAs(owner());

    foreach (opsPageUrls($deployment, $invoice) as $label => $url) {
        $this->get($url)->assertOk();
    }

    foreach (opsFileUrls($deployment, $demoKey) as $label => $url) {
        $this->get($url)->assertOk();
    }
});

test('bare users are denied on every ops component', function () {
    [$deployment] = seedOpsFixtures();

    $this->actingAs(User::factory()->create());

    Volt::test('pages.deployments.index')->assertForbidden();
    Volt::test('pages.deployments.create')->assertForbidden();
    Volt::test('pages.deployments.show', ['deployment' => $deployment])->assertForbidden();
    Volt::test('pages.licences.index')->assertForbidden();
    Volt::test('pages.licences.edit', ['deployment' => $deployment])->assertForbidden();
    Volt::test('pages.invoices.index')->assertForbidden();
    Volt::test('pages.products.index')->assertForbidden();
    Volt::test('pages.leads.index')->assertForbidden();
    Volt::test('pages.demo-keys.index')->assertForbidden();
    Volt::test('pages.catalogue.index')->assertForbidden();
});

test('bare users are forbidden on token, code, and export actions', function () {
    [$deployment] = seedOpsFixtures();

    $bare = User::factory()->create();

    // Boot every component as the owner for a valid snapshot, then
    // replay each mutating action as the bare user — exactly what a
    // snapshot smuggled past the route middleware would attempt.
    $this->actingAs(owner());

    $creator = Volt::test('pages.deployments.create')
        ->set('school_name', 'Smuggled School')
        ->set('product', 'flowedu')
        ->set('url', 'https://smuggled.example.com');

    $showerToken = Volt::test('pages.deployments.show', ['deployment' => $deployment]);
    $showerCode = Volt::test('pages.deployments.show', ['deployment' => $deployment]);
    $editor = Volt::test('pages.licences.edit', ['deployment' => $deployment]);
    $demo = Volt::test('pages.demo-keys.index')->set('label', 'Smuggled');
    $catalogue = Volt::test('pages.catalogue.index');

    $this->actingAs($bare);

    // One forbidden call per snapshot: the denied update leaves no
    // replayable snapshot behind.
    $creator->call('register')->assertForbidden();
    $showerToken->call('regenerateToken')->assertForbidden();
    $showerCode->call('mintCode')->assertForbidden();
    $editor->call('save')->assertForbidden();
    $demo->call('mint')->assertForbidden();
    $catalogue->call('saveGlobals')->assertForbidden();

    expect(Deployment::where('school_name', 'Smuggled School')->count())->toBe(0);
    expect(DemoKey::where('label', 'Smuggled')->count())->toBe(0);
});

test('owners can register deployments and mint codes', function () {
    seedOpsFixtures();

    $this->actingAs(owner());

    $token = Volt::test('pages.deployments.create')
        ->set('school_name', 'Owner Academy')
        ->set('product', 'flowedu')
        ->set('url', 'https://owner.example.com')
        ->call('register')
        ->assertHasNoErrors()
        ->get('plainTextToken');

    expect($token)->toBeString()->not->toBeEmpty();

    $deployment = Deployment::where('school_name', 'Owner Academy')->firstOrFail();

    $code = Volt::test('pages.deployments.show', ['deployment' => $deployment])
        ->call('mintCode')
        ->assertHasNoErrors()
        ->get('plainTextCode');

    expect($code)->toBeString()->not->toBeEmpty();
});

test('catalogue pricing actions stay behind manage-catalogue', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.catalogue.index')->assertForbidden();

    $this->actingAs(owner());

    Volt::test('pages.catalogue.index')->assertHasNoErrors();
});
