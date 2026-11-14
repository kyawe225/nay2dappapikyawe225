<?php

namespace Database\Seeders;

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

        User::query()->firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Admin',
            'password' => Hash::make('password'),
            'role_name' => 'Super Admin',
        ]);

        $this->call(MasterDataSeeder::class);
        $this->call(CustomerPaymentHistorySeeder::class);
        $this->call(TwodOddSeeder::class);
        $this->call(ThreedOddSeeder::class);
    }
}
