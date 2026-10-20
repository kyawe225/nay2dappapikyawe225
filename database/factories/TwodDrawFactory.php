<?php

namespace Database\Factories;

use App\Models\TwodDraw;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TwodDraw> */
class TwodDrawFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => null,
            'draw_date' => fake()->date(),
            'time_type' => fake()->randomElement(['am', 'pm']),
            'winning_number' => fake()->numerify('##'),

        ];
    }
}
