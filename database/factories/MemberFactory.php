<?php

namespace Database\Factories;

use App\Models\Member;
use Database\Factories\Concerns\WithSynchronizedLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberFactory extends Factory
{
    use WithSynchronizedLocation;

    

    public function definition(): array
    {
        $location = $this->synchronizedLocation();

        return [
            
            'photo' => null,
            'code' => $this->faker->unique()->bothify('MEM###'),
            'government_id' => $this->faker->unique()->bothify('ID-########'),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'contact' => $location['contact'],
            'emergency_contact' => $location['emergency_contact'],
            'health_issue' => $this->faker->optional()->sentence(),
            'gender' => $this->faker->randomElement(['male', 'female', 'other']),
            'dob' => $this->faker->date(),
            'address' => $location['address'],
            'country' => $location['country'],
            'city' => $location['city'],
            'state' => $location['state'],
            'pincode' => $location['pincode'],
            'source' => $this->faker->randomElement(['promotions', 'referral', 'online']),
            'goal' => $this->faker->randomElement(['fitness', 'weight loss', 'muscle gain']),
            'status' => $this->faker->randomElement(['active', 'inactive']),
        ];
    }
}
