<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use Database\Seeders\PaymentAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PaymentAccountSeeder::class);
    $this->administrator = User::query()->where('email', 'test@example.com')->sole();
    $this->customer = User::query()->where('email', 'customer1@example.com')->sole();
    $this->otherCustomer = User::query()->where('email', 'customer2@example.com')->sole();
    $this->wallet = $this->customer->wallets()->create(['avaliable_balance' => '1000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $this->walletPath = '/api/admin/accounts_wallet_show/'.$this->customer->id;
    $this->depositAccount = $this->administrator->paymentAccounts()->firstOrFail();
    $this->withdrawalAccount = $this->customer->paymentAccounts()->firstOrFail();
    Sanctum::actingAs($this->administrator, ['access']);
});

test('wallet history includes all payment and withdrawal states even without ledger movements', function (): void {
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath))->assertOk()
        ->assertJsonPath('data.wallet.id', $this->wallet->id)
        ->assertJsonPath('data.transactions.pagination.total', 0)
        ->assertJsonPath('data.withdrawalRequests.pagination.total', 0)
        ->assertJsonPath('data.walletTransactions.pagination.total', 0);
    foreach (['pending', 'approved', 'rejected'] as $status) {
        Transaction::factory()->create(['payment_user_id' => $this->customer->id, 'status' => $status]);
    }
    foreach (['requested', 'approved', 'declined'] as $status) {
        WithdrawRequest::query()->create([
            'requested_payment_user_id' => $this->customer->id, 'reference_id' => (string) Str::uuid7(),
            'amount' => '10.00', 'status' => $status,
            'requested_payment_account_id' => $this->withdrawalAccount->id,
            'requested_payment_method_id' => $this->withdrawalAccount->payment_method_id,
        ]);
    }
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath))->assertOk()
        ->assertJsonPath('data.transactions.pagination.total', 3)
        ->assertJsonPath('data.withdrawalRequests.pagination.total', 3)
        ->assertJsonPath('data.walletTransactions.pagination.total', 0);
});

test('wallet detail contains payment withdrawal and movement history while old account history is removed', function (): void {
    $transaction = Transaction::factory()->create([
        'payment_user_id' => $this->customer->id,
        'payment_accounts_id' => $this->depositAccount->id,
        'payment_method_id' => $this->depositAccount->payment_method_id,
    ]);
    $withdrawal = WithdrawRequest::query()->create([
        'requested_payment_user_id' => $this->customer->id, 'reference_id' => (string) Str::uuid7(),
        'amount' => '10.00', 'status' => 'requested',
        'requested_payment_account_id' => $this->withdrawalAccount->id,
        'requested_payment_method_id' => $this->withdrawalAccount->payment_method_id,
    ]);
    $movement = $this->wallet->transactions()->create([
        'type' => 'manual_adjustment', 'direction' => 'in', 'amount' => '5.00',
        'balance_before' => '1000.00', 'balance_after' => '1005.00',
        'reference_type' => 'admin_adjustment', 'reference_id' => (string) Str::uuid7(),
        'idempotency_key' => (string) Str::uuid7(),
    ]);

    $this->getJson($this->walletPath)->assertOk()
        ->assertJsonPath('data.id', $this->wallet->id)
        ->assertJsonPath('data.created_at', $this->wallet->created_at->toISOString())
        ->assertJsonMissingPath('data.updated_at')
        ->assertJsonPath('data.paymentTransactions.0.id', $transaction->id)
        ->assertJsonPath('data.paymentTransactions.0.created_at', $transaction->created_at->toISOString())
        ->assertJsonMissingPath('data.paymentTransactions.0.paymentUser.created_at')
        ->assertJsonPath('data.paymentTransactions.0.paymentUser.id', $this->customer->id)
        ->assertJsonPath('data.withdrawalRequests.0.id', $withdrawal->id)
        ->assertJsonPath('data.withdrawalRequests.0.requested_at', $withdrawal->created_at->toISOString())
        ->assertJsonMissingPath('data.withdrawalRequests.0.created_at')
        ->assertJsonPath('data.withdrawalRequests.0.requestedPaymentAccount.id', $this->withdrawalAccount->id)
        ->assertJsonPath('data.walletTransactions.0.id', $movement->id)
        ->assertJsonPath('data.walletTransactions.0.created_at', $movement->created_at->toISOString())
        ->assertJsonPath('data.walletTransactions.0.wallet.id', $this->wallet->id)
        ->assertJsonMissingPath('data.paymentTransactions.0.paymentUserId')
        ->assertJsonMissingPath('data.walletTransactions.0.walletId');
    $this->getJson('/api/admin/accounts/'.$this->customer->id.'/history')->assertNotFound();
});

