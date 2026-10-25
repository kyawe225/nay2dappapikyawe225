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

test('deposits credit the wallet once after admin approval and return 409 for a conflicting review', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario(withWallet: true);
    Sanctum::actingAs($customer, ['access']);
    $payload = ['amount' => '5000.50', 'lastSixTranId' => '012345', 'PaymentAccountId' => $account->id, 'PaymentMethodId' => $account->payment_method_id, 'clientReference' => (string) Str::uuid7()];

    $transactionId = $this->postJson('/api/payment/deposit', $payload)->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
    $this->postJson('/api/payment/deposit', $payload)->assertOk()->assertJsonPath('data.id', $transactionId);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('wallet_transactions', 0);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment/deposit/'.$transactionId.'/status', ['status' => 'approved'])->assertOk()->assertJsonPath('data.approverUserId', $administrator->id);
    $this->patchJson('/api/admin/payment/deposit/'.$transactionId.'/status', ['status' => 'approved'])->assertOk();
    $this->patchJson('/api/admin/payment/deposit/'.$transactionId.'/status', ['status' => 'rejected'])->assertConflict()->assertJsonPath('status', 409);

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '5000.50', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseHas('wallet_transactions', ['type' => 'deposit', 'amount' => '5000.50', 'balance_before' => 0, 'balance_after' => '5000.50']);
});

test('admin rejection records the reviewer without crediting a deposit', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    $transaction = Transaction::factory()->create(['payment_user_id' => $customer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id]);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment/deposit/'.$transaction->id.'/status', ['status' => 'rejected'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.declinedUserId', $administrator->id);

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'rejected', 'declined_user_id' => $administrator->id]);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('withdrawals lock funds once and approval pays without debiting the available balance again', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '10000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    $payload = withdrawalPayload($account);
    Sanctum::actingAs($customer, ['access']);

    $withdrawalId = $this->postJson('/api/payment/withdrawal', $payload)->assertOk()->json('data.id');
    $this->postJson('/api/payment/withdrawal', $payload)->assertOk()->assertJsonPath('data.id', $withdrawalId);
    $this->postJson('/api/payment/withdrawal', [...$payload, 'amount' => '3000.25'])->assertConflict();
    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '8000.25', 'locked_balance' => '2000.25']);
    $this->assertDatabaseCount('withdraw_requests', 1);
    Sanctum::actingAs($administrator, ['access']);
    $review = ['status' => 'approved', 'lastSixTranId' => '987654'];

    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', $review)->assertOk()->assertJsonPath('data.approverUserId', $administrator->id);
    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', $review)->assertOk();

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '8000.25', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseHas('transactions', ['type' => 'withdraw', 'amount' => '2000.25', 'last_six_tran_id' => '987654', 'approver_user_id' => $administrator->id]);
});

test('declining a withdrawal refunds locked funds once and returns 409 for later approval', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '10000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertOk()->json('data.id');
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', ['status' => 'declined'])->assertOk()->assertJsonPath('data.declinedUserId', $administrator->id);
    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', ['status' => 'declined'])->assertOk();
    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', ['status' => 'approved', 'lastSixTranId' => '123456'])->assertConflict();

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '10000.50', 'locked_balance' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 2);
    $this->assertDatabaseHas('wallet_transactions', ['type' => 'withdraw_rejected', 'balance_before' => '8000.25', 'balance_after' => '10000.50']);
    $this->assertDatabaseCount('transactions', 0);
});

