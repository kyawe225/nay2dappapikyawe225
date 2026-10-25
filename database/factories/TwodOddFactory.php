<?php

namespace Database\Factories;

use App\Models\TwodOdd;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TwodOdd> */
class TwodOddFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'twod odds', 'odd_number' => '85', 'start_date' => '2026-01-01', 'payout_mode' => 'total'];
    }
}
