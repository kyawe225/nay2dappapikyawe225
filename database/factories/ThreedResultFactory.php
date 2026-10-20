<?php

namespace Database\Factories;

use App\Models\ThreedDraw;
use App\Models\ThreedResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ThreedResult> */
class ThreedResultFactory extends Factory
{
    /** @var class-string<ThreedResult> */
    protected $model = ThreedResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'draw_id' => ThreedDraw::factory()->state(['status' => 'closed']),
            'number' => fake()->numerify('###'),
            'recorded_by' => null,
            'published_at' => now(),
        ];
    }
}
