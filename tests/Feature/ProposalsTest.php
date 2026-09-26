<?php

use App\Models\Feature;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\Setting;
use App\Models\TemplateSection;
use App\Models\User;
use App\Support\DocxImporter;
use App\Support\ProposalFiles;
use App\Support\ProposalPlaceholders;
use App\Support\ProposalRenderer;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\ProposalTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(CatalogueSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(ProductSeeder::class);
    $this->seed(ProposalTemplateSeeder::class);
});

function seedTemplateWithSections(): ProposalTemplate
{
    $template = ProposalTemplate::factory()->create();

    foreach (['Alpha', 'Beta', 'Gamma'] as $order => $heading) {
        TemplateSection::factory()->for($template, 'template')->create([
            'order' => $order,
            'heading' => $heading,
        ]);
    }

    return $template;
}

test('sections reorder and render numbered in order', function () {
    $this->actingAs(owner());
    $template = seedTemplateWithSections();

    Volt::test('pages.proposals.templates.edit', ['template' => $template])
        ->call('moveUp', 's'.$template->sections()->where('heading', 'Gamma')->firstOrFail()->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('proposal-templates.index'));

    $headings = $template->fresh()->sections()->orderBy('order')->pluck('heading')->all();

    expect($headings)->toBe(['Alpha', 'Gamma', 'Beta']);

    $html = ProposalRenderer::renderHtml(ProposalRenderer::resolve(
        $template->fresh()->sections->map(fn (TemplateSection $section): array => [
            'heading' => $section->heading,
            'body_html' => $section->body_html,
            'type' => $section->type,
            'config' => $section->config,
        ])->all(),
        ProposalRenderer::sampleValues(),
    ));

    expect($html)->toContain('1. Alpha')
        ->toContain('2. Gamma')
        ->toContain('3. Beta');
});

test('proposal numbers sequence per year', function () {
    $this->actingAs(owner());
    $template = ProposalTemplate::factory()->create();
    $year = today()->format('Y');

    $first = Proposal::generateFrom($template, ProposalRenderer::sampleValues());
    $second = Proposal::generateFrom($template, ProposalRenderer::sampleValues());

    expect($first->proposal_no)->toBe("PROP-{$year}-001");
    expect($second->proposal_no)->toBe("PROP-{$year}-002");
});

test('unknown placeholders block save and valid keys pass', function () {
    $this->actingAs(owner());
    $template = seedTemplateWithSections();

    Volt::test('pages.proposals.templates.edit', ['template' => $template])
        ->set('sections.0.body_html', '<p>Bill {{school}} for {{bogus_key}}.</p>')
        ->call('save')
        ->assertHasErrors(['sections.0.body_html']);

    expect($template->fresh()->version)->toBe(1);

    Volt::test('pages.proposals.templates.edit', ['template' => $template])
        ->set('sections.0.body_html', '<p>Bill {{school}} for {{band}} pupils.</p>')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('proposal-templates.index'));

    expect($template->fresh()->version)->toBe(2);
});

test('placeholder helpers extract and flag keys', function () {
    expect(ProposalPlaceholders::extractKeys(['<p>{{school}} and {{bogus}}</p>', null, '']))
        ->toBe(['school', 'bogus']);

    expect(ProposalPlaceholders::unknownKeys(['<p>{{school}} and {{bogus}}</p>']))
        ->toBe(['bogus']);
});

test('re-download is immune to later price edits', function () {
    $this->actingAs(owner());
    $template = ProposalTemplate::where('title', ProposalTemplateSeeder::TITLE)->firstOrFail();

    $proposal = Proposal::generateFrom($template, [
        'school' => 'Riverside College',
        'client' => 'Ama Serwaa',
        'contact' => 'ama@example.com',
        'band' => '1-500',
        'date' => today()->toDateString(),
    ]);

    $before = ProposalRenderer::renderHtml(ProposalRenderer::instanceBlocks($proposal->fresh()));
    $snapshot = $proposal->fresh()->pricing_snapshot;

    expect($before)->toContain('1,600.00');

    Feature::where('key', 'module_finance')->update(['base_price' => 99999.00]);
    Setting::set(Setting::CORE_PRICING, json_encode(array_map(
        fn (array $band): array => array_merge($band, ['core_renewal' => 99999]),
        Setting::corePricing()
    )));

    $proposal->refresh();

    expect($proposal->pricing_snapshot)->toBe($snapshot);
    expect(ProposalRenderer::renderHtml(ProposalRenderer::instanceBlocks($proposal)))->toBe($before);

    $this->get(route('proposals.pdf', $proposal))->assertOk();
    $this->get(route('proposals.docx', $proposal))->assertOk();
});

