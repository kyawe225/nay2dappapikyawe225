<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'deposit',
            'reference_id' => fake()->uuid(),
            'payment_user_id' => User::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => fake()->numberBetween(1, 100) * 100,
            'paid_at' => now(),
            'last_six_tran_id' => fake()->numerify('######'),
            'payment_accounts_id' => function (array $attributes): string {
                $accountId = (string) Str::uuid7();
                DB::table('payment_accounts')->insert([
                    'id' => $accountId,
                    'type' => $attributes['type'],
                    'name' => fake()->name(),
                    'phone_number' => fake()->numerify('09#########'),
                    'user_id' => $attributes['payment_user_id'],
                    'payment_method_id' => $attributes['payment_method_id'],
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $accountId;
            },
            'approver_user_id' => null,
            'approved_at' => null,
            'status' => 'pending',
        ];
    }
}
