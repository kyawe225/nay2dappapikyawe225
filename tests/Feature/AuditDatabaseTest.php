<?php

use App\Models\Role;
use App\Models\User;
use App\Service\AdminActivityService;
use App\Service\AuditDatabase;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->sole()->id]);
    $this->customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]);
});

test('audit storage has its own connection and schema without application foreign keys', function (): void {
    $audit = app(AuditDatabase::class)->Connection();
    expect($audit->getPdo())->not->toBe(DB::connection()->getPdo())
        ->and(Schema::connection('audit')->hasTable('admin_activities'))->toBeTrue()
        ->and(Schema::connection('audit')->hasTable('users'))->toBeFalse();

    app(AdminActivityService::class)->Record($this->administrator, 'test.action', 'users', $this->customer->id);
    $this->assertDatabaseCount('admin_activities', 1, 'audit');
    $this->assertDatabaseCount('admin_activities', 0);
    $this->assertDatabaseHas('admin_activities', ['user_id' => $this->administrator->id, 'actor_role_id' => $this->administrator->role_id], 'audit');
});

test('business auditing ignores customers and excludes rolled back administrator actions', function (): void {
    $activities = app(AdminActivityService::class);
    $activities->Record($this->customer, 'customer.action', 'users', $this->customer->id);
    $this->assertDatabaseCount('admin_activities', 0, 'audit');

    expect(fn () => DB::transaction(function () use ($activities): void {
        $activities->Record($this->administrator, 'rolled.back', 'users', $this->customer->id);
        throw new RuntimeException('Roll back the business operation');
    }))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('admin_activities', 0, 'audit');

    DB::transaction(fn () => $activities->Record($this->administrator, 'committed', 'users', $this->customer->id));
    $this->assertDatabaseHas('admin_activities', ['action' => 'committed'], 'audit');
    $this->assertDatabaseCount('admin_activities', 0);
});

test('the audit connection cannot be used as the application default', function (): void {
    $default = config('database.default');
    config(['database.default' => 'audit']);
    try {
        expect(fn () => app(AuditDatabase::class)->Connection())->toThrow(LogicException::class);
    } finally {
        config(['database.default' => $default]);
    }
});

test('audit setup imports administrator legacy history idempotently and preserves source records', function (): void {
    $auditPath = tempnam(sys_get_temp_dir(), '2dapp-audit-');
    $original = config('database.connections.audit');
    config(['database.connections.audit.database' => $auditPath]);
    DB::purge('audit');

    $administratorActivity = (string) Str::uuid7();
    foreach ([$this->administrator, $this->customer] as $actor) {
        DB::table('admin_activities')->insert([
            'id' => $actor->id === $this->administrator->id ? $administratorActivity : (string) Str::uuid7(),
            'user_id' => $actor->id, 'action' => 'legacy.action', 'target_type' => 'users',
            'target_id' => $this->customer->id, 'details' => '{}', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('admin_api_audits')->insert([
            'id' => (string) Str::uuid7(), 'request_id' => (string) Str::uuid7(), 'user_id' => $actor->id,
            'actor_role_id' => $actor->role_id, 'method' => 'GET', 'path' => '/api/admin/dashboard',
            'status_code' => 200, 'duration_ms' => 1, 'created_at' => now('UTC'),
        ]);
    }
    try {
        $this->artisan('audit:setup', ['--import-legacy' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->artisan('audit:setup', ['--import-legacy' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->assertDatabaseCount('admin_activities', 1, 'audit');
        $this->assertDatabaseCount('admin_api_audits', 1, 'audit');
        $this->assertDatabaseHas('admin_activities', ['id' => $administratorActivity], 'audit');
        $this->assertDatabaseMissing('admin_api_audits', ['user_id' => $this->customer->id], 'audit');
        $this->assertDatabaseCount('admin_activities', 2);
        $this->assertDatabaseCount('admin_api_audits', 2);
    } finally {
        DB::purge('audit');
        config(['database.connections.audit' => $original]);
        unlink($auditPath);
    }
});
