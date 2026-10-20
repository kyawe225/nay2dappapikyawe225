<?php

use App\Models\MasterDataVersion;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Support;
use App\Models\SupportType;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(MasterDataSeeder::class);
});

test('master seeding is repeatable and keeps configured payment settings', function (): void {
    $paymentMethod = PaymentMethod::query()->firstOrFail();
    $paymentMethod->update(['description' => 'Configured payment instructions', 'open_time' => '08:30']);
    $this->seed(MasterDataSeeder::class);
    $version = MasterDataVersion::query()->sole()->version;
    $this->seed(MasterDataSeeder::class);

    expect(Role::query()->count())->toBe(0)
        ->and(PaymentMethod::query()->where('type', 'deposit')->count())->toBe(2)
        ->and(PaymentMethod::query()->where('type', 'withdraw')->count())->toBe(2)
        ->and(SupportType::query()->count())->toBe(3)
        ->and(Support::query()->count())->toBe(3)
        ->and(MasterDataVersion::query()->sole()->version)->toBe($version)
        ->and($paymentMethod->fresh()->description)->toBe('Configured payment instructions')
        ->and($paymentMethod->fresh()->open_time)->toBe('08:30');
});

test('master version changes when seeded master content changes', function (): void {
    $version = MasterDataVersion::query()->sole()->version;
    PaymentMethod::query()->firstOrFail()->update(['description' => 'New instructions']);
    $this->seed(MasterDataSeeder::class);

    expect(MasterDataVersion::query()->sole()->version)->not->toBe($version);
});

test('master seeding leaves existing users and roles unchanged', function (): void {
    Role::factory()->create(['name' => 'Existing Role', 'status' => 'inactive']);
    $user = User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create();
    $originalUser = $user->fresh()->getAttributes();
    $originalRoles = Role::query()->orderBy('id')->get()->toArray();

    $this->seed(MasterDataSeeder::class);
    $this->seed(MasterDataSeeder::class);

    expect($user->fresh()->getAttributes())->toBe($originalUser)
        ->and(Role::query()->orderBy('id')->get()->toArray())->toBe($originalRoles);
});
test('master endpoint returns seeded data and skips unchanged versions', function (): void {
    Sanctum::actingAs(User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create(), ['access']);
    $version = MasterDataVersion::query()->sole()->version;
    $this->getJson('/api/master')->assertOk()
        ->assertJsonCount(2, 'data.deposit_payment_methods')
        ->assertJsonCount(2, 'data.withdrawal_payment_methods')
        ->assertJsonCount(3, 'data.support_types')
        ->assertJsonPath('data.master_version', $version);
    $this->getJson('/api/master?version='.$version)->assertOk()->assertJsonPath('data', null);
});

test('master endpoint requires authentication and validates the version', function (): void {
    $this->getJson('/api/master')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create(), ['access']);
    $this->getJson('/api/master?version[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('version');
});
