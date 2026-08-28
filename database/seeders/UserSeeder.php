<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    

    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => config('gymie.owner.email')],
            [
                'name' => config('gymie.owner.name'),
                'password' => Hash::make(config('gymie.owner.password')),
                'status' => 'active',
            ],
        )->syncRoles('owner');
    }
}
