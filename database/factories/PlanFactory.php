<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->numerify('PLN###'),
            'name' => $this->faker->name(),
            'description' => $this->faker->text(50),
            'days' => $this->faker->numberBetween(1, 365),
            'amount' => $this->faker->randomFloat(2, 10, 1000),
            'status' => $this->faker->randomElement(['active', 'inactive']),
            'limit_uses' => false,
            'uses_limit' => null,
        ];
    }

    

    public function withUseLimit(int $limit = 10): static
    {
        return $this->state(fn (): array => [
            'limit_uses' => true,
            'uses_limit' => $limit,
        ]);
    }
}
