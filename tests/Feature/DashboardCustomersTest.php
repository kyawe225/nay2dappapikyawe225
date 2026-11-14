<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('dashboard returns customer and active account counts with total wallet money', function (): void {
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create(['role_name' => 'Admin']);
    $activeCustomer = User::factory()->create(['role_name' => 'Customer', 'status' => 'active']);
    $suspendedCustomer = User::factory()->create(['role_name' => 'Customer', 'status' => 'suspended']);
    $activeCustomer->wallets()->create(['avaliable_balance' => '125.50', 'locked_balance' => '20.00', 'version' => (string) Str::uuid7()]);
    $suspendedCustomer->wallets()->create(['avaliable_balance' => '45.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/admin/dashboard_customers_summary')->assertOk()
        ->assertJsonPath('data.customerCount', 2)
        ->assertJsonPath('data.activeUserCount', 1)
        ->assertJsonPath('data.totalMemberMoney', '190.50')
        ->assertJsonMissingPath('data.customers')
        ->assertJsonMissingPath('data.activeUsers');
    $this->getJson('/api/admin/dashboard_show')->assertOk()
        ->assertJsonPath('data.customerCount', 2)
        ->assertJsonPath('data.activeUserCount', 1)
        ->assertJsonPath('data.totalMemberMoney', '190.50');
});

test('customer accounts cannot access the admin dashboard counts', function (): void {
    $this->seed(RoleSeeder::class);
    $customer = User::factory()->create(['role_name' => 'Customer']);
    Sanctum::actingAs($customer, ['access']);

    $this->getJson('/api/admin/dashboard_customers_summary')->assertForbidden();
});
