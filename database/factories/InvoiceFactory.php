<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\Invoice;
use App\Models\Licence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'licence_id' => null,
            'invoice_no' => null,
            'contact' => ['college_name' => $this->faker->company()],
            'pricing' => Licence::priceSnapshot([], 500),
            'created_by' => null,
        ];
    }
}