test('insufficient withdrawal funds return HTTP 400 without a request or ledger movement', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario(withWallet: true);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertBadRequest()->assertJsonPath('status', 400);

    $this->assertDatabaseCount('withdraw_requests', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('withdrawal submissions reject another customers account with HTTP 404', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $otherCustomer = User::query()->where('email', 'customer2@example.com')->firstOrFail();
    $account = $otherCustomer->paymentAccounts()->firstOrFail();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertNotFound()->assertJsonPath('status', 404);

    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('customers create list and delete withdrawal accounts while preserving their history', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);
    $payload = ['userName' => 'Customer account', 'phoneNumber' => '09911112222', 'paymentMethodId' => $account->payment_method_id];

    $accountId = $this->postJson('/api/paymentaccount/withdrawal', $payload)->assertOk()->assertJsonPath('data.status', 'active')->json('data.id');
    $this->postJson('/api/paymentaccount/withdrawal', $payload)->assertConflict()->assertJsonPath('status', 409);
    $this->getJson('/api/paymentaccount/withdrawal')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/paymentmethod/withdrawal')->assertOk()->assertJsonPath('data.0.paymentMethodId', $account->payment_method_id);
    $this->deleteJson('/api/paymentaccount/withdrawal/'.$accountId)->assertOk();
    $this->getJson('/api/paymentaccount/withdrawal')->assertOk()->assertJsonCount(2, 'data');

    $this->assertDatabaseHas('payment_accounts', ['id' => $accountId, 'user_id' => $customer->id, 'status' => 'delete']);
});

test('customers cannot delete another customers withdrawal account', function (): void {
    ['customer' => $customer] = paymentWalletScenario();
    $otherAccount = User::query()->where('email', 'customer2@example.com')->firstOrFail()->paymentAccounts()->firstOrFail();
    Sanctum::actingAs($customer, ['access']);

    $this->deleteJson('/api/paymentaccount/withdrawal/'.$otherAccount->id)->assertNotFound();

    $this->assertDatabaseHas('payment_accounts', ['id' => $otherAccount->id, 'status' => 'active']);
});

test('admins manage deposit accounts and inactive accounts disappear from customer choices', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($administrator, ['access']);
    $accountId = $this->postJson('/api/admin/paymentaccount/deposit', ['userName' => 'Receiving account', 'bankAccountNumber' => '001234567890', 'paymentMethodId' => $account->payment_method_id])->assertOk()->json('data.id');

    $this->getJson('/api/admin/paymentaccount/deposit')->assertOk()->assertJsonCount(3, 'data');
    $this->patchJson('/api/admin/paymentaccount/deposit/'.$accountId.'/status', ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    Sanctum::actingAs($customer, ['access']);
    $response = $this->getJson('/api/paymentmethod/deposit')->assertOk();

    expect(collect($response->json('data'))->flatMap(fn (array $method): array => $method['paymentAccounts'])->pluck('id')->all())->not->toContain($accountId);
    $this->postJson('/api/payment/deposit', ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $accountId, 'PaymentMethodId' => $account->payment_method_id])->assertBadRequest();
    $this->assertDatabaseCount('transactions', 0);
});

test('customers only see their withdrawal requests wallet balance and wallet movements', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertOk()->json('data.id');

    $this->getJson('/api/payment/withdrawal/request/history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $withdrawalId);
    $this->getJson('/api/wallet')->assertOk()->assertJsonPath('data.avaliableBalance', '3000.25')->assertJsonPath('data.lockedBalance', '2000.25');
    $this->getJson('/api/wallet/transactions')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.type', 'withdraw_lock');
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/payment/withdrawal/request/history')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/wallet/transactions?paymentUserId='.$customer->id)->assertOk()->assertJsonPath('data.pagination.total', 0);
    $this->getJson('/api/wallet')->assertOk()->assertJsonPath('data.avaliableBalance', '0.00');
});

