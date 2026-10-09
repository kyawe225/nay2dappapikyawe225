<?php

namespace Database\Seeders;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Str;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now(timezone: "UTC");
        $roleDefinitions = [
            [
                "id" => Str::uuid7(),
                "name" => "Super Admin",
                "status" => "active",
            ],
            [
                "id" => Str::uuid7(),
                "name" => "Admin",
                "status" => "active",
            ],
            [
                "id" => Str::uuid7(),
                "name" => "Branch Manager",
                "status" => "active",
            ],
            [
                "id" => Str::uuid7(),
                "name" => "Staff",
                "status" => "active",
            ],
            [
                "id" => Str::uuid7(),
                "name" => "Customer",
                "status" => "active",
            ],
        ];

        $roleIds = [];
        foreach ($roleDefinitions as $role) {
            $existing = DB::table("roles")
                ->where("name", $role["name"])
                ->first();

            if ($existing) {
                DB::table("roles")
                    ->where("id", $existing->id)
                    ->update([
                        "status" => $role["status"],
                        "deleted_at" => null,
                        "updated_at" => $now,
                    ]);
                $roleIds[$role["name"]] = (string) $existing->id;
            } else {
                DB::table("roles")->insert([
                    ...$role,
                    "deleted_at" => null,
                    "created_at" => $now,
                    "updated_at" => $now,
                ]);
                $roleIds[$role["name"]] = $role["id"];
            }
        }
    }
}
