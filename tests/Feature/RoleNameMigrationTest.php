<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalDatabase = config('database.default');
    config(['database.connections.role_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'role_upgrade']);
    $migrationPaths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => ! str_starts_with(basename($path), '2026_11_12_085945_')));
    $this->artisan('migrate', ['--database' => 'role_upgrade', '--path' => $migrationPaths, '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
});

afterEach(function (): void {
    DB::purge('role_upgrade');
    config(['database.default' => $this->originalDatabase]);
});

test('role key migration preserves users and audit actor names', function (): void {
    $roleId = (string) Str::uuid7();
    $userId = (string) Str::uuid7();
    $now = now('UTC');
    DB::table('roles')->insert(['id' => $roleId, 'name' => 'Customer', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('users')->insert(['id' => $userId, 'name' => 'Existing Customer', 'email' => 'existing@example.test', 'password' => 'hashed', 'role_id' => $roleId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('admin_api_audits')->insert(['id' => (string) Str::uuid7(), 'request_id' => (string) Str::uuid7(), 'user_id' => $userId, 'actor_role_id' => $roleId, 'method' => 'GET', 'path' => '/api/user_current', 'status_code' => 200, 'duration_ms' => 1, 'created_at' => $now]);
    DB::table('admin_activities')->insert(['id' => (string) Str::uuid7(), 'user_id' => $userId, 'actor_role_id' => $roleId, 'action' => 'account.created', 'target_type' => 'users', 'target_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);

    $migration = require database_path('migrations/2026_11_12_085945_use_role_name_as_primary_key.php');
    $migration->up();

    $this->assertDatabaseHas('users', ['id' => $userId, 'role_name' => 'Customer']);
    $this->assertDatabaseHas('roles', ['role_name' => 'Customer', 'status' => 'active']);
    $this->assertDatabaseHas('admin_api_audits', ['user_id' => $userId, 'actor_role_name' => 'Customer']);
    $this->assertDatabaseHas('admin_activities', ['user_id' => $userId, 'actor_role_name' => 'Customer']);
    expect(Schema::hasColumn('roles', 'id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role_id'))->toBeFalse();
});
