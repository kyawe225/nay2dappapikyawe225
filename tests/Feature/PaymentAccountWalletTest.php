<?php

use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use Database\Seeders\PaymentAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{customer: User, administrator: User, depositAccount: PaymentAccount, withdrawalAccount: PaymentAccount} */
function paymentWalletScenario(bool $withWallet = false): array
{
    test()->seed(PaymentAccountSeeder::class);
    $customer = User::query()->where('email', 'customer1@example.com')->firstOrFail();
    $administrator = User::query()->where('email', 'test@example.com')->firstOrFail();
    if ($withWallet) {
        $customer->wallets()->create(['avaliable_balance' => '0.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    }

    return [
        'customer' => $customer,
        'administrator' => $administrator,
        'depositAccount' => $administrator->paymentAccounts()->firstOrFail(),
        'withdrawalAccount' => $customer->paymentAccounts()->firstOrFail(),
    ];
}

/** @return array{amount: string, paymentAccountId: string, paymentMethodId: string, clientReference: string} */
function withdrawalPayload(PaymentAccount $account): array
{
    return [
        'amount' => '2000.25',
        'paymentAccountId' => $account->id,
        'paymentMethodId' => $account->payment_method_id,
        'clientReference' => (string) Str::uuid7(),
    ];
}

test('explicit payment approval returns 401 without authentication', function (string $type): void {
    $this->postJson('/api/admin/payment_'.$type.'_approve/'.Str::uuid7())->assertUnauthorized();

    $this->assertDatabaseCount('wallet_transactions', 0);
})->with(['deposit', 'withdrawal']);

test('explicit payment approval returns 403 for customers without changing requests or funds', function (): void {
    ['customer' => $customer, 'depositAccount' => $depositAccount, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $transaction = Transaction::factory()->create(['payment_user_id' => $customer->id, 'payment_accounts_id' => $depositAccount->id, 'payment_method_id' => $depositAccount->payment_method_id]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk()->json('data.id');

    $this->postJson('/api/admin/payment_deposit_approve/'.$transaction->id)->assertForbidden();
    $this->postJson('/api/admin/payment_withdrawal_approve/'.$withdrawalId, ['lastSixTranId' => '123456'])->assertForbidden();

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'pending', 'approver_user_id' => null]);
    $this->assertDatabaseHas('withdraw_requests', ['id' => $withdrawalId, 'status' => 'requested', 'transaction_id' => null]);
    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '3000.25', 'locked_balance' => '2000.25']);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseCount('transactions', 1);
});

test('deposits credit the wallet once after admin approval and return 409 for a conflicting review', function (string $method, string $action, array $review): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario(withWallet: true);
    Sanctum::actingAs($customer, ['access']);
    $payload = ['amount' => '5000.50', 'lastSixTranId' => '012345', 'PaymentAccountId' => $account->id, 'PaymentMethodId' => $account->payment_method_id];

    $depositResponse = $this->postJson('/api/payment_deposit_create', $payload)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approvedUserName', null)->assertJsonPath('data.requesterName', $customer->name)->assertJsonPath('data.requesterEmail', $customer->email)
        ->assertJsonPath('data.paymentUser.id', $customer->id)
        ->assertJsonPath('data.paymentAccount.id', $account->id)
        ->assertJsonPath('data.paymentAccount.paymentMethod.id', $account->payment_method_id)
        ->assertJsonPath('data.paymentMethod.id', $account->payment_method_id)
        ->assertJsonMissingPath('data.updated_at');
    $transactionId = $depositResponse->json('data.id');
    expect($depositResponse->json('data.created_at'))->toBe(Transaction::query()->findOrFail($transactionId)->created_at->toISOString());
    expect(Str::isUuid($depositResponse->json('data.referenceId')))->toBeTrue();
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('wallet_transactions', 0);
    Sanctum::actingAs($administrator, ['access']);

    $this->{$method}('/api/admin/payment_deposit_'.($action === 'status' ? 'status_update' : 'approve').'/'.$transactionId, $review)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approvedUserName', $administrator->name)->assertJsonPath('data.requesterName', $customer->name)->assertJsonPath('data.requesterEmail', $customer->email)
        ->assertJsonPath('data.approver.id', $administrator->id)
        ->assertJsonPath('data.created_at', $depositResponse->json('data.created_at'));
    $this->{$method}('/api/admin/payment_deposit_'.($action === 'status' ? 'status_update' : 'approve').'/'.$transactionId, $review)->assertOk();
    $this->patchJson('/api/admin/payment_deposit_status_update/'.$transactionId, ['status' => 'approved'])->assertOk();
    $this->patchJson('/api/admin/payment_deposit_status_update/'.$transactionId, ['status' => 'rejected'])->assertConflict()->assertJsonPath('status', 409);

    foreach ([
        ['/api/admin/payment_deposit_list', 'data.items.0'],
        ['/api/admin/accounts_wallet_show/'.$customer->id, 'data.paymentTransactions.0'],
        ['/api/admin/accounts_wallet_history/'.$customer->id, 'data.transactions.items.0'],
        ['/api/admin/accounts_wallet_transaction_show/'.$customer->id.'/'.$transactionId, 'data.transaction'],
    ] as [$url, $itemPath]) {
        $this->getJson($url)->assertOk()
            ->assertJsonPath($itemPath.'.requesterName', $customer->name)
            ->assertJsonPath($itemPath.'.requesterEmail', $customer->email)
            ->assertJsonPath($itemPath.'.approvedUserName', $administrator->name);
    }

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '5000.50', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseHas('wallet_transactions', ['type' => 'deposit', 'amount' => '5000.50', 'balance_before' => 0, 'balance_after' => '5000.50']);
})->with([
    'status review' => ['patchJson', 'status', ['status' => 'approved']],
    'explicit approval' => ['postJson', 'approve', []],
    'explicit approval ignores a supplied rejection' => ['postJson', 'approve', ['status' => 'rejected']],
]);