test('admin request queues support validated filters and pagination', function (): void {
    ['administrator' => $administrator] = paymentWalletScenario();
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/admin/payment/deposit?status=pending&perPage=1')->assertOk()->assertJsonPath('data.pagination.perPage', 1);
    $this->getJson('/api/admin/payment/withdrawal?perPage=101')->assertUnprocessable()->assertJsonValidationErrors('perPage');
    $this->getJson('/api/admin/payment/withdrawal?fromDate=2026-10-21&toDate=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('toDate');
    $this->getJson('/api/admin/payment/withdrawal?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('payment routes require access tokens and admin routes reject customers and inactive admins', function (): void {
    ['customer' => $customer, 'administrator' => $administrator] = paymentWalletScenario();

    $this->getJson('/api/wallet')->assertUnauthorized();
    $this->getJson('/api/admin/payment/deposit')->assertUnauthorized();
    Sanctum::actingAs($customer, ['refresh']);
    $this->getJson('/api/wallet')->assertForbidden();
    Sanctum::actingAs($customer, ['access']);
    $this->getJson('/api/admin/payment/deposit')->assertForbidden();
    $this->postJson('/api/admin/paymentaccount/deposit', [])->assertForbidden();
    $this->getJson('/api/admin/payment/withdrawal')->assertForbidden();
    $administrator->role->update(['status' => 'inactive']);
    Sanctum::actingAs($administrator, ['access']);
    $this->getJson('/api/admin/payment/deposit')->assertForbidden();
});

test('payment amounts reject missing zero negative and overprecision values', function (mixed $amount): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment/withdrawal', [...withdrawalPayload($account), 'amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('withdraw_requests', 0);
})->with(['missing' => null, 'zero' => '0', 'negative' => '-1', 'too many decimals' => '1.001', 'exponent' => '1e3']);

test('legacy withdrawal requests without a reservation return 409 without moving funds', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario(withWallet: true);
    $withdrawal = WithdrawRequest::create(['reference_id' => (string) Str::uuid7(), 'amount' => '1000.00', 'requested_payment_user_id' => $customer->id, 'requested_payment_method_id' => $account->payment_method_id, 'requested_payment_account_id' => $account->id, 'status' => 'requested']);
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawal->id.'/status', ['status' => 'declined'])->assertConflict();

    $this->assertDatabaseHas('withdraw_requests', ['id' => $withdrawal->id, 'status' => 'requested']);
    $this->assertDatabaseCount('wallet_transactions', 0);
});
test('inactive withdrawal accounts return 400 without reserving funds', function (): void {
    ['customer' => $customer, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $account->update(['status' => 'inactive']);
    $customer->wallets()->create(['avaliable_balance' => '5000.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertBadRequest();

    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => 5000, 'locked_balance' => 0]);
    $this->assertDatabaseCount('withdraw_requests', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('payment methods must match the selected account', function (): void {
    ['customer' => $customer, 'depositAccount' => $depositAccount, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/payment/withdrawal', [...withdrawalPayload($account), 'paymentMethodId' => $depositAccount->payment_method_id])->assertBadRequest();
    $this->postJson('/api/payment/deposit', ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $depositAccount->id, 'PaymentMethodId' => $account->payment_method_id])->assertBadRequest();

    $this->assertDatabaseCount('transactions', 0);
    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('account creation validates destinations and role ownership', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $depositAccount, 'withdrawalAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);

    $this->postJson('/api/paymentaccount/withdrawal', ['userName' => 'Customer', 'paymentMethodId' => $account->payment_method_id])->assertUnprocessable()->assertJsonValidationErrors(['phoneNumber', 'bankAccountNumber']);
    $this->postJson('/api/paymentaccount/withdrawal', ['userName' => 'Customer', 'phoneNumber' => '09123456789', 'paymentMethodId' => $depositAccount->payment_method_id])->assertUnprocessable()->assertJsonValidationErrors('paymentMethodId');
    Sanctum::actingAs($administrator, ['access']);
    $this->postJson('/api/paymentaccount/withdrawal', ['userName' => 'Admin', 'phoneNumber' => '09123456789', 'paymentMethodId' => $account->payment_method_id])->assertForbidden();
    $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertForbidden();

    $this->assertDatabaseCount('payment_accounts', 6);
    $this->assertDatabaseCount('withdraw_requests', 0);
});

test('deposit retries reject changed amounts and ignore customer supplied reviewer fields', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    Sanctum::actingAs($customer, ['access']);
    $payload = ['amount' => '100', 'lastSixTranId' => '123456', 'PaymentAccountId' => $account->id, 'PaymentMethodId' => $account->payment_method_id, 'clientReference' => (string) Str::uuid7(), 'status' => 'approved', 'payment_user_id' => $administrator->id, 'approver_user_id' => $administrator->id];

    $transactionId = $this->postJson('/api/payment/deposit', $payload)->assertOk()->assertJsonPath('data.paymentUserId', $customer->id)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.approverUserId', null)->json('data.id');
    $this->postJson('/api/payment/deposit', [...$payload, 'amount' => '200'])->assertConflict();

    $this->assertDatabaseHas('transactions', ['id' => $transactionId, 'amount' => 100, 'payment_user_id' => $customer->id, 'status' => 'pending', 'approver_user_id' => null]);
    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('admin withdrawal approval requires payment confirmation and rolls back invalid requests', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'withdrawalAccount' => $account] = paymentWalletScenario();
    $customer->wallets()->create(['avaliable_balance' => '5000.50', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
    Sanctum::actingAs($customer, ['access']);
    $withdrawalId = $this->postJson('/api/payment/withdrawal', withdrawalPayload($account))->assertOk()->json('data.id');
    Sanctum::actingAs($administrator, ['access']);

    $this->patchJson('/api/admin/payment/withdrawal/'.$withdrawalId.'/status', ['status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('lastSixTranId');

    $this->assertDatabaseHas('withdraw_requests', ['id' => $withdrawalId, 'status' => 'requested', 'transaction_id' => null]);
    $this->assertDatabaseHas('wallet', ['user_id' => $customer->id, 'avaliable_balance' => '3000.25', 'locked_balance' => '2000.25']);
    $this->assertDatabaseCount('transactions', 0);
});

test('admin deposit queues filter real records by customer status and date', function (): void {
    ['customer' => $customer, 'administrator' => $administrator, 'depositAccount' => $account] = paymentWalletScenario();
    $otherCustomer = User::query()->where('email', 'customer2@example.com')->firstOrFail();
    $transaction = Transaction::factory()->create(['payment_user_id' => $customer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id, 'created_at' => '2026-10-20 12:00:00']);
    Transaction::factory()->create(['payment_user_id' => $otherCustomer->id, 'payment_accounts_id' => $account->id, 'payment_method_id' => $account->payment_method_id, 'status' => 'approved', 'created_at' => '2026-10-19 12:00:00']);
    Sanctum::actingAs($administrator, ['access']);

    $this->getJson('/api/admin/payment/deposit?paymentUserId='.$customer->id.'&status=pending&fromDate=2026-10-20&toDate=2026-10-20')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $transaction->id);
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
