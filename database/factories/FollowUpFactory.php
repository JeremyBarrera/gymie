<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FollowUpFactory extends Factory
{
    

    public function definition(): array
    {
        return [
            'enquiry_id' => Enquiry::factory(),
            'user_id' => User::factory(),
            'schedule_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'method' => $this->faker->randomElement(['call', 'email', 'in_person', 'whatsapp', 'other']),
            'outcome' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['pending', 'done']),
        ];
    }
}
