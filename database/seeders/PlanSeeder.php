<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    

    public function run(): void
    {
        Plan::factory()->count(5)->create();
    }
}