test('admin rejection records the reviewer without crediting a deposit', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    $transaction = Transaction::factory()->create(['payment_user_id' => $customer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id]);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment_deposit_status_update/'.$transaction->id, ['status' => 'rejected'])->assertOk()->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.decliner.id', $administrator->id);
    $this->postJson('/api/admin/payment_deposit_approve/'.$transaction->id)->assertConflict();

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'rejected', 'declined_user_id' => $administrator->id]);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('withdrawals lock funds once and approval pays without debiting the available balance again', function (string $method, string $action, array $review): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '10000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $payload = withdrawalPayload($account);
    Sanctum::actingAs($customer, ['access']);

    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', $payload)->assertOk()->assertJsonPath('data.approvedUserName', null)->assertJsonPath('data.requesterName', $customer->name)->assertJsonPath('data.requesterEmail', $customer->email)
        ->assertJsonPath('data.requestedPaymentUser.id', $customer->id)
        ->assertJsonPath('data.requestedPaymentMethod.id', $account->payment_method_id)
        ->assertJsonPath('data.requestedPaymentAccount.id', $account->id)->json('data.id');
    $this->postJson('/api/payment_withdrawal_create', $payload)->assertOk()->assertJsonPath('data.id', $withdrawalId);
    $this->postJson('/api/payment_withdrawal_create', [...$payload, 'amount' => '3000.25'])->assertConflict();
    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '8000.25', 'locked_balance' => '2000.25']);
    $this->assertDatabaseCount('withdraw_requests', 1);
    Sanctum::actingAs($administrator, ['access']);
    $this->{$method}('/api/admin/payment_withdrawal_'.($action === 'status' ? 'status_update' : 'approve').'/'.$withdrawalId, $review)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approvedUserName', $administrator->name)->assertJsonPath('data.requesterName', $customer->name)->assertJsonPath('data.requesterEmail', $customer->email)
        ->assertJsonPath('data.approver.id', $administrator->id)
        ->assertJsonPath('data.transaction.type', 'withdraw');
    $this->{$method}('/api/admin/payment_withdrawal_'.($action === 'status' ? 'status_update' : 'approve').'/'.$withdrawalId, $review)->assertOk();
    $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawalId, ['status' => 'approved', 'lastSixTranId' => '987654'])->assertOk();

    $transactionId = WithdrawRequest::query()->findOrFail($withdrawalId)->transaction_id;
    foreach ([
        ['/api/admin/payment_withdrawal_list', 'data.items.0'],
        ['/api/admin/accounts_wallet_show/'.$customer->id, 'data.withdrawalRequests.0'],
        ['/api/admin/accounts_wallet_show/'.$customer->id, 'data.paymentTransactions.0'],
        ['/api/admin/accounts_wallet_history/'.$customer->id, 'data.withdrawalRequests.items.0'],
        ['/api/admin/accounts_wallet_history/'.$customer->id, 'data.transactions.items.0'],
        ['/api/admin/accounts_wallet_withdrawal_show/'.$customer->id.'/'.$withdrawalId, 'data.withdrawalRequest'],
        ['/api/admin/accounts_wallet_transaction_show/'.$customer->id.'/'.$transactionId, 'data.transaction'],
    ] as [$url, $itemPath]) {
        $this->getJson($url)->assertOk()
            ->assertJsonPath($itemPath.'.requesterName', $customer->name)
            ->assertJsonPath($itemPath.'.requesterEmail', $customer->email)
            ->assertJsonPath($itemPath.'.approvedUserName', $administrator->name);
    }

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '8000.25', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseHas('transactions', ['type' => 'withdraw', 'amount' => '2000.25', 'last_six_tran_id' => '987654', 'approver_user_id' => $administrator->id]);
})->with([
    'status review' => ['patchJson', 'status', ['status' => 'approved', 'lastSixTranId' => '987654']],
    'explicit approval' => ['postJson', 'approve', ['lastSixTranId' => '987654']],
    'explicit approval ignores a supplied decline' => ['postJson', 'approve', ['status' => 'declined', 'lastSixTranId' => '987654']],
]);

