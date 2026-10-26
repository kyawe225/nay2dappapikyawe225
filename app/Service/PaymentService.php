<?php

namespace App\Service;

use App\Http\Requests\Admin\PaymentReviewRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\PaymentHistoryRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletTransactionResource;
use App\Http\Resources\WithdrawResource;
use App\Http\ServiceContractor\IPaymentService;
use App\Http\ServiceContractor\IWalletService;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Response\ResponseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService implements IPaymentService
{
    public function __construct(private IWalletService $walletService) {}

    public function DepositHistory(User $user): ResponseModel
    {
        $transactions = $user->transactions()->with(['approver', 'user'])->where('type', 'deposit')->latest()->latest('id')->get();

        return ResponseModel::ok('Deposit transactions fetched successfully!', TransactionResource::collection($transactions));
    }

    public function WithdrawHistory(User $user): ResponseModel
    {
        $transactions = $user->transactions()->with(['approver', 'user'])->where('type', 'withdraw')->latest()->latest('id')->get();

        return ResponseModel::ok('Withdrawal transactions fetched successfully!', TransactionResource::collection($transactions));
    }

    public function WithdrawRequestHistory(User $user): ResponseModel
    {
        $withdrawals = $user->withdrawRequests()->with(['approver', 'user'])->latest()->latest('id')->get();

        return ResponseModel::ok('Withdrawal requests fetched successfully!', WithdrawResource::collection($withdrawals));
    }

    public function CreateNewDepositRequest(DepositRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $user): ResponseModel {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $validatedArr = $request->validated();
            $referenceId = $this->ReferenceId($validatedArr['clientReference'] ?? null, $user, 'deposit');
            $existingTransaction = $user->transactions()->where('reference_id', $referenceId)->first();

            if ($existingTransaction !== null) {
                if (bccomp($existingTransaction->amount, (string) $validatedArr['amount'], 2) !== 0
                    || $existingTransaction->payment_accounts_id !== $validatedArr['PaymentAccountId']
                    || $existingTransaction->payment_method_id !== $validatedArr['PaymentMethodId']
                    || $existingTransaction->last_six_tran_id !== $validatedArr['lastSixTranId']) {
                    return ResponseModel::conflict('Client reference is already used for another deposit!', null);
                }

                return ResponseModel::ok('Deposit request already exists!', new TransactionResource($existingTransaction));
            }

            $account = PaymentAccount::query()->lockForUpdate()->findOrFail($validatedArr['PaymentAccountId']);

            if ($account->type !== 'deposit' || $account->status !== 'active'
                || $account->payment_method_id !== $validatedArr['PaymentMethodId']
                || $account->paymentMethod->status !== 'active' || $account->paymentMethod->type !== 'deposit' || ! $account->user->isAdministrator()) {
                return ResponseModel::badRequest('Please select an active admin deposit account and matching payment method!', null);
            }

            $transaction = Transaction::create([
                'type' => 'deposit',
                'reference_id' => $referenceId,
                'payment_user_id' => $user->id,
                'amount' => $validatedArr['amount'],
                'paid_at' => now('UTC'),
                'last_six_tran_id' => $validatedArr['lastSixTranId'],
                'payment_accounts_id' => $account->id,
                'payment_method_id' => $account->payment_method_id,
                'status' => 'pending',
            ]);

            return ResponseModel::ok('Deposit requested successfully! Please wait for admin approval!', new TransactionResource($transaction));
        }, 3);
    }

    public function CreateNewWithdrawalRequest(WithdrawalRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $user): ResponseModel {
            User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->isAdministrator()) {
                return new ResponseModel(403, 'Only customer accounts can request withdrawals!', null);
            }

            $validatedArr = $request->validated();
            $referenceId = $this->ReferenceId($validatedArr['clientReference'] ?? null, $user, 'withdraw');
            $existingWithdrawal = $user->withdrawRequests()->where('reference_id', $referenceId)->first();

            if ($existingWithdrawal !== null) {
                if (bccomp($existingWithdrawal->amount, (string) $validatedArr['amount'], 2) !== 0
                    || $existingWithdrawal->requested_payment_account_id !== $validatedArr['paymentAccountId']
                    || $existingWithdrawal->requested_payment_method_id !== $validatedArr['paymentMethodId']) {
                    return ResponseModel::conflict('Client reference is already used for another withdrawal!', null);
                }

                return ResponseModel::ok('Withdrawal request already exists!', new WithdrawResource($existingWithdrawal));
            }

            $account = $user->paymentAccounts()->lockForUpdate()->find($validatedArr['paymentAccountId']);

            if ($account === null) {
                return ResponseModel::NotFound('Withdrawal account was not found!', null);
            }

            if ($account->type !== 'withdraw' || $account->status !== 'active'
                || $account->payment_method_id !== $validatedArr['paymentMethodId']
                || $account->paymentMethod->status !== 'active' || $account->paymentMethod->type !== 'withdraw') {
                return ResponseModel::badRequest('Please select an active withdrawal account and matching payment method!', null);
            }

            $wallet = $this->GetWallet($user);
            $amount = (string) $validatedArr['amount'];

            if (bccomp($wallet->avaliable_balance, $amount, 2) < 0) {
                return ResponseModel::badRequest('Insufficient available balance!', null);
            }

            $withdrawal = WithdrawRequest::create([
                'reference_id' => $referenceId,
                'requested_payment_user_id' => $user->id,
                'amount' => $amount,
                'requested_payment_account_id' => $account->id,
                'requested_payment_method_id' => $account->payment_method_id,
                'status' => 'requested',
            ]);

            $balanceBefore = $wallet->avaliable_balance;
            $wallet->avaliable_balance = bcsub($balanceBefore, $amount, 2);
            $wallet->locked_balance = bcadd($wallet->locked_balance, $amount, 2);
            $wallet->version = (string) Str::uuid7();
            $wallet->save();
            $this->RecordMovement($wallet, 'withdraw_lock', 'out', $amount, $balanceBefore, 'withdraw_request', $withdrawal->id);

            return ResponseModel::ok('Withdrawal requested successfully! Please wait for admin approval!', new WithdrawResource($withdrawal));
        }, 3);
    }

    public function DepositRequests(PaymentHistoryRequest $request): ResponseModel
    {
        $query = Transaction::query()->with(['approver', 'user'])->where('type', 'deposit');
        $this->FilterHistory($query, $request, 'payment_user_id');
        $transactions = $query->latest()->latest('id')->paginate($request->integer('perPage', 20));

        return ResponseModel::ok('Deposit requests fetched successfully!', [
            'items' => TransactionResource::collection($transactions),
            'pagination' => ['currentPage' => $transactions->currentPage(), 'lastPage' => $transactions->lastPage(), 'perPage' => $transactions->perPage(), 'total' => $transactions->total()],
        ]);
    }

    public function WithdrawalRequests(PaymentHistoryRequest $request): ResponseModel
    {
        $query = WithdrawRequest::query()->with(['approver', 'user']);
        $this->FilterHistory($query, $request, 'requested_payment_user_id');
        $withdrawals = $query->latest()->latest('id')->paginate($request->integer('perPage', 20));

        return ResponseModel::ok('Withdrawal requests fetched successfully!', [
            'items' => WithdrawResource::collection($withdrawals),
            'pagination' => ['currentPage' => $withdrawals->currentPage(), 'lastPage' => $withdrawals->lastPage(), 'perPage' => $withdrawals->perPage(), 'total' => $withdrawals->total()],
        ]);
    }

    public function ReviewDeposit(PaymentReviewRequest $request, Transaction $transaction, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $transaction, $user): ResponseModel {
            $customer = User::query()->lockForUpdate()->findOrFail($transaction->payment_user_id);
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $status = $request->validated('status');

            if ($transaction->type !== 'deposit') {
                return ResponseModel::NotFound('Deposit request was not found!', null);
            }

            if ($transaction->status === $status) {
                return ResponseModel::ok('Deposit request is already reviewed!', new TransactionResource($transaction));
            }

            if (! in_array($transaction->status, ['pending', 'pending_approval'], true)) {
                return ResponseModel::conflict('Deposit request is already completed!', null);
            }

            if ($status === 'approved') {
                $wallet = $this->GetWallet($customer);
                $balanceBefore = $wallet->avaliable_balance;
                $balanceAfter = bcadd($balanceBefore, $transaction->amount, 2);

                if (bccomp(bcadd($balanceAfter, $wallet->locked_balance, 2), str_repeat('9', 50).'.99', 2) > 0) {
                    return ResponseModel::badRequest('Wallet balance exceeds the supported amount!', null);
                }

                $wallet->avaliable_balance = $balanceAfter;
                $wallet->version = (string) Str::uuid7();
                $wallet->save();
                $this->RecordMovement($wallet, 'deposit', 'in', $transaction->amount, $balanceBefore, 'transaction', $transaction->id);
                $transaction->approver_user_id = $user->id;
                $transaction->approved_at = now('UTC');
            } else {
                $transaction->declined_user_id = $user->id;
                $transaction->declined_at = now('UTC');
            }

            $transaction->status = $status;
            $transaction->save();

            return ResponseModel::ok('Deposit request reviewed successfully!', new TransactionResource($transaction));
        }, 3);
    }

    public function ReviewWithdrawal(PaymentReviewRequest $request, WithdrawRequest $withdrawal, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $withdrawal, $user): ResponseModel {
            $customer = User::query()->lockForUpdate()->findOrFail($withdrawal->requested_payment_user_id);
            $withdrawal = WithdrawRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);
            $status = $request->validated('status');

            if ($withdrawal->status === $status) {
                return ResponseModel::ok('Withdrawal request is already reviewed!', new WithdrawResource($withdrawal));
            }

            if ($withdrawal->status !== 'requested') {
                return ResponseModel::conflict('Withdrawal request is already completed!', null);
            }

            $wallet = $this->GetWallet($customer);
            $reservation = $wallet->transactions()->where('idempotency_key', 'withdraw_lock:'.$withdrawal->id)->first();

            if ($reservation === null || bccomp($reservation->amount, $withdrawal->amount, 2) !== 0
                || bccomp($wallet->locked_balance, $withdrawal->amount, 2) < 0) {
                return ResponseModel::conflict('This withdrawal has no matching wallet reservation. Please reconcile it before review!', null);
            }

            $wallet->locked_balance = bcsub($wallet->locked_balance, $withdrawal->amount, 2);

            if ($status === 'approved') {
                $transaction = Transaction::create([
                    'type' => 'withdraw',
                    'reference_id' => $withdrawal->reference_id,
                    'payment_user_id' => $customer->id,
                    'amount' => $withdrawal->amount,
                    'paid_at' => now('UTC'),
                    'last_six_tran_id' => $request->validated('lastSixTranId'),
                    'payment_accounts_id' => $withdrawal->requested_payment_account_id,
                    'payment_method_id' => $withdrawal->requested_payment_method_id,
                    'approver_user_id' => $user->id,
                    'approved_at' => now('UTC'),
                    'status' => 'approved',
                ]);
                $withdrawal->transaction_id = $transaction->id;
                $withdrawal->approve_user_id = $user->id;
                $withdrawal->approved_at = now('UTC');
            } else {
                $balanceBefore = $wallet->avaliable_balance;
                $wallet->avaliable_balance = bcadd($balanceBefore, $withdrawal->amount, 2);
                $this->RecordMovement($wallet, 'withdraw_rejected', 'in', $withdrawal->amount, $balanceBefore, 'withdraw_request', $withdrawal->id);
                $withdrawal->declined_user_id = $user->id;
                $withdrawal->declined_at = now('UTC');
            }

            $wallet->version = (string) Str::uuid7();
            $wallet->save();
            $withdrawal->status = $status;
            $withdrawal->save();

            return ResponseModel::ok('Withdrawal request reviewed successfully!', new WithdrawResource($withdrawal));
        }, 3);
    }

    public function Wallet(User $user): ResponseModel
    {
        $wallet = $user->wallets()->first();

        return ResponseModel::ok('Wallet fetched successfully!', [
            'avaliableBalance' => $wallet?->avaliable_balance ?? '0.00',
            'lockedBalance' => $wallet?->locked_balance ?? '0.00',
            'version' => $wallet?->version,
        ]);
    }

    public function WalletHistory(PaymentHistoryRequest $request, User $user): ResponseModel
    {
        $query = WalletTransaction::query()->whereHas('wallet', fn (Builder $query): Builder => $query->where('user_id', $user->id));

        foreach (['fromDate' => '>=', 'toDate' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('created_at', $operator, $request->validated($field));
            }
        }

        $transactions = $query->latest()->latest('id')->paginate($request->integer('perPage', 20));

        return ResponseModel::ok('Wallet transactions fetched successfully!', [
            'items' => WalletTransactionResource::collection($transactions),
            'pagination' => ['currentPage' => $transactions->currentPage(), 'lastPage' => $transactions->lastPage(), 'perPage' => $transactions->perPage(), 'total' => $transactions->total()],
        ]);
    }

    private function GetWallet(User $user): Wallet
    {
        return $this->walletService->GetWallet($user);
    }

    private function RecordMovement(Wallet $wallet, string $type, string $direction, string $amount, string $balanceBefore, string $referenceType, string $referenceId): void
    {
        $this->walletService->RecordMovement($wallet, $type, $direction, $amount, $balanceBefore, $referenceType, $referenceId);
    }

    private function ReferenceId(?string $clientReference, User $user, string $type): string
    {
        return $clientReference === null ? (string) Str::uuid7() : md5($user->id.':'.$type.':'.$clientReference);
    }

    private function FilterHistory(Builder $query, PaymentHistoryRequest $request, string $userColumn): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('paymentUserId')) {
            $query->where($userColumn, $request->validated('paymentUserId'));
        }

        foreach (['fromDate' => '>=', 'toDate' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('created_at', $operator, $request->validated($field));
            }
        }
    }
}
