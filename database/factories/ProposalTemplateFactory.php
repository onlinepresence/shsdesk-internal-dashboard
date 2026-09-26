<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProposalTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalTemplate>
 */
class ProposalTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'title' => ucfirst($this->faker->words(3, true)).' proposal',
            'version' => 1,
        ];
    }
}
