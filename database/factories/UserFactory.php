<?php

namespace Database\Factories;

use App\Contracts\TenantContext;
use App\Models\Location;
use App\Models\User;
use Database\Factories\Concerns\WithSynchronizedLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    use WithSynchronizedLocation;

    

    protected static ?string $password;

    public $status;

    public $gender;

    

    public function definition(): array
    {
        $this->status = $this->faker->randomElement(['active', 'inactive']);
        $this->gender = $this->faker->randomElement(['male', 'female', 'other']);
        $location = $this->synchronizedLocation();

        return [
            'name' => $this->faker->company,
            'contact' => $location['contact'],
            'address' => $location['address'],
            'country' => $location['country'],
            'state' => $location['state'],
            'city' => $location['city'],
            'pincode' => $location['pincode'],
            'gender' => $this->gender,
            'status' => $this->status,
            'dob' => $this->faker->date(),
            'email' => $this->faker->unique()->safeEmail,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->locations()->exists()) {
                return;
            }

            $locationId = app()->bound(TenantContext::class)
                ? app(TenantContext::class)->locationId()
                : null;

            if ($locationId !== null && Location::query()->whereKey($locationId)->exists()) {
                $user->locations()->attach($locationId);
            }
        });
    }

    

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
