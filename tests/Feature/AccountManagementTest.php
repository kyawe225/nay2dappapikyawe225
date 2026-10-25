<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordRecoveryNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{customer: User, administrator: User, staff: User} */
function accountManagementScenario(): array
{
    test()->seed(RoleSeeder::class);

    return [
        'customer' => User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]),
        'administrator' => User::factory()->create(['role_id' => Role::query()->where('name', 'Super Admin')->sole()->id]),
        'staff' => User::factory()->create(['role_id' => Role::query()->where('name', 'Staff')->sole()->id]),
    ];
}

test('profile updates ignore privileged account fields and do not expose password data', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = accountManagementScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/profile')->assertOk()->assertJsonMissingPath('data.password');
    $this->patchJson('/api/profile', ['name' => 'Updated customer', 'roleId' => $administrator->role_id, 'status' => 'suspended'])->assertOk()->assertJsonPath('data.name', 'Updated customer');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Updated customer', 'role_id' => $customer->role_id, 'status' => 'active']);
});

test('changing a profile email requires the current password and invalidates old reset tokens', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Password::createToken($customer);
    Sanctum::actingAs($customer, ['access']);

    $this->patchJson('/api/profile', ['email' => 'changed@example.com', 'currentPassword' => 'wrong'])->assertBadRequest();
    $this->patchJson('/api/profile', ['email' => 'changed@example.com', 'currentPassword' => 'password'])->assertOk()->assertJsonPath('data.email', 'changed@example.com');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'email' => 'changed@example.com', 'email_verified_at' => null]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->email]);
});

test('password changes check the old password and revoke all token pairs', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    $customer->createToken('access', ['access']);
    $customer->createToken('refresh', ['refresh']);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/profile/password', ['currentPassword' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertBadRequest();
    $this->assertDatabaseCount('personal_access_tokens', 2);
    $this->postJson('/api/profile/password', ['currentPassword' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();

    expect(Hash::check('new-password', $customer->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('password recovery uses expiring single use tokens and does not reveal whether an email exists', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Notification::fake();
    $customer->createToken('existing-access', ['access']);

    $knownResponse = $this->postJson('/api/auth/forgot-password', ['email' => $customer->email])->assertOk()->json();
    $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->assertExactJson($knownResponse);
    $token = null;
    Notification::assertSentTo($customer, PasswordRecoveryNotification::class, function (PasswordRecoveryNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    $reset = ['email' => $customer->email, 'token' => $token, 'password' => 'recovered-password', 'password_confirmation' => 'recovered-password'];
    $this->postJson('/api/auth/reset-password', $reset)->assertOk();
    $this->postJson('/api/auth/reset-password', $reset)->assertBadRequest();

    expect(Hash::check('recovered-password', $customer->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('suspended accounts cannot log in use existing access or refresh tokens', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = accountManagementScenario();
    $customer->createToken('access', ['access']);
    $customer->createToken('refresh', ['refresh']);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/accounts/'.$customer->id, ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->postJson('/api/auth/login', ['email' => $customer->email, 'password' => 'password'])->assertForbidden();
    Sanctum::actingAs($customer->fresh(), ['access']);
    $this->getJson('/api/profile')->assertForbidden();
    Sanctum::actingAs($customer->fresh(), ['refresh']);
    $this->postJson('/api/auth/refresh')->assertForbidden();
});

test('Super Admin can create staff list accounts and change a role with an audit record', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'staff' => $staff] = accountManagementScenario();
    Sanctum::actingAs($administrator, ['access']);

    $newStaffId = $this->postJson('/api/admin/accounts', ['name' => 'New Staff', 'email' => 'newstaff@example.com', 'password' => 'staff-password', 'roleId' => $staff->role_id])->assertOk()->assertJsonPath('data.role.name', 'Staff')->json('data.id');
    $this->patchJson('/api/admin/accounts/'.$customer->id, ['roleId' => $staff->role_id])->assertOk()->assertJsonPath('data.role.name', 'Staff');
    $this->getJson('/api/admin/accounts?search=newstaff')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $newStaffId);
    $this->getJson('/api/admin/roles')->assertOk()->assertJsonCount(5, 'data');

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'role_id' => $staff->role_id]);
    $this->assertDatabaseHas('admin_activities', ['action' => 'account.updated', 'user_id' => $administrator->id, 'target_id' => $customer->id], 'audit');
});

test('staff cannot grant roles or suspend accounts and a Super Admin cannot demote themselves', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'staff' => $staff] = accountManagementScenario();
    Sanctum::actingAs($staff, ['access']);

    $this->postJson('/api/admin/accounts', [])->assertForbidden();
    $this->patchJson('/api/admin/accounts/'.$customer->id, ['roleId' => $administrator->role_id])->assertForbidden();
    Sanctum::actingAs($administrator, ['access']);
    $this->patchJson('/api/admin/accounts/'.$administrator->id, ['roleId' => $customer->role_id])->assertForbidden();
    $this->patchJson('/api/admin/accounts/'.$administrator->id, ['status' => 'suspended'])->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $administrator->id, 'role_id' => $administrator->role_id, 'status' => 'active']);
});

test('inactive roles cannot log in and refresh only tokens cannot change profiles', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    Sanctum::actingAs($customer, ['refresh']);

    $this->patchJson('/api/profile', ['name' => 'Changed'])->assertForbidden();
    $customer->role->update(['status' => 'inactive']);
    $this->postJson('/api/auth/login', ['email' => $customer->email, 'password' => 'password'])->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => $customer->name]);
});

test('expired recovery tokens cannot reset passwords', function (): void {
    ['customer' => $customer] = accountManagementScenario();
    $this->freezeTime();
    $token = Password::createToken($customer);
    $this->travel(61)->minutes();

    $this->postJson('/api/auth/reset-password', ['email' => $customer->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertBadRequest();

    expect(Hash::check('password', $customer->fresh()->password))->toBeTrue();
});
