<?php

namespace Database\Seeders;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerPaymentHistorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(PaymentAccountSeeder::class);

            $administrator = User::query()->where('email', 'test@example.com')->firstOrFail();
            $customers = User::query()
                ->whereIn('email', ['customer1@example.com', 'customer2@example.com'])
                ->whereHas('role', fn (Builder $query): Builder => $query->where('name', 'Customer'))
                ->get();

            foreach ($customers as $customer) {
                $withdrawalAccounts = $customer->paymentAccounts()
                    ->with('paymentMethod')
                    ->where('type', 'withdraw')
                    ->whereHas('paymentMethod', fn (Builder $query): Builder => $query->whereIn('name', ['KBZPay', 'WavePay']))
                    ->get();

                foreach ($withdrawalAccounts as $withdrawalAccount) {
                    $depositAccount = $administrator->paymentAccounts()
                        ->where('type', 'deposit')
                        ->whereHas('paymentMethod', fn (Builder $query): Builder => $query
                            ->where('name', $withdrawalAccount->paymentMethod->name)
                            ->where('type', 'deposit'))
                        ->firstOrFail();

                    $this->seedHistory($customer, $administrator, $depositAccount, $withdrawalAccount);
                }
            }
        });
    }

    private function seedHistory(User $customer, User $administrator, PaymentAccount $depositAccount, PaymentAccount $withdrawalAccount): void
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $referencePrefix = $customer->email.':'.$withdrawalAccount->paymentMethod->name;

        $definitions = [
            ['account' => $depositAccount, 'status' => 'approved', 'amount' => '50000.00', 'paid_at' => $today->subDays(7), 'last_six_tran_id' => '100001'],
            ['account' => $depositAccount, 'status' => 'pending', 'amount' => '15000.00', 'paid_at' => $today->subDay(), 'last_six_tran_id' => '100002'],
            ['account' => $withdrawalAccount, 'status' => 'approved', 'amount' => '10000.00', 'paid_at' => $today->subDays(4), 'last_six_tran_id' => '200001'],
        ];

        foreach ($definitions as $definition) {
            $account = $definition['account'];
            $approved = $definition['status'] === 'approved';
            $transaction = Transaction::query()->firstOrCreate(
                ['reference_id' => 'sample-'.md5($referencePrefix.':'.$account->type.':'.$definition['status'])],
                [
                    'type' => $account->type,
                    'payment_user_id' => $customer->id,
                    'amount' => $definition['amount'],
                    'paid_at' => $definition['paid_at'],
                    'last_six_tran_id' => $definition['last_six_tran_id'],
                    'payment_accounts_id' => $account->id,
                    'payment_method_id' => $account->payment_method_id,
                    'approver_user_id' => $approved ? $administrator->id : null,
                    'approved_at' => $approved ? $definition['paid_at']->addHour() : null,
                    'status' => $definition['status'],
                    'created_at' => $definition['paid_at'],
                    'updated_at' => $approved ? $definition['paid_at']->addHour() : $definition['paid_at'],
                ],
            );

            if ($account->type === 'withdraw') {
                WithdrawRequest::query()->firstOrCreate(
                    ['reference_id' => 'sample-'.md5($referencePrefix.':withdraw-request:approved')],
                    [
                        'amount' => $transaction->amount,
                        'requested_payment_user_id' => $customer->id,
                        'requested_payment_method_id' => $account->payment_method_id,
                        'requested_payment_account_id' => $account->id,
                        'status' => 'approved',
                        'approve_user_id' => $transaction->approver_user_id,
                        'approved_at' => $transaction->approved_at,
                        'transaction_id' => $transaction->id,
                        'created_at' => $transaction->created_at->subHour(),
                        'updated_at' => $transaction->approved_at,
                    ],
                );
            }
        }

        foreach (['requested', 'declined'] as $status) {
            $requestedAt = $today->subDays($status === 'requested' ? 1 : 2);

            WithdrawRequest::query()->firstOrCreate(
                ['reference_id' => 'sample-'.md5($referencePrefix.':withdraw-request:'.$status)],
                [
                    'amount' => '5000.00',
                    'requested_payment_user_id' => $customer->id,
                    'requested_payment_method_id' => $withdrawalAccount->payment_method_id,
                    'requested_payment_account_id' => $withdrawalAccount->id,
                    'status' => $status,
                    'declined_user_id' => $status === 'declined' ? $administrator->id : null,
                    'declined_at' => $status === 'declined' ? $requestedAt->addHour() : null,
                    'created_at' => $requestedAt,
                    'updated_at' => $status === 'declined' ? $requestedAt->addHour() : $requestedAt,
                ],
            );
        }
    }
}
