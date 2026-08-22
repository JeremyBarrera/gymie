<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     *
     * Bootstrap-only: creates the owner account from `.env`
     * (`OWNER_NAME` / `OWNER_EMAIL` / `OWNER_PASSWORD`) when one with that
     * email does not exist yet. It never modifies an existing account — the
     * env credentials are not re-applied to a running install.
     */
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