test('declining a withdrawal refunds locked funds once and returns 409 for later approval', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '10000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk()->json('data.id');
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawalId, ['status' => 'declined'])->assertOk()->assertJsonPath('data.decliner.id', $administrator->id);
    $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawalId, ['status' => 'declined'])->assertOk();
    $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawalId, ['status' => 'approved', 'lastSixTranId' => '123456'])->assertConflict();
    $this->postJson('/api/admin/payment_withdrawal_approve/'.$withdrawalId, ['lastSixTranId' => '123456'])->assertConflict();

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '10000.50', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 2);
    $this->assertDatabaseHas('wallet_transactions', ['type' => 'withdraw_rejected', 'balance_before' => '8000.25', 'balance_after' => '10000.50']);
    $this->assertDatabaseCount('transactions', 0);
});

test('insufficient withdrawal funds return HTTP 400 without a request or ledger movement', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario(withWallet: true);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertBadRequest()->assertJsonPath('status', 400);

    $this->assertDatabaseCount('withdraw_requests', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('withdrawal submissions reject another customers account with HTTP 404', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $otherCustomer = User::query()->where('email', 'customer2@example.com')->firstOrFail();
    $account = $otherCustomer->paymentAccounts()->firstOrFail();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertNotFound()->assertJsonPath('status', 404);

    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('customers create list and delete withdrawal accounts while preserving their history', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);
    $payload = ['userName' => 'Customer account', 'phoneNumber' => '09911112222', 'paymentMethodId' => $account->payment_method_id];

    $accountId = $this->postJson('/api/payment_account_withdrawal_create', $payload)->assertOk()->assertJsonPath('data.status', 'active')->json('data.id');
    $this->postJson('/api/payment_account_withdrawal_create', $payload)->assertConflict()->assertJsonPath('status', 409);
    $this->getJson('/api/payment_account_withdrawal_list')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/payment_method_withdrawal_list')->assertOk()->assertJsonPath('data.0.paymentMethod.id', $account->payment_method_id);
    $this->deleteJson('/api/payment_account_withdrawal_delete/'.$accountId)->assertOk();
    $this->getJson('/api/payment_account_withdrawal_list')->assertOk()->assertJsonCount(2, 'data');

    $this->assertDatabaseHas('payment_accounts', ['id' => $accountId, 'user_id' => $customer->id, 'status' => 'delete']);
});

test('customers cannot delete another customers withdrawal account', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $otherAccount = User::query()->where('email', 'customer2@example.com')->firstOrFail()->paymentAccounts()->firstOrFail();
    Sanctum::actingAs($customer, ['access']);

    $this->deleteJson('/api/payment_account_withdrawal_delete/'.$otherAccount->id)->assertNotFound();

    $this->assertDatabaseHas('payment_accounts', ['id' => $otherAccount->id, 'status' => 'active']);
});

test('admins manage deposit accounts and inactive accounts disappear from customer choices', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($administrator, ['access']);
    $accountId = $this->postJson('/api/admin/payment_account_deposit_create', ['userName' => 'Receiving account', 'bankAccountNumber' => '001234567890', 'paymentMethodId' => $account->payment_method_id])->assertOk()->json('data.id');

    $this->getJson('/api/admin/payment_account_deposit_list')->assertOk()->assertJsonCount(3, 'data');
    $this->patchJson('/api/admin/payment_account_deposit_status_update/'.$accountId, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    Sanctum::actingAs($customer, ['access']);
    $response = $this->getJson('/api/payment_method_deposit_list')->assertOk();

    expect(collect($response->json('data'))->flatMap(fn (array $method): array => $method['paymentAccounts'])->pluck('id')->all())->not->toContain($accountId);
    $this->postJson('/api/payment_deposit_create', ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $accountId, 'PaymentMethodId' => $account->payment_method_id])->assertBadRequest();
    $this->assertDatabaseCount('transactions', 0);
});

