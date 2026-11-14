<?php

use App\Http\ServiceContractor\IWalletService;
use App\Models\User;
use App\Service\AdminActivityService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->administrator = User::factory()->create(['role_name' => 'Admin']);
    $this->postJson('/api/auth_register', ['name' => 'Wallet Customer', 'email' => 'wallet-customer@example.test', 'password' => 'customer-password'])->assertOk();
    $this->customer = User::query()->where('email', 'wallet-customer@example.test')->sole();
    $this->walletPath = '/api/admin/accounts_wallet_show/'.$this->customer->id;
    Sanctum::actingAs($this->administrator, ['access']);
});

test('registration creates one customer wallet and administrator accounts have none', function (): void {
    $wallet = $this->customer->wallets()->sole();
    expect($wallet->avaliable_balance)->toBe('0.00')
        ->and($wallet->locked_balance)->toBe('0.00')
        ->and(Str::isUuid($wallet->version))->toBeTrue()
        ->and($this->administrator->wallets()->count())->toBe(0);

    $this->getJson($this->walletPath)->assertOk()->assertJsonPath('data.id', $wallet->id);
    $this->postJson(str_replace('wallet_show', 'wallet_create', $this->walletPath), ['reason' => 'Duplicate wallet'])->assertConflict();
    $adminPath = '/api/admin/accounts_wallet_show/'.$this->administrator->id;
    $this->getJson($adminPath)->assertNotFound();
    $this->postJson(str_replace('wallet_show', 'wallet_create', $adminPath), ['reason' => 'Admin wallets are not allowed'])->assertNotFound();
    expect($this->administrator->wallets()->count())->toBe(0)->and($this->customer->wallets()->count())->toBe(1);
});

test('customers cannot manage wallets or open another one', function (): void {
    Sanctum::actingAs($this->customer, ['access']);
    $this->getJson($this->walletPath)->assertForbidden();
    $this->postJson(str_replace('wallet_show', 'wallet_create', $this->walletPath), ['reason' => 'New wallet'])->assertForbidden();
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), ['reason' => 'Adjustment'])->assertForbidden();
    $this->deleteJson(str_replace('wallet_show', 'wallet_delete', $this->walletPath), ['reason' => 'Delete wallet'])->assertForbidden();
    $this->postJson('/api/wallet_show', [])->assertStatus(405);
    expect($this->customer->wallets()->count())->toBe(1);
});

test('new administrator accounts do not receive a wallet', function (string $roleName): void {
    $superAdministrator = User::factory()->create(['role_name' => 'Super Admin']);
    Sanctum::actingAs($superAdministrator, ['access']);
    $userId = $this->postJson('/api/admin/accounts_create', [
        'name' => 'New administrator', 'email' => 'new-admin@example.test', 'password' => 'administrator-password',
        'roleName' => $roleName,
    ])->assertOk()->json('data.id');
    $this->assertDatabaseMissing('wallet', ['user_id' => $userId]);
    $this->postJson('/api/admin/accounts_wallet_create/'.$userId, ['reason' => 'No admin wallets'])->assertNotFound();
})->with(['Super Admin', 'Admin', 'Staff']);

test('manual credits and debits are traced and retry without another movement', function (): void {
    $credit = ['direction' => 'in', 'amount' => '100.25', 'reason' => 'Customer service correction', 'clientReference' => (string) Str::uuid7()];
    $first = $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $credit)->assertOk()
        ->assertJsonPath('data.wallet.avaliableBalance', '100.25')
        ->assertJsonPath('data.transaction.type', 'manual_adjustment')
        ->assertJsonPath('data.transaction.actorUser.id', $this->administrator->id)
        ->assertJsonPath('data.transaction.reason', $credit['reason'])
        ->assertJsonPath('data.transaction.clientReference', $credit['clientReference'])
        ->assertJsonPath('data.transaction.balanceBefore', '0.00')
        ->assertJsonPath('data.transaction.balanceAfter', '100.25')->json('data.transaction.id');
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $credit)->assertOk()->assertJsonPath('data.transaction.id', $first);
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$credit, 'amount' => '101.25'])->assertConflict();
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$credit, 'reason' => 'Different reason'])->assertConflict();
    $this->assertDatabaseCount('wallet_transactions', 1);

    $debit = ['direction' => 'out', 'amount' => '30.25', 'reason' => 'Reverse part of correction', 'clientReference' => (string) Str::uuid7()];
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $debit)->assertOk()->assertJsonPath('data.wallet.avaliableBalance', '70.00');
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $debit)->assertOk()->assertJsonPath('data.wallet.avaliableBalance', '70.00');
    $this->assertDatabaseCount('wallet_transactions', 2);
    expect(DB::connection()->table('admin_activities')->where('action', 'wallet.adjusted')->count())->toBe(2);

    $activity = json_decode(DB::connection()->table('admin_activities')->where('action', 'wallet.adjusted')->first()->details, true);
    expect($activity)->toMatchArray(['transactionId' => $first, 'reason' => $credit['reason'], 'balanceBefore' => '0.00', 'balanceAfter' => '100.25']);
    Sanctum::actingAs($this->customer, ['access']);
    $this->getJson('/api/wallet_history?perPage=1')->assertOk()->assertJsonCount(2, 'data.items')->assertJsonMissingPath('data.pagination');
    $traces = $this->getJson('/api/wallet_history')->assertOk()->json('data.items');
    expect(collect($traces)->pluck('reason')->all())->toContain($credit['reason'], $debit['reason']);
});

