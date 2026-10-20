<?php

namespace Database\Factories;

use App\Models\TwodDraw;
use App\Models\TwodResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TwodResult> */
class TwodResultFactory extends Factory
{
    /** @var class-string<TwodResult> */
    protected $model = TwodResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'draw_id' => TwodDraw::factory()->state(['status' => 'closed']),
            'number' => fake()->numerify('##'),
            'recorded_by' => null,
            'published_at' => now(),
        ];
    }
}
