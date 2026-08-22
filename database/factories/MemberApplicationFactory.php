<?php

namespace Database\Factories;

use App\Models\MemberApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberApplicationFactory extends Factory
{
    protected $model = MemberApplication::class;

    public function definition(): array
    {
        return [
            'identifier_type' => $this->faker->randomElement(['contact', 'government_id', 'email']),
            'identifier_value' => $this->faker->unique()->numerify('##########'),
            'payload' => [
                'name' => $this->faker->name(),
                'contact' => $this->faker->phoneNumber(),
                'email' => $this->faker->safeEmail(),
            ],
            'status' => $this->faker->randomElement(['pending', 'approved', 'rejected']),
            'created_member_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'pending']);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'approved']);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'rejected']);
    }
}
