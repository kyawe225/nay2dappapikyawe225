<?php

use App\Http\ServiceContractor\IMasterService;
use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\Support;
use App\Models\SupportType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_name' => 'Admin']);
    Sanctum::actingAs($this->administrator, ['access']);
});

test('dedicated support type controller validates and supports the complete CRUD flow', function (): void {
    $this->postJson('/api/admin/support_types_create', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'imageLocation']);
    $id = $this->post('/api/admin/support_types_create', ['name' => 'Test support type', 'imageLocation' => UploadedFile::fake()->image('test.png')], ['Accept' => 'application/json'])->assertOk()->json('data.id');
    $this->getJson('/api/admin/support_types_list?search=Test')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/admin/support_types_show/'.$id)->assertOk()->assertJsonPath('data.name', 'Test support type');
    $this->patchJson('/api/admin/support_types_update/'.$id, ['name' => 'Updated support type'])->assertOk()->assertJsonPath('data.name', 'Updated support type');
    $this->getJson('/api/master_list')->assertOk()->assertJsonPath('data.support_types.0.id', $id);
    $this->deleteJson('/api/admin/support_types_delete/'.$id)->assertOk();
    $this->getJson('/api/admin/support_types_show/'.$id)->assertNotFound();
    $this->assertDatabaseMissing('support_types', ['id' => $id]);
});

test('support types in use cannot be deleted and legacy master routes still work', function (): void {
    $id = $this->post('/api/admin/master_create/support-types', ['name' => 'In use', 'imageLocation' => UploadedFile::fake()->image('used.png')], ['Accept' => 'application/json'])->assertOk()->json('data.id');
    Support::query()->create(['name' => 'Test support', 'support_type_id' => $id, 'type' => 'phone', 'value' => '0990000000', 'status' => 'active']);
    $this->deleteJson('/api/admin/support_types_delete/'.$id)->assertConflict();
    $this->getJson('/api/admin/master_list/support-types')->assertOk()->assertJsonPath('data.pagination.total', 1);
});

test('dedicated payment method controller validates and supports providers such as KBZPay and WavePay', function (): void {
    $this->postJson('/api/admin/payment_methods_create', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'description']);
    $id = $this->postJson('/api/admin/payment_methods_create', ['name' => 'KBZPay', 'type' => 'deposit', 'description' => 'Test provider', 'status' => 'active'])->assertOk()
        ->assertJsonMissingPath('data.user')
        ->json('data.id');
    $this->getJson('/api/admin/payment_methods_list?search=KBZPay')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->getJson('/api/admin/payment_methods_show/'.$id)->assertOk()->assertJsonPath('data.name', 'KBZPay');
    $this->patchJson('/api/admin/payment_methods_update/'.$id, ['name' => 'WavePay', 'description' => 'Updated provider'])->assertOk()->assertJsonPath('data.name', 'WavePay');
    $this->getJson('/api/admin/master_list/payment-methods')->assertOk()->assertJsonPath('data.pagination.total', 1);
    $this->deleteJson('/api/admin/payment_methods_delete/'.$id)->assertOk();
    $this->getJson('/api/admin/payment_methods_show/'.$id)->assertNotFound();
});

test('payment method responses omit deposit account owner profiles', function (): void {
    $method = PaymentMethod::factory()->create(['type' => 'deposit', 'status' => 'active']);
    $account = PaymentAccount::query()->create([
        'user_id' => $this->administrator->id, 'payment_method_id' => $method->id,
        'type' => 'deposit', 'user_name' => 'Receiving account', 'phone_number' => '0990000000', 'status' => 'active',
    ]);

    $this->patchJson('/api/admin/payment_methods_update/'.$method->id, ['name' => 'Updated provider'])->assertOk()
        ->assertJsonPath('data.paymentAccounts.0.id', $account->id)
        ->assertJsonPath('data.paymentAccounts.0.userName', 'Receiving account')
        ->assertJsonMissingPath('data.paymentAccounts.0.user');
    $this->getJson('/api/admin/payment_methods_show/'.$method->id)->assertOk()
        ->assertJsonMissingPath('data.paymentAccounts.0.user');
    $this->getJson('/api/admin/payment_methods_list')->assertOk()
        ->assertJsonMissingPath('data.items.0.paymentAccounts.0.user');

    $customer = User::factory()->create(['role_name' => 'Customer']);
    Sanctum::actingAs($customer, ['access']);
    $this->getJson('/api/payment_method_deposit_list')->assertOk()
        ->assertJsonPath('data.0.paymentAccounts.0.id', $account->id)
        ->assertJsonMissingPath('data.0.paymentAccounts.0.user');
});

