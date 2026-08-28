<?php

namespace Database\Factories;

use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    

    public function definition(): array
    {
        
        $subscription = Subscription::factory();

        
        $date = $this->faker->dateTimeBetween('-1 year', 'now');
        $dueDate = (clone $date)->modify('+30 days');

        
        $fee = $this->faker->randomFloat(2, 50, 1000);

        
        $discountOptions = array_keys(Helpers::getDiscounts());
        $discountPct = $this->faker->randomElement($discountOptions);
        $discountAmount = round(Helpers::getDiscountAmount($discountPct, $fee), 2);

        
        $paidAmount = $this->faker->randomFloat(2, 0, $fee);

        return [
            'number' => $this->faker->unique()->numerify('INV-#####'),
            'subscription_id' => $subscription,
            'date' => $date,
            'due_date' => $dueDate,
            'payment_method' => $this->faker->randomElement(['offline', 'stripe']),
            'status' => 'issued',
            'subscription_fee' => $fee,
            'discount' => $discountPct,
            'discount_amount' => $discountAmount,
            'discount_note' => $this->faker->sentence(3),
            'paid_amount' => $paidAmount,
        ];
    }
}
