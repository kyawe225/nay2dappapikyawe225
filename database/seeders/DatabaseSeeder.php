<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();



        $this->call(RoleSeeder::class);

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'test@example.com',
            'password'=>Hash::make("password"),
            'role_id'=>Role::where('name',"Super Admin")->first()->id
        ]);
    }
}
