<?php

use App\Models\Role;
use App\Models\User;
use App\Service\AdminActivityService;
use App\Service\AuditDatabase;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->sole()->id]);
    $this->customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]);
});

test('audit storage uses the application connection and records administrator actions', function (): void {
    expect(app(AuditDatabase::class)->Connection())->toBe(DB::connection());

    app(AdminActivityService::class)->Record($this->administrator, 'test.action', 'users', $this->customer->id);

    $this->assertDatabaseCount('admin_activities', 1);
    $this->assertDatabaseHas('admin_activities', ['user_id' => $this->administrator->id, 'actor_role_id' => $this->administrator->role_id]);
});

test('business auditing ignores customers and excludes rolled back administrator actions', function (): void {
    $activities = app(AdminActivityService::class);
    $activities->Record($this->customer, 'customer.action', 'users', $this->customer->id);
    $this->assertDatabaseCount('admin_activities', 0);

    expect(fn () => DB::transaction(function () use ($activities): void {
        $activities->Record($this->administrator, 'rolled.back', 'users', $this->customer->id);
        throw new RuntimeException('Roll back the business operation');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('admin_activities', 0);

    DB::transaction(fn () => $activities->Record($this->administrator, 'committed', 'users', $this->customer->id));
    $this->assertDatabaseHas('admin_activities', ['action' => 'committed']);
    $this->assertDatabaseCount('admin_activities', 1);
});

test('audit storage follows the configured default connection', function (): void {
    $original = config('database.default');
    config(['database.connections.alternate' => config('database.connections.sqlite'), 'database.default' => 'alternate']);
    try {
        expect(app(AuditDatabase::class)->Connection())->toBe(DB::connection('alternate'));
    } finally {
        DB::purge('alternate');
        config(['database.default' => $original]);
    }
});

test('audit setup is idempotent and preserves existing application history', function (): void {
    app(AdminActivityService::class)->Record($this->administrator, 'existing.action', 'users', $this->customer->id);

    $this->artisan('audit:setup', ['--no-interaction' => true])->assertExitCode(0);
    $this->artisan('audit:setup', ['--no-interaction' => true])->assertExitCode(0);

    $this->assertDatabaseCount('admin_activities', 1);
    $this->assertDatabaseHas('admin_activities', ['action' => 'existing.action']);
    $this->assertDatabaseCount('users', 2);
});