test('adjustments validate input and never spend locked funds or overflow the wallet', function (): void {
    $wallet = $this->customer->wallets()->sole();
    $wallet->update(['avaliable_balance' => '20.25', 'locked_balance' => '50.00']);
    $base = ['direction' => 'out', 'amount' => '21.00', 'reason' => 'Invalid correction', 'clientReference' => (string) Str::uuid7()];
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $base)->assertUnprocessable();
    foreach (['0', '-1', '1.001'] as $amount) {
        $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$base, 'amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$base, 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$base, 'clientReference' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('clientReference');
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$base, 'lockedBalance' => '0.00'])->assertUnprocessable()->assertJsonValidationErrors('lockedBalance');
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$base, 'direction' => 'in', 'amount' => str_repeat('9', 50).'.99'])->assertUnprocessable();
    expect($wallet->refresh()->avaliable_balance)->toBe('20.25')->and($wallet->locked_balance)->toBe('50.00');
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('only an empty wallet without history can be deleted and admins can recreate it', function (): void {
    $oldId = $this->customer->wallets()->sole()->id;
    $this->deleteJson(str_replace('wallet_show', 'wallet_delete', $this->walletPath), ['reason' => 'Reset empty test wallet'])->assertOk();
    expect($this->customer->wallets()->count())->toBe(0);
    $this->getJson($this->walletPath)->assertNotFound();
    Sanctum::actingAs($this->customer, ['access']);
    $this->getJson('/api/wallet_show')->assertOk()->assertJsonPath('data.version', null);
    expect($this->customer->wallets()->count())->toBe(0);
    $this->postJson('/api/wallet_show', [])->assertStatus(405);

    Sanctum::actingAs($this->administrator, ['access']);
    $this->postJson(str_replace('wallet_show', 'wallet_create', $this->walletPath), ['reason' => 'Recreate missing customer wallet'])->assertOk()->assertJsonPath('data.avaliableBalance', '0.00');
    expect($this->customer->wallets()->sole()->id)->not->toBe($oldId);
    $this->postJson(str_replace('wallet_show', 'wallet_create', $this->walletPath), ['reason' => 'Duplicate'])->assertConflict();
    $credit = ['direction' => 'in', 'amount' => '10', 'reason' => 'Credit', 'clientReference' => (string) Str::uuid7()];
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), $credit)->assertOk();
    $this->deleteJson(str_replace('wallet_show', 'wallet_delete', $this->walletPath), ['reason' => 'Has a balance'])->assertConflict();
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), [...$credit, 'direction' => 'out', 'clientReference' => (string) Str::uuid7()])->assertOk();
    $this->deleteJson(str_replace('wallet_show', 'wallet_delete', $this->walletPath), ['reason' => 'Has financial history'])->assertConflict();
    expect($this->customer->wallets()->count())->toBe(1);
});

test('missing wallets are not automatically reopened by financial services', function (): void {
    $this->customer->wallets()->delete();
    expect(fn () => app(IWalletService::class)->GetWallet($this->customer))
        ->toThrow(ModelNotFoundException::class);
    expect($this->customer->wallets()->count())->toBe(0);
});

test('manual adjustment rolls back if its business audit cannot be recorded', function (): void {
    $this->mock(AdminActivityService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('Record')->once()->andThrow(new RuntimeException('Audit unavailable'));
    });
    $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), ['direction' => 'in', 'amount' => '100', 'reason' => 'Must roll back', 'clientReference' => (string) Str::uuid7()])->assertInternalServerError();
    expect($this->customer->wallets()->sole()->avaliable_balance)->toBe('0.00');
    $this->assertDatabaseCount('wallet_transactions', 0);
    $this->assertDatabaseHas('admin_api_audits', ['user_id' => $this->administrator->id, 'status_code' => 500]);
});
