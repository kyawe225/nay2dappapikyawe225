<?php

namespace Database\Factories;

use App\Models\ThreedDraw;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ThreedDraw> */
class ThreedDrawFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => null,
            'draw_date' => fake()->date(),
            'time_type' => fake()->randomElement(['am', 'pm']),
            'winning_number' => fake()->numerify('##'),
            'open_time' => '09:00',
            'cutoff_time' => '15:00',
        ];
    }
}
