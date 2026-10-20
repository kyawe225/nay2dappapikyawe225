<?php

namespace Database\Factories;

use App\Models\TwodEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TwodEntry> */
class TwodEntriesFactory extends Factory
{
    /** @var class-string<TwodEntry> */
    protected $model = TwodEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => null,
            'draw_date' => fake()->date(),
            'time' => fake()->randomElement(['am', 'pm']),
            'draw_number' => fake()->numerify('##'),
            'status' => 'active',
            'user_id' => User::factory(),
        ];
    }
}
