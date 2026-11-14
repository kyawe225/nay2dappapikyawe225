<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    /** @return array{role_name: string, status: string} */
    public function definition(): array
    {
        return ['role_name' => fake()->unique()->word(), 'status' => 'active'];
    }
}
