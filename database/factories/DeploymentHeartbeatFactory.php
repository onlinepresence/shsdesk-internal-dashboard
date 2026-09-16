<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\DeploymentHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentHeartbeat>
 */
class DeploymentHeartbeatFactory extends Factory
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
            'app_version' => '1.0.0',
            'students' => fake()->numberBetween(0, 2000),
            'teachers' => fake()->numberBetween(0, 200),
            'users' => fake()->numberBetween(0, 2200),
            'modules_in_use' => ['attendance', 'grades'],
        ];
    }
}
