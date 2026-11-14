<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Log::spy();
    Log::shouldReceive('channel')->with('http')->andReturnSelf();
});

test('web requests include request ids and structured completion logs', function (): void {
    $response = $this->get('/')->assertOk();
    $requestId = $response->headers->get('X-Request-ID');
    expect(Str::isUuid($requestId))->toBeTrue();
    Log::shouldHaveReceived('log')->with('info', 'HTTP request completed', Mockery::on(
        fn (array $context): bool => $context['request_id'] === $requestId && $context['category'] === 'web'
            && $context['path'] === '/' && $context['status_code'] === 200 && $context['duration_ms'] >= 0
    ))->once();
});

test('validation and authentication logs exclude bodies query strings and authorization headers', function (): void {
    $response = $this->withHeader('Authorization', 'Bearer private-bearer-value')
        ->postJson('/api/auth_login?token=private-query-value', ['email' => 'invalid', 'password' => 'private-password-value'])->assertUnprocessable();
    expect(Str::isUuid($response->headers->get('X-Request-ID')))->toBeTrue();
    Log::shouldHaveReceived('log')->with('warning', 'HTTP request completed', Mockery::on(function (array $context): bool {
        return $context['path'] === '/api/auth_login' && $context['status_code'] === 422
            && ! str_contains(json_encode($context), 'private-')
            && array_intersect(['body', 'headers', 'query'], array_keys($context)) === [];
    }))->once();
});

test('customer and anonymous requests do not enter the administrator audit tables', function (): void {
    $this->getJson('/api/admin/dashboard_show')->assertUnauthorized();
    $customer = User::factory()->create(['role_name' => 'Customer']);
    Sanctum::actingAs($customer, ['access']);
    $this->getJson('/api/admin/dashboard_show')->assertForbidden();
    $this->getJson('/api/admin/audit_logs_list')->assertForbidden();
    $this->getJson('/api/profile_show')->assertOk();
    $this->assertDatabaseCount('admin_api_audits', 0);
    $this->assertDatabaseCount('admin_activities', 0);
});

test('administrator request audits use the application database and can be filtered', function (): void {
    $administrator = User::factory()->create(['role_name' => 'Admin']);
    Sanctum::actingAs($administrator, ['access']);
    $success = $this->getJson('/api/admin/dashboard_show')->assertOk();
    $requestId = $success->headers->get('X-Request-ID');
    $this->getJson('/api/admin/audit_logs_list?requestId='.$requestId.'&method=GET&statusCode=200&actorUserId='.$administrator->id)
        ->assertOk()->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.request_id', $requestId)->assertJsonPath('data.items.0.user_id', $administrator->id)
        ->assertJsonPath('data.items.0.actorUser.id', $administrator->id)
        ->assertJsonPath('data.items.0.actorUser.role.roleName', 'Admin')
        ->assertJsonMissingPath('data.items.0.created_at');
    $this->getJson('/api/admin/audit_logs_list?perPage=101')->assertUnprocessable();
    expect(DB::connection()->table('admin_api_audits')->where('request_id', $requestId)->count())->toBe(1);
    $this->assertDatabaseCount('admin_api_audits', 3);
});

test('administrator errors have a correlation id and a separate audit record', function (): void {
    $administrator = User::factory()->create(['role_name' => 'Admin']);
    Sanctum::actingAs($administrator, ['access']);
    Route::get('/api/admin/logging-failure', function (): never {
        abort(500, 'Logging test failure');
    })->name('logging.failure');
    $response = $this->getJson('/api/admin/logging-failure')->assertInternalServerError();
    $requestId = $response->headers->get('X-Request-ID');
    expect(Str::isUuid($requestId))->toBeTrue();
    $this->assertDatabaseHas('admin_api_audits', ['request_id' => $requestId, 'status_code' => 500, 'route' => 'logging.failure']);
    Log::shouldHaveReceived('log')->with('error', 'HTTP request completed', Mockery::on(
        fn (array $context): bool => $context['request_id'] === $requestId && $context['status_code'] === 500
    ))->once();
});
