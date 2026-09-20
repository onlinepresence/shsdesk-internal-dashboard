<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
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
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->unique()->safeEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
            'school' => $this->faker->company().' School',
            'band' => '1-500',
            'modules' => ['module_finance'],
            'quote_upfront' => $this->faker->randomFloat(2, 1000, 20000),
            'quote_renewal' => $this->faker->randomFloat(2, 500, 5000),
            'quote_lines' => [['label' => 'Core Academic License', 'amount' => 4500.00]],
            'status' => Lead::STATUS_NEW,
        ];
    }
}
