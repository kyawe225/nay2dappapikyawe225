<?php

namespace Database\Seeders;

use App\Models\TwodOdd;
use Illuminate\Database\Seeder;

class TwodOddSeeder extends Seeder
{
    public function run(): void
    {
        TwodOdd::query()->firstOrCreate(['start_date' => '2026-01-01'], [
            'name' => 'twod initial odds',
            'odd_number' => '85',
            'payout_mode' => null,
        ]);
    }
}
