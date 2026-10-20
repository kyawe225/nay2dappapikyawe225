<?php

use App\Models\PaymentMethod;
use App\Models\PaymentTransactions;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->customer = User::factory()->create(['role_id' => Role::query()->where('name', 'Customer')->firstOrFail()->id]);
    $this->administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Admin')->firstOrFail()->id]);
    $this->paymentMethod = PaymentMethod::factory()->create();
    $this->deposit = [
        'type' => 'deposit',
        'amount' => 5000,
        'payment_method_id' => $this->paymentMethod->id,
        'reference_number' => 'TRANSFER-12345',
        'client_reference' => (string) Str::uuid(),
    ];
    $this->withdrawal = [
        'type' => 'withdrawal',
        'amount' => 2000,
        'payment_method_id' => $this->paymentMethod->id,
        'account_name' => 'Customer Name',
        'account_number' => '09123456789',
        'client_reference' => (string) Str::uuid(),
    ];
});

test('wallet and payment routes require an access token', function (): void {
    $this->getJson('/api/wallet')->assertUnauthorized();
    $this->getJson('/api/payments')->assertUnauthorized();
    Sanctum::actingAs($this->customer, ['refresh']);
    $this->getJson('/api/wallet')->assertForbidden();
});

test('customers only see active payment methods', function (): void {
    PaymentMethod::factory()->inactive()->create();
    $deletedMethod = PaymentMethod::factory()->create();
    $deletedMethod->delete();
    Sanctum::actingAs($this->customer, ['access']);

    $this->getJson('/api/payment-methods')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->paymentMethod->id);
});

test('a deposit stays pending until an administrator approves it exactly once', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->deposit)->assertCreated()
        ->assertJsonPath('data.status', 'pending')->json('data.id');
    expect($this->customer->fresh()->balance)->toBe(0);
    $this->assertDatabaseCount('transactions', 0);

    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'approved'])->assertOk();
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'approved'])->assertOk();

    expect($this->customer->fresh()->balance)->toBe(5000);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseHas('transactions', ['payment_transaction_id' => $paymentId, 'type' => 'deposit', 'balance_before' => 0, 'balance_after' => 5000]);
    $this->assertDatabaseHas('payment_transactions', ['id' => $paymentId, 'reviewed_by' => $this->administrator->id]);
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'rejected', 'review_note' => 'Too late'])->assertConflict();
});

test('withdrawals reserve funds and approval does not deduct them again', function (): void {
    $this->customer->forceFill(['balance' => 10000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->withdrawal)->assertCreated()->json('data.id');
    expect($this->customer->fresh()->balance)->toBe(8000);

    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'approved'])->assertOk();
    expect($this->customer->fresh()->balance)->toBe(8000);
    $this->assertDatabaseCount('transactions', 1);
});