test('customers only see their withdrawal requests wallet balance and wallet movements', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk()->json('data.id');

    $this->getJson('/api/payment_withdrawal_request_history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withdrawalId);
    $this->getJson('/api/wallet_show')->assertOk()->assertJsonPath('data.avaliableBalance', '3000.25')->assertJsonPath('data.lockedBalance', '2000.25');
    $this->getJson('/api/wallet_history')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonMissingPath('data.pagination')->assertJsonPath('data.items.0.type', 'withdraw_lock');
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/payment_withdrawal_request_history')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/wallet_history?paymentUserId='.$customer->id)->assertOk()->assertJsonCount(0, 'data.items');
    $this->getJson('/api/wallet_show')->assertOk()->assertJsonPath('data.avaliableBalance', '0.00');
});

test('customer wallet history returns every movement without pagination', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk();
    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk();

    $this->getJson('/api/wallet_history?perPage=1')->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.pagination');
});

test('admin request queues support validated filters and pagination', function (): void {
    ['administrator' => $administrator] = paymentWalletScenario();
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/admin/payment_deposit_list?status=pending&perPage=1')->assertOk()->assertJsonPath('data.pagination.perPage', 1);
    $this->getJson('/api/admin/payment_withdrawal_list?perPage=101')->assertUnprocessable()->assertJsonValidationErrors('perPage');
    $this->getJson('/api/admin/payment_withdrawal_list?fromDate=2026-10-21&toDate=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('toDate');
    $this->getJson('/api/admin/payment_withdrawal_list?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('payment routes require access tokens and admin routes reject customers and inactive admins', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = paymentWalletScenario();

    $this->getJson('/api/wallet_show')->assertUnauthorized();
    $this->getJson('/api/admin/payment_deposit_list')->assertUnauthorized();
    Sanctum::actingAs($customer, ['other']);
    $this->getJson('/api/wallet_show')->assertForbidden();
    Sanctum::actingAs($customer, ['access']);
    $this->getJson('/api/admin/payment_deposit_list')->assertForbidden();
    $this->postJson('/api/admin/payment_account_deposit_create', [])->assertForbidden();
    $this->getJson('/api/admin/payment_withdrawal_list')->assertForbidden();
    $administrator->role->update(['status' => 'inactive']);
    Sanctum::actingAs($administrator, ['access']);
    $this->getJson('/api/admin/payment_deposit_list')->assertForbidden();
});

test('payment amounts reject missing zero negative and overprecision values', function (mixed $amount): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', [...withdrawalPayload($account), 'amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('withdraw_requests', 0);
})->with(['missing' => null, 'zero' => '0', 'negative' => '-1', 'too many decimals' => '1.001', 'exponent' => '1e3']);

test('legacy withdrawal requests without a reservation return 409 without moving funds', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario(withWallet: true);
    $withdrawal = WithdrawRequest::create(['reference_id' => (string) Str::uuid7(), 'amount' => '1000.00', 'requested_payment_user_id' => $customer->id, 'requested_payment_method_id' => $account->payment_method_id, 'requested_payment_account_id' => $account->id, 'status' => 'requested']);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment_withdrawal_status_update/'.$withdrawal->id, ['status' => 'declined'])->assertConflict();

    $this->assertDatabaseHas('withdraw_requests', ['id' => $withdrawal->id, 'status' => 'requested']);
    $this->assertDatabaseCount('wallet_transactions', 0);
});
test('inactive withdrawal accounts return 400 without reserving funds', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $account->update(['status' => 'inactive']);
    $customer->wallets()->create(['avaliable_balance' => '5000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertBadRequest();

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => 5000, 'locked_balance' => 0]);
    $this->assertDatabaseCount('withdraw_requests', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('payment methods must match the selected account', function (): void {
    ['customer' => $customer, 'depositAccount' => $depositAccount, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_withdrawal_create', [...withdrawalPayload($account), 'paymentMethodId' => $depositAccount->payment_method_id])->assertBadRequest();
    $this->postJson('/api/payment_deposit_create', ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $depositAccount->id, 'PaymentMethodId' => $account->payment_method_id])->assertBadRequest();

    $this->assertDatabaseCount('transactions', 0);
    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('account creation validates destinations and role ownership', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $depositAccount, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment_account_withdrawal_create', ['userName' => 'Customer', 'paymentMethodId' => $account->payment_method_id])->assertUnprocessable()->assertJsonValidationErrors(['phoneNumber', 'bankAccountNumber']);
    $this->postJson('/api/payment_account_withdrawal_create', ['userName' => 'Customer', 'phoneNumber' => '09123456789', 'paymentMethodId' => $depositAccount->payment_method_id])->assertUnprocessable()->assertJsonValidationErrors('paymentMethodId');
    Sanctum::actingAs($administrator, ['access']);
    $this->postJson('/api/payment_account_withdrawal_create', ['userName' => 'Admin', 'phoneNumber' => '09123456789', 'paymentMethodId' => $account->payment_method_id])->assertForbidden();
    $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertForbidden();

    $this->assertDatabaseCount('payment_accounts', 6);
    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('deposits generate distinct server references and ignore customer supplied reviewer fields', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);
    $payload = ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $account->id, 'PaymentMethodId' => $account->payment_method_id, 'clientReference' => 'customer-selected-reference', 'status' => 'approved', 'payment_user_id' => $administrator->id, 'approver_user_id' => $administrator->id];

    $firstResponse = $this->postJson('/api/payment_deposit_create', $payload)->assertOk()->assertJsonPath('data.paymentUser.id', $customer->id)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approver', null);
    $secondResponse = $this->postJson('/api/payment_deposit_create', $payload)->assertOk();
    $transactionId = $firstResponse->json('data.id');
    expect(Str::isUuid($firstResponse->json('data.referenceId')))->toBeTrue()
        ->and(Str::isUuid($secondResponse->json('data.referenceId')))->toBeTrue()
        ->and($secondResponse->json('data.referenceId'))->not->toBe($firstResponse->json('data.referenceId'));

    $this->assertDatabaseHas('transactions', ['id' => $transactionId, 'amount' => 100, 'payment_user_id' => $customer->id, 'status' => 'pending', 'approver_user_id' => null]);
    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('admin withdrawal approval requires payment confirmation and returns 422 without changing funds', function (string $method, string $action, array $review): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment_withdrawal_create', withdrawalPayload($account))->assertOk()->json('data.id');
    Sanctum::actingAs($administrator, ['access']);

    $this->{$method}('/api/admin/payment_withdrawal_'.($action === 'status' ? 'status_update' : 'approve').'/'.$withdrawalId, $review)->assertUnprocessable()->assertJsonValidationErrors('lastSixTranId');

    $this->assertDatabaseHas('withdraw_requests', ['id' => $withdrawalId, 'status' => 'requested', 'transaction_id' => null]);
    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '3000.25', 'locked_balance' => '2000.25']);
    $this->assertDatabaseCount('transactions', 0);
})->with([
    'status review without confirmation' => ['patchJson', 'status', ['status' => 'approved']],
    'explicit approval without confirmation' => ['postJson', 'approve', []],
    'explicit approval with short confirmation' => ['postJson', 'approve', ['lastSixTranId' => '12345']],
]);

test('admin deposit queues filter real records by customer status and date', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    $otherCustomer = User::query()->where('email', 'customer2@example.com')->firstOrFail();
    $transaction = Transaction::factory()->create(['payment_user_id' => $customer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id, 'created_at' => '2026-10-20 12:00:00']);
    Transaction::factory()->create(['payment_user_id' => $otherCustomer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id, 'status' => 'approved', 'created_at' => '2026-10-19 12:00:00']);
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/admin/payment_deposit_list?paymentUserId='.$customer->id.'&status=pending&fromDate=2026-10-20&toDate=2026-10-20')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $transaction->id);
});
test('payment migration can roll back and reapply without losing an existing wallet', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $wallet = $customer->wallets()->create(['avaliable_balance' => '1000.25', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $migration = require database_path('migrations/2026_10_21_024913_complete_payment_review_and_wallet_constraints.php');

    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('wallet', ['id' => $wallet->id, 'avaliable_balance' => '1000.25', 'locked_balance' => 0]);
});

test('payment migration refuses rollback when it would truncate a wallet balance', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $wallet = $customer->wallets()->create(['avaliable_balance' => '1000000.25', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $migration = require database_path('migrations/2026_10_21_024913_complete_payment_review_and_wallet_constraints.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('wallet', ['id' => $wallet->id, 'avaliable_balance' => '1000000.25']);
});