test('wallet payment detail and ledger detail trace an approved deposit in both directions', function (): void {
    $transaction = Transaction::factory()->create([
        'payment_user_id' => $this->customer->id, 'payment_accounts_id' => $this->depositAccount->id,
        'payment_method_id' => $this->depositAccount->payment_method_id, 'amount' => '25.50',
    ]);
    $this->patchJson('/api/admin/payment_deposit_status_update/'.$transaction->id, ['status' => 'approved'])->assertOk();
    $movement = $this->wallet->transactions()->sole();
    $this->getJson(str_replace('wallet_show', 'wallet_transaction_show', $this->walletPath).'/'.$transaction->id)->assertOk()
        ->assertJsonPath('data.transaction.status', 'approved')
        ->assertJsonPath('data.transaction.approved_at', $transaction->fresh()->approved_at->toISOString())
        ->assertJsonPath('data.transaction.paymentUser.id', $this->customer->id)
        ->assertJsonPath('data.transaction.paymentAccount.id', $this->depositAccount->id)
        ->assertJsonPath('data.walletTransactions.0.id', $movement->id)
        ->assertJsonPath('data.walletTransactions.0.wallet.id', $this->wallet->id)
        ->assertJsonPath('data.walletTransactions.0.wallet.user.id', $this->customer->id)
        ->assertJsonCount(0, 'data.withdrawalRequests');
    $this->getJson(str_replace('wallet_show', 'wallet_movement_show', $this->walletPath).'/'.$movement->id)->assertOk()
        ->assertJsonPath('data.transaction.id', $transaction->id)
        ->assertJsonPath('data.withdrawalRequest', null)
        ->assertJsonPath('data.walletTransaction.balanceBefore', '1000.00')
        ->assertJsonPath('data.walletTransaction.balanceAfter', '1025.50');
});

test('wallet withdrawal details trace pending approved and declined requests without double debit', function (string $status): void {
    Sanctum::actingAs($this->customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', [
        'amount' => '10.00', 'paymentAccountId' => $this->withdrawalAccount->id,
        'paymentMethodId' => $this->withdrawalAccount->payment_method_id, 'clientReference' => (string) Str::uuid7(),
    ])->assertOk()->json('data.id');
    Sanctum::actingAs($this->administrator, ['access']);
    if ($status !== 'requested') {
        $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawalId, ['status' => $status, 'lastSixTranId' => '012345'])->assertOk();
    }
    $detail = $this->getJson(str_replace('wallet_show', 'wallet_withdrawal_show', $this->walletPath).'/'.$withdrawalId)->assertOk()
        ->assertJsonPath('data.withdrawalRequest.status', $status)
        ->assertJsonCount($status === 'declined' ? 2 : 1, 'data.walletTransactions');
    $transactionId = $detail->json('data.transaction.id');
    if ($status === 'approved') {
        expect($transactionId)->not->toBeNull();
        $this->getJson(str_replace('wallet_show', 'wallet_transaction_show', $this->walletPath).'/'.$transactionId)->assertOk()
            ->assertJsonPath('data.withdrawalRequests.0.id', $withdrawalId)
            ->assertJsonCount(1, 'data.walletTransactions');
    } else {
        $detail->assertJsonPath('data.transaction', null);
    }
    foreach ($this->wallet->transactions()->get() as $movement) {
        $this->getJson(str_replace('wallet_show', 'wallet_movement_show', $this->walletPath).'/'.$movement->id)->assertOk()
            ->assertJsonPath('data.withdrawalRequest.id', $withdrawalId)
            ->assertJsonPath('data.transaction.id', $transactionId);
    }
    $this->assertDatabaseHas('wallet', [
        'id' => $this->wallet->id, 'avaliable_balance' => $status === 'declined' ? '1000.00' : '990.00',
        'locked_balance' => $status === 'requested' ? '10.00' : '0.00',
    ]);
})->with(['requested', 'approved', 'declined']);

