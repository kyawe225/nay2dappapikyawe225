<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->customer = User::factory()->create([
        'role_id' => Role::query()->where('name', 'Customer')->firstOrFail()->id,
    ]);
});

test('authentication requests validate required fields', function (): void {
    $this->postJson('/api/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $this->postJson('/api/auth/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('invalid credentials return an unauthorized http status', function (): void {
    $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'incorrect-password'])
        ->assertUnauthorized()->assertJsonPath('status', 401);
});

test('registration assigns the customer role and ignores privileged fields', function (): void {
    $this->postJson('/api/auth/register', [
        'name' => 'New Customer',
        'email' => 'new-customer@example.com',
        'password' => 'secure-password',
        'role_id' => Role::query()->where('name', 'Admin')->firstOrFail()->id,
        'balance' => 1000000,
    ])->assertOk();

    $user = User::query()->where('email', 'new-customer@example.com')->firstOrFail();
    $wallet = $user->wallets()->sole();
    expect($user->role->name)->toBe('Customer')
        ->and($wallet->avaliable_balance)->toBe('0.00')
        ->and($wallet->locked_balance)->toBe('0.00')
        ->and(Str::isUuid($wallet->version))->toBeTrue();
    $this->postJson('/api/auth/register', [
        'name' => 'Duplicate', 'email' => $user->email, 'password' => 'secure-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($user->wallets()->count())->toBe(1)->and($user->wallets()->sole()->id)->toBe($wallet->id);
});

test('registration rolls back the customer if wallet creation fails', function (): void {
    $dispatcher = Wallet::getEventDispatcher();
    Wallet::setEventDispatcher(clone $dispatcher);
    Wallet::creating(function (Wallet $wallet): never {
        throw new RuntimeException('Wallet creation failed');
    });
    try {
        expect(fn () => $this->withoutExceptionHandling()->postJson('/api/auth/register', [
            'name' => 'Failed Customer', 'email' => 'failed-customer@example.test', 'password' => 'customer-password',
        ]))->toThrow(RuntimeException::class, 'Wallet creation failed');
    } finally {
        Wallet::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseMissing('users', ['email' => 'failed-customer@example.test']);
    $this->assertDatabaseCount('wallet', 0);
});

test('the database prevents a second wallet for the same customer', function (): void {
    $this->postJson('/api/auth/register', ['name' => 'One Wallet', 'email' => 'one-wallet@example.test', 'password' => 'customer-password'])->assertOk();
    $customer = User::query()->where('email', 'one-wallet@example.test')->sole();
    expect(fn () => $customer->wallets()->create(['avaliable_balance' => '0.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]))
        ->toThrow(QueryException::class);
    expect($customer->wallets()->count())->toBe(1);
});

test('login returns tokens and the users role without exposing the password', function (): void {
    $response = $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.user.role.name', 'Customer');
    expect($response->json('data.access_token'))->toBeString()
        ->and($response->json('data.refresh_token'))->toBeString()
        ->and($response->json('data.user'))->not->toHaveKey('password');
});

test('refresh rotates both tokens and makes the old pair unusable', function (): void {
    $tokens = $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $replacement = $this->withToken($tokens['refresh_token'])->postJson('/api/auth/refresh')->assertOk()->json('data');
    expect($this->customer->tokens()->count())->toBe(2);

    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['refresh_token'])->postJson('/api/auth/refresh')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/user')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($replacement['access_token'])->getJson('/api/user')->assertOk();
});

test('access and refresh tokens cannot be used interchangeably', function (): void {
    $tokens = $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $this->withToken($tokens['access_token'])->postJson('/api/auth/refresh')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokens['refresh_token'])->getJson('/api/wallet')->assertForbidden();
});

test('logout revokes only the current session token pair', function (): void {
    $firstSession = $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $secondSession = $this->postJson('/api/auth/login', ['email' => $this->customer->email, 'password' => 'password'])->assertOk()->json('data');
    $this->withToken($firstSession['access_token'])->postJson('/api/auth/logout')->assertOk();
    expect($this->customer->tokens()->count())->toBe(2);

    $this->app['auth']->forgetGuards();
    $this->withToken($firstSession['refresh_token'])->postJson('/api/auth/refresh')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($secondSession['access_token'])->getJson('/api/user')->assertOk();
});

test('expired refresh tokens cannot rotate a session', function (): void {
    $token = $this->customer->createToken('refresh-token:'.Str::uuid(), ['refresh'], now()->subMinute());
    $this->withToken($token->plainTextToken)->postJson('/api/auth/refresh')->assertUnauthorized();
    expect($this->customer->tokens()->count())->toBe(1);
});
