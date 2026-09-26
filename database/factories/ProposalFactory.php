<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Support\ProposalNumber;
use App\Support\ProposalPricing;
use App\Support\ProposalRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_template_id' => ProposalTemplate::factory(),
            'template_version' => 1,
            'proposal_no' => ProposalNumber::next(),
            'lead_id' => null,
            'deployment_id' => null,
            'values' => ProposalRenderer::sampleValues(),
            'sections_snapshot' => [
                ['heading' => 'Introduction', 'body_html' => '<p>Proposal for {{school}}.</p>', 'type' => 'prose', 'config' => null],
            ],
            'pricing_snapshot' => ProposalPricing::snapshot(),
            'status' => Proposal::STATUS_DRAFT,
        ];
    }
}
