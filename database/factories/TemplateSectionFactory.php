<?php

namespace Database\Factories;

use App\Models\ProposalTemplate;
use App\Models\TemplateSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateSection>
 */
class TemplateSectionFactory extends Factory
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
            'order' => 0,
            'heading' => ucfirst($this->faker->words(2, true)),
            'body_html' => '<p>'.$this->faker->sentence().'</p>',
            'type' => TemplateSection::TYPE_PROSE,
            'config' => null,
        ];
    }
}