test('wallet history pages independently and filters status movement references and dates', function (): void {
    $transactionIds = $withdrawalIds = $movementIds = [];
    foreach ([1, 2, 3] as $day) {
        $createdAt = '2026-10-0'.$day.' 12:00:00';
        $transactionIds[] = Transaction::factory()->create([
            'payment_user_id' => $this->customer->id, 'created_at' => $createdAt,
            'status' => $day === 2 ? 'pending' : 'approved',
        ])->id;
        $withdrawalIds[] = WithdrawRequest::query()->create([
            'requested_payment_user_id' => $this->customer->id, 'reference_id' => (string) Str::uuid7(),
            'amount' => '10.00', 'status' => $day === 2 ? 'declined' : 'requested', 'created_at' => $createdAt,
            'requested_payment_account_id' => $this->withdrawalAccount->id,
            'requested_payment_method_id' => $this->withdrawalAccount->payment_method_id,
        ])->id;
        $movement = $this->wallet->transactions()->create([
            'type' => 'manual_adjustment', 'direction' => $day === 2 ? 'out' : 'in', 'amount' => '1.00',
            'balance_before' => '1000.00', 'balance_after' => $day === 2 ? '999.00' : '1001.00',
            'reference_type' => 'admin_adjustment', 'reference_id' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(), 'created_at' => $createdAt,
        ]);
        $movement->forceFill(['created_at' => $createdAt])->save();
        $movementIds[] = $movement->id;
    }
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath).'?perPage=1&transactionsPage=2&withdrawalRequestsPage=3&walletTransactionsPage=2')->assertOk()
        ->assertJsonPath('data.transactions.items.0.id', $transactionIds[1])
        ->assertJsonPath('data.transactions.pagination.currentPage', 2)
        ->assertJsonPath('data.transactions.pagination.lastPage', 3)
        ->assertJsonPath('data.withdrawalRequests.items.0.id', $withdrawalIds[0])
        ->assertJsonPath('data.withdrawalRequests.pagination.currentPage', 3)
        ->assertJsonPath('data.walletTransactions.items.0.id', $movementIds[1]);
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath).'?transactionType=deposit&transactionStatus=pending&withdrawalStatus=declined&movementType=manual_adjustment&direction=out')->assertOk()
        ->assertJsonPath('data.transactions.pagination.total', 1)
        ->assertJsonPath('data.transactions.items.0.id', $transactionIds[1])
        ->assertJsonPath('data.withdrawalRequests.pagination.total', 1)
        ->assertJsonPath('data.withdrawalRequests.items.0.id', $withdrawalIds[1])
        ->assertJsonPath('data.walletTransactions.pagination.total', 1)
        ->assertJsonPath('data.walletTransactions.items.0.id', $movementIds[1]);
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath).'?fromDate=2026-10-02&toDate=2026-10-02')->assertOk()
        ->assertJsonPath('data.transactions.pagination.total', 1)
        ->assertJsonPath('data.withdrawalRequests.pagination.total', 1)
        ->assertJsonPath('data.walletTransactions.pagination.total', 1);
    $movement = $this->wallet->transactions()->findOrFail($movementIds[0]);
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath).'?referenceType=admin_adjustment&referenceId='.$movement->reference_id)->assertOk()
        ->assertJsonPath('data.walletTransactions.pagination.total', 1)
        ->assertJsonPath('data.walletTransactions.items.0.id', $movement->id);
});

test('manual adjustment detail exposes actor reason balances and retry reference', function (): void {
    $reference = (string) Str::uuid7();
    $id = $this->patchJson(str_replace('wallet_show', 'wallet_adjust', $this->walletPath), ['direction' => 'in', 'amount' => '7.25', 'reason' => 'Balance correction', 'clientReference' => $reference])->assertOk()->json('data.transaction.id');
    $this->getJson(str_replace('wallet_show', 'wallet_movement_show', $this->walletPath).'/'.$id)->assertOk()
        ->assertJsonPath('data.walletTransaction.actorUser.id', $this->administrator->id)
        ->assertJsonMissingPath('data.walletTransaction.actorUserId')
        ->assertJsonPath('data.walletTransaction.reason', 'Balance correction')
        ->assertJsonPath('data.walletTransaction.clientReference', $reference)
        ->assertJsonPath('data.walletTransaction.balanceAfter', '1007.25')
        ->assertJsonPath('data.transaction', null)->assertJsonPath('data.withdrawalRequest', null);
});

