<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\PaymentTransactions;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentTransactions> */
class PaymentTransactionsFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'type' => 'deposit',
            'amount' => fake()->numberBetween(1000, 100000),
            'status' => 'pending',
            'account_name' => fake()->name(),
            'account_number' => fake()->numerify('09#########'),
            'reference_number' => (string) fake()->uuid(),
        ];
    }
}
