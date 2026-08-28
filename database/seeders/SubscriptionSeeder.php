<?php

namespace Database\Seeders;

use App\Models\Subscription;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    

    public function run(): void
    {
        Subscription::factory()->count(10)->create();
    }
}
