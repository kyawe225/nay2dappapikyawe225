<?php

namespace Database\Seeders;

use App\Models\ThreedOdd;
use Illuminate\Database\Seeder;

class ThreedOddSeeder extends Seeder
{
    public function run(): void
    {
        ThreedOdd::query()->firstOrCreate(['start_date' => '2026-01-01'], [
            'name' => 'threed initial odds',
            'odd_number' => '550',
            'payout_mode' => null,
        ]);
    }
}
