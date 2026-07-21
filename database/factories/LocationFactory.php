<?php

namespace Database\Factories;

use App\Models\Location;
use Database\Factories\Concerns\WithSynchronizedLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    use WithSynchronizedLocation;

    public function definition(): array
    {
        $location = $this->synchronizedLocation();

        return [
            'name' => $this->faker->company(),
            'address' => $location['address'],
            'country' => $location['country'],
            'state' => $location['state'],
            'city' => $location['city'],
            'pincode' => $location['pincode'],
            'phone' => $location['contact'],
        ];
    }
}
