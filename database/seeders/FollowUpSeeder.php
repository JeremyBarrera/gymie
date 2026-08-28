<?php

namespace Database\Seeders;

use App\Models\FollowUp;
use Illuminate\Database\Seeder;

class FollowUpSeeder extends Seeder
{
    

    public function run(): void
    {
        FollowUp::factory()->count(5)->create();
    }
}