test('payment method type and deletion protections are shared with the legacy controller', function (): void {
    $method = PaymentMethod::factory()->create(['type' => 'deposit', 'status' => 'active']);
    PaymentAccount::query()->create(['user_id' => $this->administrator->id, 'payment_method_id' => $method->id, 'type' => 'deposit', 'user_name' => 'Test admin', 'phone_number' => '0990000000', 'status' => 'active']);
    $this->patchJson('/api/admin/payment_methods_update/'.$method->id, ['type' => 'withdraw'])->assertConflict();
    $this->deleteJson('/api/admin/payment_methods_delete/'.$method->id)->assertConflict();
});

test('customers cannot access dedicated administrator master endpoints', function (): void {
    $customer = User::factory()->create(['role_name' => 'Customer']);
    Sanctum::actingAs($customer, ['access']);
    foreach (['support-types', 'payment-methods'] as $resource) {
        $this->getJson('/api/admin/'.str_replace('-', '_', $resource).'_list')->assertForbidden();
        $this->postJson('/api/admin/'.str_replace('-', '_', $resource).'_create', [])->assertForbidden();
    }
});

test('master images are uploaded, returned as URLs, retained and replaced', function (string $resource, string $model, string $column, string $responseField): void {
    $payload = ['name' => 'Upload test', 'imageLocation' => UploadedFile::fake()->image('icon.png')];
    if ($resource === 'payment-methods') {
        $payload += ['type' => 'deposit', 'description' => 'Provider'];
    }
    $response = $this->post('/api/admin/'.str_replace('-', '_', $resource).'_create', $payload, ['Accept' => 'application/json'])->assertOk();
    $record = $model::findOrFail($response->json('data.id'));
    $originalPath = $record->{$column};
    Storage::disk('public')->assertExists($originalPath);
    $response->assertJsonPath('data.'.$responseField, Storage::disk('public')->url($originalPath));

    $this->patchJson('/api/admin/'.str_replace('-', '_', $resource).'_update/'.$record->id, ['name' => 'Renamed'])->assertOk();
    expect($record->refresh()->{$column})->toBe($originalPath);

    $response = $this->post('/api/admin/master_update/'.$resource.'/'.$record->id, [
        '_method' => 'PATCH', 'imageLocation' => UploadedFile::fake()->image('replacement.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();
    $replacementPath = $record->refresh()->{$column};
    expect($replacementPath)->not->toBe($originalPath);
    Storage::disk('public')->assertExists($replacementPath);
    $response->assertJsonPath('data.'.$responseField, Storage::disk('public')->url($replacementPath));
})->with([
    ['support-types', SupportType::class, 'image_location', 'imageLocation'],
    ['payment-methods', PaymentMethod::class, 'payment_method_image_location', 'paymentMethodImage'],
]);

test('master images reject paths, non images and oversized files', function (string $resource): void {
    foreach (['images/icon.png', UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('large.png')->size(5121)] as $invalidImage) {
        $this->post('/api/admin/'.str_replace('-', '_', $resource).'_create', [
            'name' => 'Invalid upload', 'type' => 'deposit', 'description' => 'Provider', 'imageLocation' => $invalidImage,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('imageLocation');
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['support-types', 'payment-methods']);

test('failed image storage does not create a master record', function (): void {
    Storage::shouldReceive('disk')->with('public')->andReturnSelf();
    Storage::shouldReceive('putFileAs')->once()->andReturn(false);
    $this->post('/api/admin/support_types_create', [
        'name' => 'Failed upload', 'imageLocation' => UploadedFile::fake()->image('icon.png'),
    ], ['Accept' => 'application/json'])->assertStatus(500)->assertJsonPath('message', 'Image could not be saved. Please try again!');
    $this->assertDatabaseMissing('support_types', ['name' => 'Failed upload']);
});

test('failed master save removes the new upload and retains the existing image', function (): void {
    $id = $this->post('/api/admin/support_types_create', [
        'name' => 'Original', 'imageLocation' => UploadedFile::fake()->image('original.png'),
    ], ['Accept' => 'application/json'])->assertOk()->json('data.id');
    $supportType = SupportType::findOrFail($id);
    $originalPath = $supportType->image_location;
    $this->mock(IMasterService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('RefreshVersion')->once()->andThrow(new RuntimeException('Simulated save failure'));
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->post('/api/admin/support_types_update/'.$supportType->id, [
        '_method' => 'PATCH', 'imageLocation' => UploadedFile::fake()->image('replacement.png'),
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Simulated save failure');
    expect($supportType->refresh()->image_location)->toBe($originalPath)
        ->and(Storage::disk('public')->allFiles())->toBe([$originalPath]);
});
