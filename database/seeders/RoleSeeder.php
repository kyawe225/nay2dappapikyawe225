<?php

namespace Database\Seeders;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now(timezone: 'UTC');
        foreach (['Super Admin', 'Admin', 'Branch Manager', 'Staff', 'Customer'] as $roleName) {
            if (DB::table('roles')->where('role_name', $roleName)->exists()) {
                DB::table('roles')->where('role_name', $roleName)->update([
                    'status' => 'active',
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('roles')->insert([
                    'role_name' => $roleName,
                    'status' => 'active',
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
