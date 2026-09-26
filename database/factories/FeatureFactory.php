<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::query()->firstOrCreate(
                ['slug' => 'flowedu'],
                ['name' => 'FlowEdu', 'active' => true],
            )->id,
            'key' => 'feature_'.$this->faker->unique()->slug(2),
            'label' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->sentence(),
            'kind' => $this->faker->randomElement(['core', 'module']),
            'locked' => false,
            'default_on' => false,
            'base_price' => $this->faker->randomFloat(2, 500, 3000),
            'renewal_base' => $this->faker->randomFloat(2, 100, 750),
            'active' => true,
        ];
    }

    /**
     * Feature withdrawn from new grants and heartbeat answers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
