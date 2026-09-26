<?php

use App\Models\Feature;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\Setting;
use App\Support\ProposalPricing;
use App\Support\ProposalRenderer;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(ProductSeeder::class);
});

function floweduId(): int
{
    return Product::where('slug', 'flowedu')->firstOrFail()->id;
}

test('picker lists active products by default', function () {
    $this->actingAs(owner());

    $component = Volt::test('pages.catalogue.index')
        ->assertSee('FlowEdu')
        ->assertDontSee('SHSDesk')
        ->assertDontSee('EduRecords GH');

    expect($component->get('product_id'))->toBe(floweduId());

    $component
        ->set('show_inactive_products', true)
        ->assertSee('SHSDesk')
        ->assertSee('EduRecords GH');
});

test('features filter by picked product and keys scope per product', function () {
    $shsdesk = Product::where('slug', 'shsdesk')->firstOrFail();

    Feature::factory()->create([
        'product_id' => $shsdesk->id,
        'kind' => 'module',
        'key' => 'module_reports_desk',
        'label' => 'Desk Reports',
    ]);

    $this->actingAs(owner());

    // Same key may exist under two products at different prices.
    Volt::test('pages.catalogue.index')
        ->set('product_id', $shsdesk->id)
        ->set('key', 'module_finance')
        ->set('label', 'Desk Finance')
        ->set('kind', 'module')
        ->set('base_price', '100')
        ->set('renewal_base', '10')
        ->call('save')
        ->assertHasNoErrors();

    // Still scoped: Desk rows hide under FlowEdu and vice versa.
    Volt::test('pages.catalogue.index')->assertDontSee('Desk Reports');

    Volt::test('pages.catalogue.index')
        ->set('product_id', $shsdesk->id)
        ->assertSee('Desk Reports')
        ->assertSee('Desk Finance')
        ->assertDontSee('Financial Portal');
});

test('product overrides win with global fallback', function () {
    $flowedu = floweduId();
    $shsdesk = Product::where('slug', 'shsdesk')->firstOrFail()->id;

    Setting::setForProduct($flowedu, Setting::HOSTING_MANAGED_FEE, '1700');
    Setting::setForProduct($flowedu, Setting::CURRENCY, 'USD');

    expect(Setting::getForProduct($flowedu, Setting::HOSTING_MANAGED_FEE))->toBe('1700');
    expect(Setting::getForProduct($flowedu, Setting::CURRENCY))->toBe('USD');

    // Globals untouched, other products fall back to them.
    expect(Setting::get(Setting::HOSTING_MANAGED_FEE))->toBe('1600');
    expect(Setting::getForProduct($shsdesk, Setting::HOSTING_MANAGED_FEE))->toBe('1600');
    expect(Setting::getForProduct($shsdesk, Setting::CURRENCY))->toBe('GHS');
    expect(ProposalPricing::currency($shsdesk))->toBe('GHS');
});

test('proposal snapshots price their own product', function () {
    $acme = Product::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

    Feature::factory()->create([
        'product_id' => $acme->id,
        'kind' => 'module',
        'label' => 'Acme Module',
        'base_price' => 1111,
        'renewal_base' => 222,
    ]);

    $floweduTemplate = ProposalTemplate::factory()->create(['product_id' => floweduId()]);
    $acmeTemplate = ProposalTemplate::factory()->create(['product_id' => $acme->id]);

    $floweduProposal = Proposal::generateFrom($floweduTemplate, ProposalRenderer::sampleValues());
    $acmeProposal = Proposal::generateFrom($acmeTemplate, ProposalRenderer::sampleValues());

    expect(collect($acmeProposal->pricing_snapshot['modules'])->pluck('label')->all())
        ->toBe(['Acme Module']);
    expect(collect($acmeProposal->pricing_snapshot['modules'])->first())->toMatchArray([
        'onetime' => 1111.0,
        'renew' => 222.0,
    ]);
    expect(collect($floweduProposal->pricing_snapshot['modules'])->pluck('label')->all())
        ->not->toContain('Acme Module');
});
