<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationTokenFactory extends Factory
{
    protected $model = LocationToken::class;

    public function definition(): array
    {
        return [
            'token' => $this->faker->uuid(),
            'tokenable_type' => Location::class,
            'tokenable_id' => Location::factory(),
            'kind' => $this->faker->randomElement(['checkin', 'signup']),
        ];
    }

    public function checkin(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'checkin']);
    }

    public function signup(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'signup']);
    }
}
