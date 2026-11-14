<?php

use App\Models\User;
use App\Notifications\PasswordRecoveryNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{customer: User, administrator: User, staff: User} */
function accountManagementScenario(): array
{
    test()->seed(RoleSeeder::class);

    return [
        'customer' => User::factory()->create(['role_name' => 'Customer']),
        'administrator' => User::factory()->create(['role_name' => 'Super Admin']),
        'staff' => User::factory()->create(['role_name' => 'Staff']),
    ];
}

test('profile updates ignore privileged account fields and do not expose password data', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = accountManagementScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/profile_show')->assertOk()->assertJsonMissingPath('data.password');
    $this->patchJson('/api/profile_update', ['name' => 'Updated customer', 'roleName' => $administrator->role_name, 'status' => 'suspended'])->assertOk()->assertJsonPath('data.name', 'Updated customer');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Updated customer', 'role_name' => $customer->role_name, 'status' => 'active']);
});

test('changing a profile email requires the current password and invalidates old reset tokens', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Password::createToken($customer);
    Sanctum::actingAs($customer, ['access']);

    $this->patchJson('/api/profile_update', ['email' => 'changed@example.com', 'currentPassword' => 'wrong'])->assertBadRequest();
    $this->patchJson('/api/profile_update', ['email' => 'changed@example.com', 'currentPassword' => 'password'])->assertOk()->assertJsonPath('data.email', 'changed@example.com');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'email' => 'changed@example.com', 'email_verified_at' => null]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->email]);
});

test('password changes check the old password and revoke all access tokens', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    $customer->createToken('access', ['access']);
    $customer->createToken('second-access', ['access']);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/profile_password_update', ['currentPassword' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertBadRequest();
    $this->assertDatabaseCount('personal_access_tokens', 2);
    $this->postJson('/api/profile_password_update', ['currentPassword' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();

    expect(Hash::check('new-password', $customer->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('password recovery uses expiring single use tokens and does not reveal whether an email exists', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Notification::fake();
    $customer->createToken('existing-access', ['access']);

    $knownResponse = $this->postJson('/api/auth_forgot_password', ['email' => $customer->email])->assertOk()->json();
    $this->postJson('/api/auth_forgot_password', ['email' => 'unknown@example.com'])->assertOk()->assertExactJson($knownResponse);
    $token = null;
    Notification::assertSentTo($customer, PasswordRecoveryNotification::class, function (PasswordRecoveryNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    $reset = ['email' => $customer->email, 'token' => $token, 'password' => 'recovered-password', 'password_confirmation' => 'recovered-password'];
    $this->postJson('/api/auth_reset_password', $reset)->assertOk();
    $this->postJson('/api/auth_reset_password', $reset)->assertBadRequest();

    expect(Hash::check('recovered-password', $customer->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('suspended accounts cannot log in or use existing access tokens', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = accountManagementScenario();
    $customer->createToken('access', ['access']);
    $customer->createToken('second-access', ['access']);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/accounts_update/'.$customer->id, ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->postJson('/api/auth_login', ['email' => $customer->email, 'password' => 'password'])->assertForbidden();
    Sanctum::actingAs($customer->fresh(), ['access']);
    $this->getJson('/api/profile_show')->assertForbidden();
    Sanctum::actingAs($customer->fresh(), ['access']);
    $this->getJson('/api/wallet_show')->assertForbidden();
});

test('Super Admin can create staff list accounts and change a role with an audit record', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'staff' => $staff] = accountManagementScenario();
    Sanctum::actingAs($administrator, ['access']);

    $newStaffId = $this->postJson('/api/admin/accounts_create', ['name' => 'New Staff', 'email' => 'newstaff@example.com', 'password' => 'staff-password', 'roleName' => $staff->role_name])->assertOk()->assertJsonPath('data.role.roleName', 'Staff')->json('data.id');
    $this->patchJson('/api/admin/accounts_update/'.$customer->id, ['roleName' => $staff->role_name])->assertOk()->assertJsonPath('data.role.roleName', 'Staff');
    $this->getJson('/api/admin/accounts_list?search=newstaff')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $newStaffId);
    $this->getJson('/api/admin/roles_list')->assertOk()->assertJsonCount(5, 'data');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'role_name' => $staff->role_name]);
    $this->assertDatabaseHas('admin_activities', ['action' => 'account.updated', 'user_id' => $administrator->id, 'target_id' => $customer->id]);
});

test('account list includes the current customer wallet and no wallet for administrators', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = accountManagementScenario();
    $customer->wallets()->create(['avaliable_balance' => '320.25', 'locked_balance' => '40.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($administrator, ['access']);

    $response = $this->getJson('/api/admin/accounts_list')->assertOk();

    $accounts = collect($response->json('data.items'))->keyBy('id');
    expect($accounts[$customer->id]['wallet']['avaliableBalance'])->toBe('320.25')
        ->and($accounts[$customer->id]['wallet']['user']['id'])->toBe($customer->id)
        ->and($accounts[$customer->id]['wallet']['lockedBalance'])->toBe('40.00')
        ->and($accounts[$customer->id]['currentWalletAmount'])->toBe('320.25')
        ->and($accounts[$administrator->id]['currentWalletAmount'])->toBeNull()
        ->and($accounts[$administrator->id]['wallet'])->toBeNull();
    $this->getJson('/api/admin/accounts_show/'.$customer->id)->assertOk()
        ->assertJsonPath('data.currentWalletAmount', '320.25');
});

test('staff cannot grant roles or suspend accounts and a Super Admin cannot demote themselves', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'staff' => $staff] = accountManagementScenario();
    Sanctum::actingAs($staff, ['access']);

    $this->postJson('/api/admin/accounts_create', [])->assertForbidden();
    $this->patchJson('/api/admin/accounts_update/'.$customer->id, ['roleName' => $administrator->role_name])->assertForbidden();
    Sanctum::actingAs($administrator, ['access']);
    $this->patchJson('/api/admin/accounts_update/'.$administrator->id, ['roleName' => $customer->role_name])->assertForbidden();
    $this->patchJson('/api/admin/accounts_update/'.$administrator->id, ['status' => 'suspended'])->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $administrator->id, 'role_name' => $administrator->role_name, 'status' => 'active']);
});

test('inactive roles cannot log in and unrelated ability tokens cannot change profiles', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Sanctum::actingAs($customer, ['other']);

    $this->patchJson('/api/profile_update', ['name' => 'Changed'])->assertForbidden();
    $customer->role->update(['status' => 'inactive']);
    $this->postJson('/api/auth_login', ['email' => $customer->email, 'password' => 'password'])->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => $customer->name]);
});

test('expired recovery tokens cannot reset passwords', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    $this->freezeTime();
    $token = Password::createToken($customer);
    $this->travel(61)->minutes();

    $this->postJson('/api/auth_reset_password', ['email' => $customer->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertBadRequest();

    expect(Hash::check('password', $customer->fresh()->password))->toBeTrue();
});
