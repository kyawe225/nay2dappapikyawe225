<?php

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->customer = User::factory()->create([
        'role_name' => 'Customer',
    ]);
});

test('authentication requests validate required fields', function (): void {
    $this->postJson('/api/auth_login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $this->postJson('/api/auth_register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('invalid credentials return an unauthorized http status', function (): void {
    $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'incorrect-password'])
        ->assertUnauthorized()->assertJsonPath('status', 401);
});

test('registration assigns the customer role and ignores privileged fields', function (): void {
    $this->postJson('/api/auth_register', [
        'name' => 'New Customer',
        'email' => 'new-customer@example.com',
        'password' => 'secure-password',
        'role_name' => 'Admin',
        'balance' => 1000000,
    ])->assertOk();

    $user = User::query()->where('email', 'new-customer@example.com')->firstOrFail();
    $wallet = $user->wallets()->sole();
    expect($user->role->role_name)->toBe('Customer')
        ->and($wallet->avaliable_balance)->toBe('0.00')
        ->and($wallet->locked_balance)->toBe('0.00')
        ->and(Str::isUuid($wallet->version))->toBeTrue();
    $this->postJson('/api/auth_register', [
        'name' => 'Duplicate', 'email' => $user->email, 'password' => 'secure-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($user->wallets()->count())->toBe(1)->and($user->wallets()->sole()->id)->toBe($wallet->id);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role_name' => 'Customer']);
    expect(Schema::hasColumn('roles', 'id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role_id'))->toBeFalse();
});

test('registration rolls back the customer if wallet creation fails', function (): void {
    $dispatcher = Wallet::getEventDispatcher();
    Wallet::setEventDispatcher(clone $dispatcher);
    Wallet::creating(function (Wallet $wallet): never {
        throw new RuntimeException('Wallet creation failed');
    });
    try {
        expect(fn () => $this->withoutExceptionHandling()->postJson('/api/auth_register', [
            'name' => 'Failed Customer', 'email' => 'failed-customer@example.test', 'password' => 'customer-password',
        ]))->toThrow(RuntimeException::class, 'Wallet creation failed');
    } finally {
        Wallet::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseMissing('users', ['email' => 'failed-customer@example.test']);
    $this->assertDatabaseCount('wallet', 0);
});

test('the database prevents a second wallet for the same customer', function (): void {
    $this->postJson('/api/auth_register', ['name' => 'One Wallet', 'email' => 'one-wallet@example.test', 'password' => 'customer-password'])->assertOk();
    $customer = User::query()->where('email', 'one-wallet@example.test')->sole();
    expect(fn () => $customer->wallets()->create(['avaliable_balance' => '0.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]))
        ->toThrow(QueryException::class);
    expect($customer->wallets()->count())->toBe(1);
});

test('login returns one access token and the users role without exposing the password', function (): void {
    $response = $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.user.role.role_name', 'Customer');
    $response->assertJsonPath('data.user.role_name', 'Customer');
    expect($response->json('data.access_token'))->toBeString()
        ->and($response->json('data'))->not->toHaveKey('refresh_token');
    expect($response->json('data.user'))->not->toHaveKey('password');
    expect($this->customer->tokens()->count())->toBe(1);
});

test('the removed refresh endpoint returns 404 and creates no token', function (): void {
    $tokens = $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $this->withToken($tokens['access_token'])->postJson('/api/auth/refresh')->assertNotFound();
    expect($this->customer->tokens()->count())->toBe(1);
});

test('access tokens expire after 30 days', function (): void {
    $this->freezeTime();
    $tokens = $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    expect($this->customer->tokens()->sole()->expires_at->toDateTimeString())->toBe(now('UTC')->addDays(30)->toDateTimeString());
    $this->travel(31)->days();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/user_current')->assertUnauthorized();
});

test('logout revokes only the current access token', function (): void {
    $firstSession = $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $secondSession = $this->postJson('/api/auth_login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $this->withToken($firstSession['access_token'])->postJson('/api/auth_logout')->assertOk();
    expect($this->customer->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $this->withToken($firstSession['access_token'])->getJson('/api/user_current')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($secondSession['access_token'])->getJson('/api/user_current')->assertOk();
});

test('tokens without access ability cannot use protected routes', function (): void {
    $token = $this->customer->createToken('other-token', ['other'], now()->addDay());
    $this->withToken($token->plainTextToken)->getJson('/api/wallet_show')->assertForbidden();
    expect($this->customer->tokens()->count())->toBe(1);
});
