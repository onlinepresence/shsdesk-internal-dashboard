<?php

namespace Database\Factories;

use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'product' => 'flowedu',
            'school_name' => fake()->company().' School',
            'url' => fake()->url(),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    /**
     * Deployment silent for longer than the stale window.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_seen_at' => now()->subHours(Deployment::STALE_AFTER_HOURS + 1),
        ]);
    }

    /**
     * Deployment that never checked in and aged past the stale window.
     */
    public function neverSeen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_seen_at' => null,
            'created_at' => now()->subHours(Deployment::STALE_AFTER_HOURS + 1),
        ]);
    }

    /**
     * Deployment whose tokens were revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
