<?php

use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use Database\Seeders\CustomerPaymentHistorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('database seeding gives each sample customer linked payment history', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(PaymentMethod::query()->count())->toBe(4)
        ->and(PaymentAccount::query()->count())->toBe(6)
        ->and(Transaction::query()->count())->toBe(12)
        ->and(WithdrawRequest::query()->count())->toBe(12);

    foreach (['customer1@example.com', 'customer2@example.com'] as $email) {
        $customer = User::query()->where('email', $email)->firstOrFail();
        $transactions = $customer->transactions()->with(['paymentAccount.user', 'paymentMethod', 'approver'])->get();

        expect($customer->isAdministrator())->toBeFalse()
            ->and($customer->paymentAccounts()->where('type', 'withdraw')->count())->toBe(2)
            ->and($customer->paymentAccounts()->where('type', 'deposit')->count())->toBe(0)
            ->and($transactions->where('type', 'deposit'))->toHaveCount(4)
            ->and($transactions->where('type', 'withdraw'))->toHaveCount(2)
            ->and($transactions->pluck('paymentMethod.name')->unique()->sort()->values()->all())->toBe(['KBZPay', 'WavePay']);

        foreach ($transactions as $transaction) {
            expect($transaction->paymentAccount->payment_method_id)->toBe($transaction->payment_method_id)
                ->and($transaction->paymentAccount->type)->toBe($transaction->type)
                ->and($transaction->paymentMethod->type)->toBe($transaction->type)
                ->and($transaction->created_at->lessThan(now()))->toBeTrue();

            if ($transaction->type === 'deposit') {
                expect($transaction->paymentAccount->user->isAdministrator())->toBeTrue();
            } else {
                expect($transaction->paymentAccount->user_id)->toBe($customer->id);
            }

            if ($transaction->status === 'approved') {
                expect($transaction->approver->isAdministrator())->toBeTrue()
                    ->and($transaction->approved_at->greaterThan($transaction->paid_at))->toBeTrue();
            } else {
                expect($transaction->approver_user_id)->toBeNull()
                    ->and($transaction->approved_at)->toBeNull();
            }
        }

        $requests = WithdrawRequest::query()->where('requested_payment_user_id', $customer->id)->get();

        foreach (['approved', 'requested', 'declined'] as $status) {
            expect($requests->where('status', $status))->toHaveCount(2);
        }

        foreach ($requests as $request) {
            $account = PaymentAccount::query()->findOrFail($request->requested_payment_account_id);
            expect($account->user_id)->toBe($customer->id)
                ->and($account->payment_method_id)->toBe($request->requested_payment_method_id);

            if ($request->status === 'approved') {
                expect($request->transaction->payment_user_id)->toBe($customer->id)
                    ->and($request->transaction->payment_accounts_id)->toBe($account->id)
                    ->and($request->transaction->amount)->toBe(number_format((float) $request->amount, 2, '.', ''));
            } else {
                expect($request->transaction_id)->toBeNull();
            }
        }
    }
});

test('customer history seeding preserves existing records when repeated', function (): void {
    $this->seed(CustomerPaymentHistorySeeder::class);
    $pendingDeposit = Transaction::query()->where('status', 'pending')->firstOrFail();
    $pendingDeposit->update(['last_six_tran_id' => '999999']);
    $account = PaymentAccount::query()->firstOrFail();
    $account->update(['descriptions' => 'Configured account']);
    $method = PaymentMethod::query()->firstOrFail();
    $method->update(['description' => 'Configured method']);

    $tables = ['users', 'payment_methods', 'payment_accounts', 'transactions', 'withdraw_requests'];
    $originalRows = [];

    foreach ($tables as $table) {
        $originalRows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    $this->travel(2)->days();
    $this->seed(CustomerPaymentHistorySeeder::class);

    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($originalRows[$table]);
    }
});
