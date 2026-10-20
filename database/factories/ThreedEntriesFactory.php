<?php

namespace Database\Factories;

use App\Models\ThreedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ThreedEntry> */
class ThreedEntriesFactory extends Factory
{
    /** @var class-string<ThreedEntry> */
    protected $model = ThreedEntry::class;

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