test('rejected withdrawals refund reserved funds once', function (): void {
    $this->customer->forceFill(['balance' => 10000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->withdrawal)->assertCreated()->json('data.id');

    Sanctum::actingAs($this->administrator, ['access']);
    $review = ['status' => 'rejected', 'review_note' => 'Account details need correction.'];
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', $review)->assertOk();
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', $review)->assertOk();

    expect($this->customer->fresh()->balance)->toBe(10000);
    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseHas('transactions', ['payment_transaction_id' => $paymentId, 'type' => 'withdrawal_refund', 'balance_before' => 8000, 'balance_after' => 10000]);
});

test('customers can cancel their pending withdrawal and receive a single refund', function (): void {
    $this->customer->forceFill(['balance' => 10000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->withdrawal)->assertCreated()->json('data.id');
    $this->postJson('/api/payments/'.$paymentId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson('/api/payments/'.$paymentId.'/cancel')->assertOk();

    expect($this->customer->fresh()->balance)->toBe(10000);
    $this->assertDatabaseCount('transactions', 2);
    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'approved'])->assertConflict();
});

test('withdrawal requests cannot overdraw a wallet', function (): void {
    $this->customer->forceFill(['balance' => 1000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments', $this->withdrawal)->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect($this->customer->fresh()->balance)->toBe(1000);
    $this->assertDatabaseCount('payment_transactions', 0);
    $this->assertDatabaseCount('transactions', 0);
});

test('a repeated client reference reuses the original request without reserving twice', function (): void {
    $this->customer->forceFill(['balance' => 10000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->withdrawal)->assertCreated()->json('data.id');
    $this->postJson('/api/payments', $this->withdrawal)->assertOk()->assertJsonPath('data.id', $paymentId);
    $this->postJson('/api/payments', [...$this->withdrawal, 'amount' => 3000])->assertConflict();
    expect($this->customer->fresh()->balance)->toBe(8000);
    $this->assertDatabaseCount('payment_transactions', 1);
    $this->assertDatabaseCount('transactions', 1);
});

test('the same deposit transfer reference cannot be claimed twice', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments', $this->deposit)->assertCreated();
    Sanctum::actingAs($this->administrator, ['access']);
    $this->postJson('/api/payments', [...$this->deposit, 'client_reference' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('reference_number');
    $this->assertDatabaseCount('payment_transactions', 1);
});

test('customers cannot read cancel or list another customers payments', function (): void {
    $otherPayment = PaymentTransactions::factory()->create();
    Sanctum::actingAs($this->customer, ['access']);
    $this->getJson('/api/payments/'.$otherPayment->id)->assertNotFound();
    $this->postJson('/api/payments/'.$otherPayment->id.'/cancel')->assertNotFound();
    $this->getJson('/api/payments?user_id='.$otherPayment->user_id)->assertOk()->assertJsonCount(0, 'data');
});

test('customers cannot access administrator payment routes', function (): void {
    $payment = PaymentTransactions::factory()->create();
    Sanctum::actingAs($this->customer, ['access']);
    $this->getJson('/api/admin/payments')->assertForbidden();
    $this->getJson('/api/admin/transactions')->assertForbidden();
    $this->postJson('/api/admin/payment-methods', [])->assertForbidden();
    $this->patchJson('/api/admin/payments/'.$payment->id.'/status', ['status' => 'approved'])->assertForbidden();
    expect($payment->fresh()->status)->toBe('pending');
});

test('an inactive administrator role cannot review payments', function (): void {
    $this->administrator->role->update(['status' => 'inactive']);
    Sanctum::actingAs($this->administrator, ['access']);
    $this->getJson('/api/admin/payments')->assertForbidden();
});

test('payment amounts must be positive whole units', function (mixed $amount): void {
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments', [...$this->deposit, 'amount' => $amount])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
})->with([0, -1, 1.5, 1000000001]);

test('inactive and deleted payment methods cannot accept new requests', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $this->paymentMethod->update(['status' => 'inactive']);
    $this->postJson('/api/payments', $this->deposit)->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
    $this->paymentMethod->delete();
    $this->postJson('/api/payments', $this->deposit)->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
});

test('administrators manage payment methods without losing payment history', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->deposit)->assertCreated()->json('data.id');
    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payment-methods/'.$this->paymentMethod->id, ['instructions' => 'Updated instructions.'])->assertOk();
    $this->deleteJson('/api/admin/payment-methods/'.$this->paymentMethod->id)->assertOk();
    $this->getJson('/api/admin/payments/'.$paymentId)->assertOk()
        ->assertJsonPath('data.payment_method.id', $this->paymentMethod->id);
});

test('wallet history contains only the current customers balance changes', function (): void {
    $this->customer->forceFill(['balance' => 10000])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments', $this->withdrawal)->assertCreated();
    $this->getJson('/api/wallet')->assertOk()->assertJsonPath('data.balance', 8000)->assertJsonPath('data.currency', 'MMK');
    $this->getJson('/api/wallet/transactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'withdrawal');
    Sanctum::actingAs($this->administrator, ['access']);
    $this->getJson('/api/wallet/transactions?user_id='.$this->customer->id)->assertOk()->assertJsonCount(0, 'data');
});

test('admin payment filters and pagination are validated', function (): void {
    Sanctum::actingAs($this->administrator, ['access']);
    $this->getJson('/api/admin/payments?per_page=101')->assertUnprocessable();
    $this->getJson('/api/admin/payments?status=unknown')->assertUnprocessable();
    $this->getJson('/api/admin/payments?from_date=2026-10-15&to_date=2026-10-01')->assertUnprocessable();
    $this->getJson('/api/admin/payments?to_date=2026-10-15')->assertOk();
});

test('payment submissions ignore owner status and reviewer fields supplied by a customer', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments', [
        ...$this->deposit,
        'user_id' => $this->administrator->id,
        'status' => 'approved',
        'reviewed_by' => $this->administrator->id,
    ])->assertCreated()->assertJsonPath('data.user_id', $this->customer->id)
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reviewed_by', null);
    expect($this->customer->fresh()->balance)->toBe(0);
});

test('rejected deposits cannot credit a wallet or be cancelled afterwards', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $paymentId = $this->postJson('/api/payments', $this->deposit)->assertCreated()->json('data.id');
    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'rejected'])
        ->assertUnprocessable()->assertJsonValidationErrors('review_note');
    $this->patchJson('/api/admin/payments/'.$paymentId.'/status', ['status' => 'rejected', 'review_note' => 'Transfer not found.'])->assertOk();
    Sanctum::actingAs($this->customer, ['access']);
    $this->postJson('/api/payments/'.$paymentId.'/cancel')->assertConflict();
    expect($this->customer->fresh()->balance)->toBe(0);
    $this->assertDatabaseCount('transactions', 0);
});

test('new payment methods return their default active status', function (): void {
    Sanctum::actingAs($this->administrator, ['access']);
    $this->postJson('/api/admin/payment-methods', [
        'name' => 'Bank Transfer',
        'account_name' => 'Business Account',
        'account_number' => '01234567890',
    ])->assertCreated()->assertJsonPath('data.status', 'active');
});

test('deposit approval preserves space for pending withdrawal refunds', function (): void {
    $this->customer->forceFill(['balance' => 9007199254740991])->save();
    Sanctum::actingAs($this->customer, ['access']);
    $withdrawalId = $this->postJson('/api/payments', $this->withdrawal)->assertCreated()->json('data.id');
    $depositId = $this->postJson('/api/payments', [...$this->deposit, 'amount' => 2000])->assertCreated()->json('data.id');
    Sanctum::actingAs($this->administrator, ['access']);
    $this->patchJson('/api/admin/payments/'.$depositId.'/status', ['status' => 'approved'])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->assertDatabaseHas('payment_transactions', ['id' => $depositId, 'status' => 'pending']);
    $this->patchJson('/api/admin/payments/'.$withdrawalId.'/status', ['status' => 'rejected', 'review_note' => 'Refund requested.'])->assertOk();
    expect($this->customer->fresh()->balance)->toBe(9007199254740991);
    $this->assertDatabaseCount('transactions', 2);
});
