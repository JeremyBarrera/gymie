<?php

namespace Database\Seeders;

use App\Models\Enquiry;
use Illuminate\Database\Seeder;

class EnquirySeeder extends Seeder
{
    

    public function run(): void
    {
        Enquiry::factory()->count(5)->create();
    }
}