test('import round-trips the seeded template', function () {
    $this->actingAs(owner());
    $template = ProposalTemplate::where('title', ProposalTemplateSeeder::TITLE)->firstOrFail();

    $proposal = Proposal::generateFrom($template, [
        'school' => 'Riverside College',
        'client' => 'Ama Serwaa',
        'contact' => 'ama@example.com',
        'band' => '1-500',
        'date' => today()->toDateString(),
    ]);

    $path = ProposalFiles::docxPath($proposal);

    try {
        $parsed = DocxImporter::parse($path);
    } finally {
        @unlink($path);
    }

    $seededHeadings = $template->sections()->orderBy('order')->pluck('heading')->all();
    $parsedHeadings = array_map(
        fn (string $heading): string => preg_replace('/^\d+\.\s*/', '', $heading),
        array_column($parsed['sections'], 'heading')
    );

    // The exporter leads with a heading-less cover (number + school);
    // everything after it round-trips section for section.
    expect($parsedHeadings[0])->toBe('');
    expect($parsed['sections'][0]['body_html'])->toContain($proposal->proposal_no);
    expect(array_slice($parsedHeadings, 1))->toBe($seededHeadings);
    expect($parsed['tables'])->toBeGreaterThanOrEqual(2);
    expect($parsed['notes'])->not->toBeEmpty();

    // Generated documents carry filled values, never raw keys — while the
    // seeded template itself holds every placeholder the flow needs.
    expect($parsed['placeholders'])->toBe([]);

    $rawChunks = [];

    foreach ($template->sections()->orderBy('order')->get() as $section) {
        $rawChunks[] = $section->heading;
        $rawChunks[] = $section->body_html;
    }

    expect(ProposalPlaceholders::extractKeys($rawChunks))
        ->toEqualCanonicalizing(['proposal_no', 'school', 'client', 'contact', 'date', 'band']);
});

test('proposal create prefills from a lead', function () {
    $this->actingAs(owner());

    session(['proposal_prefill' => [
        'lead_id' => null,
        'school' => 'Tema College',
        'client' => 'Ama Serwaa',
        'contact' => 'ama@example.com',
        'band' => '1-500',
        'product_id' => null,
    ]]);

    $component = Volt::test('pages.proposals.create');

    expect($component->get('school'))->toBe('Tema College')
        ->and($component->get('client'))->toBe('Ama Serwaa')
        ->and($component->get('contact'))->toBe('ama@example.com')
        ->and($component->get('band'))->toBe('1-500');
});

test('proposal pages are gated to ops access', function () {
    $template = seedTemplateWithSections();
    $proposal = Proposal::generateFrom($template, ProposalRenderer::sampleValues());

    $this->actingAs(User::factory()->create());

    $this->get(route('proposals.index'))->assertForbidden();
    $this->get(route('proposals.create'))->assertForbidden();
    $this->get(route('proposal-templates.index'))->assertForbidden();
    $this->get(route('proposal-templates.edit', $template))->assertForbidden();
    $this->get(route('proposals.pdf', $proposal))->assertForbidden();
    $this->get(route('proposals.docx', $proposal))->assertForbidden();

    Volt::test('pages.proposals.templates.edit', ['template' => $template])->assertForbidden();

    $this->actingAs(owner());

    $this->get(route('proposals.index'))->assertOk();
    $this->get(route('proposals.create'))->assertOk();
    $this->get(route('proposal-templates.index'))->assertOk();
    $this->get(route('proposal-templates.edit', $template))->assertOk();
});
