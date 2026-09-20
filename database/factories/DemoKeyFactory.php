<?php

namespace Database\Factories;

use App\Models\DemoKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoKey>
 */
class DemoKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => ucfirst($this->faker->word()).' demo',
            'scope' => DemoKey::SCOPE_FULL,
            'code_hash' => DemoKey::hashCode(DemoKey::generateCode()),
            'expires_at' => now()->addMonth(),
            'host' => null,
            'revoked_at' => null,
            'last_used_at' => null,
        ];
    }

    /**
     * Key that never lapses.
     */
    public function never(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => null,
        ]);
    }

    /**
     * Key retired by hand.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now()->subMinute(),
        ]);
    }
}
