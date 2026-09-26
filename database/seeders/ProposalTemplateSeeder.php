<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProposalTemplate;
use App\Models\TemplateSection;
use Illuminate\Database\Seeder;

/**
 * The reviewed FlowEdu proposal template. Prose is human-authored with
 * the review fixes baked in (never pasted raw):
 *
 * - Band renewal figures bind the live catalogue, which already carries
 *   the reviewed renewals (501–1000 → 1,600; 1001–2000 → 2,200) — the
 *   template itself types no figures, so it can never disagree.
 * - Attendance covers lecturer registers only; no student tracking claim.
 * - Messaging is in-system messaging with read receipts; no real-time.
 * - Stack states Laravel 13; hosting says Managed (spelled right).
 * - The uptime claim is scoped to Managed hosting with exclusions.
 * - The date resolves at generation ({{date}}), never hardcoded.
 *
 * Existing rows are left untouched so re-running is always safe.
 */
class ProposalTemplateSeeder extends Seeder
{
    public const TITLE = 'FlowEdu proposal';

    public function run(): void
    {
        $product = Product::where('slug', 'flowedu')->first();

        abort_unless($product instanceof Product, 500, 'Seed products before proposal templates.');

        if (ProposalTemplate::query()->where('product_id', $product->id)->where('title', self::TITLE)->exists()) {
            return;
        }

        $template = ProposalTemplate::query()->create([
            'product_id' => $product->id,
            'title' => self::TITLE,
            'version' => 1,
        ]);

        foreach ($this->sections() as $order => $section) {
            $template->sections()->create(array_merge(['order' => $order], $section));
        }
    }

    /**
     * @return list<array{heading: ?string, body_html: ?string, type: string, config: ?array}>
     */
    protected function sections(): array
    {
        return [
            [
                'heading' => 'Introduction',
                'body_html' => '<p>Proposal {{proposal_no}} prepares {{school}} for FlowEdu, our school management platform on Laravel 13. This quote is addressed to {{client}} ({{contact}}), dated {{date}}, for the {{band}} student band.</p><p>Everything below is generated from the live catalogue at signing time: modules, bands, and every figure.</p>',
                'type' => TemplateSection::TYPE_PROSE,
                'config' => null,
            ],
            [
                'heading' => 'Core platform',
                'body_html' => '<p>Every deployment ships the academic core: structure, students, grading, and teacher and student portals. Two points of scope, stated plainly:</p><ul><li><strong>Attendance</strong> covers lecturer registers only. Students do not track themselves; there is no student self check-in.</li><li><strong>Messaging</strong> is in-system messaging with read receipts. It is not real-time chat.</li></ul>',
                'type' => TemplateSection::TYPE_PROSE,
                'config' => null,
            ],
            [
                'heading' => 'Modules and pricing',
                'body_html' => null,
                'type' => TemplateSection::TYPE_PRICING_TABLE,
                'config' => ['source' => TemplateSection::PRICING_MODULES],
            ],
            [
                'heading' => 'Student bands and renewal',
                'body_html' => null,
                'type' => TemplateSection::TYPE_PRICING_TABLE,
                'config' => ['source' => TemplateSection::PRICING_BANDS],
            ],
            [
                'heading' => 'Hosting, support, and uptime',
                'body_html' => '<p>Self-hosted and Managed hosting are both supported; only Managed hosting carries the uptime commitment below. Support answers in tiers: critical faults (the system is unusable) get a same-business-day response, everything else next business day. Uptime of 99.5% per calendar month applies to Managed hosting only, excluding scheduled maintenance windows and outages in school-side network or power.</p>',
                'type' => TemplateSection::TYPE_PROSE,
                'config' => null,
            ],
            [
                'heading' => 'Sign-off',
                'body_html' => '<p>Signing returns this proposal with its number; the licence follows on payment.</p>',
                'type' => TemplateSection::TYPE_SIGNATURE,
                'config' => null,
            ],
        ];
    }
}
