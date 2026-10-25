<?php

use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Support;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->sole()->id]);
    Sanctum::actingAs($this->administrator, ['access']);
});

test('dedicated support type controller validates and supports the complete CRUD flow', function (): void {
    $this->postJson('/api/admin/support-types', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'imageLocation']);
    $id = $this->postJson('/api/admin/support-types', ['name' => 'Test support type', 'imageLocation' => 'support/test.png'])->assertOk()->json('data.id');
    $this->getJson('/api/admin/support-types?search=Test')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/admin/support-types/'.$id)->assertOk()->assertJsonPath('data.name', 'Test support type');
    $this->patchJson('/api/admin/support-types/'.$id, ['name' => 'Updated support type'])->assertOk()->assertJsonPath('data.name', 'Updated support type');
    $this->getJson('/api/master')->assertOk()->assertJsonPath('data.support_types.0.id', $id);
    $this->deleteJson('/api/admin/support-types/'.$id)->assertOk();
    $this->getJson('/api/admin/support-types/'.$id)->assertNotFound();
    $this->assertDatabaseMissing('support_types', ['id' => $id]);
});

test('support types in use cannot be deleted and legacy master routes still work', function (): void {
    $id = $this->postJson('/api/admin/master/support-types', ['name' => 'In use', 'imageLocation' => 'support/used.png'])->assertOk()->json('data.id');
    Support::query()->create(['name' => 'Test support', 'support_type_id' => $id, 'type' => 'phone', 'value' => '0990000000', 'status' => 'active']);
    $this->deleteJson('/api/admin/support-types/'.$id)->assertConflict();
    $this->getJson('/api/admin/master/support-types')->assertOk()->assertJsonPath('data.pagination.total', 1);
});

test('dedicated payment method controller validates and supports providers such as KBZPay and WavePay', function (): void {
    $this->postJson('/api/admin/payment-methods', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'description']);
    $id = $this->postJson('/api/admin/payment-methods', ['name' => 'KBZPay', 'type' => 'deposit', 'description' => 'Test provider', 'status' => 'active'])->assertOk()->json('data.id');
    $this->getJson('/api/admin/payment-methods?search=KBZPay')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/admin/payment-methods/'.$id)->assertOk()->assertJsonPath('data.name', 'KBZPay');
    $this->patchJson('/api/admin/payment-methods/'.$id, ['name' => 'WavePay', 'description' => 'Updated provider'])->assertOk()->assertJsonPath('data.name', 'WavePay');
    $this->getJson('/api/admin/master/payment-methods')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->deleteJson('/api/admin/payment-methods/'.$id)->assertOk();
    $this->getJson('/api/admin/payment-methods/'.$id)->assertNotFound();
});

test('payment method type and deletion protections are shared with the legacy controller', function (): void {
    $method = PaymentMethod::factory()->create(['type' => 'deposit', 'status' => 'active']);
    PaymentAccount::query()->create(['user_id' => $this->administrator->id, 'payment_method_id' => $method->id, 'type' => 'deposit', 'user_name' => 'Test admin', 'phone_number' => '0990000000', 'status' => 'active']);
    $this->patchJson('/api/admin/payment-methods/'.$method->id, ['type' => 'withdraw'])->assertConflict();
    $this->deleteJson('/api/admin/payment-methods/'.$method->id)->assertConflict();
});

test('customers cannot access dedicated administrator master endpoints', function (): void {
    $customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->sole()->id]);
    Sanctum::actingAs($customer, ['access']);
    foreach (['support-types', 'payment-methods'] as $resource) {
        $this->getJson('/api/admin/'.$resource)->assertForbidden();
        $this->postJson('/api/admin/'.$resource, [])->assertForbidden();
    }
});
