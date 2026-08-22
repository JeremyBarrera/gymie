<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::query()->inRandomOrder()->value('id') ?? Service::factory(),
            'code' => $this->faker->unique()->numerify('PLN###'),
            'name' => $this->faker->name(),
            'description' => $this->faker->text(50),
            'days' => $this->faker->numberBetween(1, 365),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'track_uses' => false,
            'uses_limit' => null,
        ];
    }

    /**
     * Plan that tracks a limited number of uses.
     */
    public function withUseLimit(int $limit = 10): static
    {
        return $this->state(fn (): array => [
            'track_uses' => true,
            'uses_limit' => $limit,
        ]);
    }
}
