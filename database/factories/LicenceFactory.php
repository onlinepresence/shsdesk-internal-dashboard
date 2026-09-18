<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\Licence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Licence>
 */
class LicenceFactory extends Factory
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
            'core' => ['core_timetable' => true, 'core_attendance' => true],
            'modules' => ['module_finance' => true],
            'caps' => ['max_active_students' => 500],
            'starts_at' => today()->subMonth(),
            'expires_at' => today()->addYear(),
            'notes' => null,
            'hosting_mode' => 'self_hosted',
            'config_setup' => false,
            'migration' => false,
            'training_admin' => 0,
            'training_teacher' => 0,
            'training_onsite' => 0,
            'founding_client' => false,
        ];
    }

    /**
     * Licence lapsing within the next 30 days.
     */
    public function expiring(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => today()->subYear()->addDays(10),
            'expires_at' => today()->addDays(10),
        ]);
    }

    /**
     * Licence already past expiry.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => today()->subYears(2),
            'expires_at' => today()->subDay(),
        ]);
    }

    /**
     * Licence with no expiry date.
     */
    public function indefinite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => null,
            'expires_at' => null,
        ]);
    }
}
