<?php

namespace Database\Factories;

use App\Models\ThreedOdd;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ThreedOdd> */
class ThreedOddFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'threed odds', 'odd_number' => '550', 'start_date' => '2026-01-01', 'payout_mode' => 'total'];
    }
}
