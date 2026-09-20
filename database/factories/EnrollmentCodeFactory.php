<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\EnrollmentCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentCode>
 */
class EnrollmentCodeFactory extends Factory
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
            'code_hash' => EnrollmentCode::hashCode(EnrollmentCode::generateCode()),
            'expires_at' => now()->addMinutes(EnrollmentCode::EXPIRY_MINUTES),
            'consumed_at' => null,
            'failed_attempts' => 0,
            'voided_at' => null,
        ];
    }

    /**
     * Code already redeemed.
     */
    public function consumed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'consumed_at' => now()->subMinute(),
        ]);
    }

    /**
     * Code past its redeem window.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Code voided by hand or by too many failures.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at' => now()->subMinute(),
        ]);
    }
}
