<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Plan;
use App\Models\PlanCheckIn;
use App\Models\Service;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanCheckInFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'subscription_id' => Subscription::factory(),
            'plan_id' => Plan::factory(),
            'service_id' => Service::factory(),
            'location_id' => null,
            'checked_in_by' => null,
            'checked_in_at' => now(),
        ];
    }
}