test('wallet record details cannot disclose records belonging to another customer', function (): void {
    $transaction = Transaction::factory()->create(['payment_user_id' => $this->customer->id]);
    $movement = $this->wallet->transactions()->create([
        'type' => 'deposit', 'direction' => 'in', 'amount' => '1.00', 'balance_before' => '0.00', 'balance_after' => '1.00',
        'reference_type' => 'transaction', 'reference_id' => $transaction->id, 'idempotency_key' => (string) Str::uuid7(),
    ]);
    $withdrawal = WithdrawRequest::query()->create([
        'requested_payment_user_id' => $this->customer->id, 'reference_id' => (string) Str::uuid7(), 'amount' => '10.00',
        'requested_payment_account_id' => $this->withdrawalAccount->id, 'requested_payment_method_id' => $this->withdrawalAccount->payment_method_id,
    ]);
    $this->otherCustomer->wallets()->create(['avaliable_balance' => '0.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $otherPath = '/api/admin/accounts_wallet_show/'.$this->otherCustomer->id;
    foreach (['transactions/'.$transaction->id, 'withdrawal-requests/'.$withdrawal->id, 'wallet-transactions/'.$movement->id] as $suffix) {
        $this->getJson('/api/admin/accounts_wallet_'.(str_starts_with($suffix, 'transactions') ? 'transaction_show' : (str_starts_with($suffix, 'withdrawal-requests') ? 'withdrawal_show' : 'movement_show')).'/'.$this->otherCustomer->id.'/'.substr($suffix, strpos($suffix, '/') + 1))->assertNotFound();
    }
    $this->getJson(str_replace('wallet_show', 'wallet_history', $otherPath).'?paymentUserId='.$this->customer->id)->assertOk()
        ->assertJsonPath('data.transactions.pagination.total', 0)
        ->assertJsonPath('data.withdrawalRequests.pagination.total', 0)
        ->assertJsonPath('data.walletTransactions.pagination.total', 0);
    $foreignTransaction = Transaction::factory()->create(['payment_user_id' => $this->otherCustomer->id]);
    $movement->update(['reference_id' => $foreignTransaction->id]);
    $this->getJson(str_replace('wallet_show', 'wallet_movement_show', $this->walletPath).'/'.$movement->id)->assertOk()->assertJsonPath('data.transaction', null);
});

test('wallet history and detail endpoints require active administrator access', function (): void {
    $paths = [
        str_replace('wallet_show', 'wallet_history', $this->walletPath),
        str_replace('wallet_show', 'wallet_transaction_show', $this->walletPath).'/'.Str::uuid7(),
        str_replace('wallet_show', 'wallet_withdrawal_show', $this->walletPath).'/'.Str::uuid7(),
        str_replace('wallet_show', 'wallet_movement_show', $this->walletPath).'/'.Str::uuid7(),
    ];
    Sanctum::actingAs($this->customer, ['access']);
    foreach ($paths as $path) {
        $this->getJson($path)->assertForbidden();
    }
    $this->app['auth']->forgetGuards();
    foreach ($paths as $path) {
        $this->getJson($path)->assertUnauthorized();
    }
});

test('wallet history validates filter and independent pagination inputs', function (string $query, string $field): void {
    $this->getJson(str_replace('wallet_show', 'wallet_history', $this->walletPath).'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['perPage=101', 'perPage'], ['transactionsPage=0', 'transactionsPage'],
    ['withdrawalRequestsPage=-1', 'withdrawalRequestsPage'], ['walletTransactionsPage=abc', 'walletTransactionsPage'],
    ['fromDate=2026-10-03&toDate=2026-10-02', 'toDate'], ['transactionType=unknown', 'transactionType'],
    ['transactionStatus=active', 'transactionStatus'], ['withdrawalStatus=rejected', 'withdrawalStatus'],
    ['movementType=unknown', 'movementType'], ['direction=credit', 'direction'],
]);

test('wallet history rejects nonexistent accounts administrator targets and customers without a wallet', function (): void {
    foreach ([(string) Str::uuid7(), $this->administrator->id, $this->otherCustomer->id] as $userId) {
        $path = '/api/admin/accounts_wallet_show/'.$userId;
        $this->getJson(str_replace('wallet_show', 'wallet_history', $path))->assertNotFound();
        $this->getJson(str_replace('wallet_show', 'wallet_transaction_show', $path).'/'.Str::uuid7())->assertNotFound();
        $this->getJson(str_replace('wallet_show', 'wallet_withdrawal_show', $path).'/'.Str::uuid7())->assertNotFound();
        $this->getJson(str_replace('wallet_show', 'wallet_movement_show', $path).'/'.Str::uuid7())->assertNotFound();
    }
});
