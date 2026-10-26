<?php

namespace App\Service;

use App\Http\Requests\Admin\WalletHistoryRequest;
use App\Http\Requests\Admin\WalletManagementRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Http\Resources\WithdrawResource;
use App\Http\ServiceContractor\IWalletService;
use App\Models\User;
use App\Models\Wallet;
use App\Response\ResponseModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletService implements IWalletService
{
    public function __construct(private AdminActivityService $activities) {}

    /** The caller must hold the user's database lock until the transaction commits. */
    public function GetWallet(User $user): Wallet
    {
        return $user->wallets()->firstOrFail();
    }

    public function ShowForCustomer(string $userId): ResponseModel
    {
        $customer = User::query()->whereHas('role', fn (Builder $query): Builder => $query->where('name', 'Customer'))->find($userId);
        if ($customer === null) {
            return ResponseModel::NotFound('Customer account was not found!', null);
        }
        $wallet = $customer->wallets()->first();

        return $wallet === null
            ? ResponseModel::NotFound('Customer wallet was not found!', null)
            : ResponseModel::ok('Wallet fetched successfully!', new WalletResource($wallet));
    }

    public function HistoryForCustomer(string $userId, WalletHistoryRequest $request): ResponseModel
    {
        $wallet = $this->FindCustomerWallet($userId);
        if ($wallet === null) {
            return ResponseModel::NotFound('Customer wallet was not found!', null);
        }
        $transactions = $wallet->paymentTransactions()->with(['approver', 'user']);
        $withdrawals = $wallet->withdrawalRequests()->with(['approver', 'user']);
        $movements = $wallet->transactions();
        $this->FilterHistory($transactions, $request, ['transactionType' => 'type', 'transactionStatus' => 'status', 'referenceId' => 'reference_id']);
        $this->FilterHistory($withdrawals, $request, ['withdrawalStatus' => 'status', 'referenceId' => 'reference_id']);
        $this->FilterHistory($movements, $request, ['movementType' => 'type', 'direction' => 'direction', 'referenceType' => 'reference_type', 'referenceId' => 'reference_id']);
        $perPage = $request->integer('perPage', 20);

        return ResponseModel::ok('Customer wallet history fetched successfully!', [
            'wallet' => new WalletResource($wallet),
            'transactions' => $this->HistoryPage($transactions->latest()->latest('id')->paginate($perPage, ['*'], 'transactionsPage'), TransactionResource::class),
            'withdrawalRequests' => $this->HistoryPage($withdrawals->latest()->latest('id')->paginate($perPage, ['*'], 'withdrawalRequestsPage'), WithdrawResource::class),
            'walletTransactions' => $this->HistoryPage($movements->latest()->latest('id')->paginate($perPage, ['*'], 'walletTransactionsPage'), WalletTransactionResource::class),
        ]);
    }

    public function TransactionForCustomer(string $userId, string $transactionId): ResponseModel
    {
        $wallet = $this->FindCustomerWallet($userId);
        $transaction = $wallet?->paymentTransactions()->find($transactionId);
        if ($transaction === null) {
            return ResponseModel::NotFound('Customer wallet transaction was not found!', null);
        }
        $withdrawals = $wallet->withdrawalRequests()->with(['approver', 'user'])->where('transaction_id', $transaction->id)->get();
        $movements = $wallet->transactions()->where(function (Builder $query) use ($transaction, $withdrawals): void {
            $query->where(fn (Builder $query): Builder => $query->where('reference_type', 'transaction')->where('reference_id', $transaction->id))
                ->orWhere(fn (Builder $query): Builder => $query->where('reference_type', 'withdraw_request')->whereIn('reference_id', $withdrawals->modelKeys()));
        })->latest()->latest('id')->get();

        return ResponseModel::ok('Customer payment transaction fetched successfully!', [
            'wallet' => new WalletResource($wallet), 'transaction' => new TransactionResource($transaction),
            'withdrawalRequests' => WithdrawResource::collection($withdrawals),
            'walletTransactions' => WalletTransactionResource::collection($movements),
        ]);
    }

    public function WithdrawalForCustomer(string $userId, string $withdrawalId): ResponseModel
    {
        $wallet = $this->FindCustomerWallet($userId);
        $withdrawal = $wallet?->withdrawalRequests()->find($withdrawalId);
        if ($withdrawal === null) {
            return ResponseModel::NotFound('Customer wallet withdrawal request was not found!', null);
        }
        $transaction = $wallet->paymentTransactions()->find($withdrawal->transaction_id);
        $movements = $wallet->transactions()->where('reference_type', 'withdraw_request')->where('reference_id', $withdrawal->id)->latest()->latest('id')->get();

        return ResponseModel::ok('Customer withdrawal request fetched successfully!', [
            'wallet' => new WalletResource($wallet), 'withdrawalRequest' => new WithdrawResource($withdrawal),
            'transaction' => $transaction === null ? null : new TransactionResource($transaction),
            'walletTransactions' => WalletTransactionResource::collection($movements),
        ]);
    }

    public function MovementForCustomer(string $userId, string $movementId): ResponseModel
    {
        $wallet = $this->FindCustomerWallet($userId);
        $movement = $wallet?->transactions()->find($movementId);
        if ($movement === null) {
            return ResponseModel::NotFound('Customer wallet ledger entry was not found!', null);
        }
        $withdrawal = $movement->reference_type === 'withdraw_request' ? $wallet->withdrawalRequests()->find($movement->reference_id) : null;
        $transactionId = $movement->reference_type === 'transaction' ? $movement->reference_id : $withdrawal?->transaction_id;
        $transaction = $wallet->paymentTransactions()->find($transactionId);

        return ResponseModel::ok('Customer wallet ledger entry fetched successfully!', [
            'wallet' => new WalletResource($wallet), 'walletTransaction' => new WalletTransactionResource($movement),
            'transaction' => $transaction === null ? null : new TransactionResource($transaction),
            'withdrawalRequest' => $withdrawal === null ? null : new WithdrawResource($withdrawal),
        ]);
    }

    private function FindCustomerWallet(string $userId): ?Wallet
    {
        return Wallet::query()->where('user_id', $userId)
            ->whereHas('user.role', fn (Builder $query): Builder => $query->where('name', 'Customer'))->first();
    }

    /** @param array<string, string> $filters */
    private function FilterHistory(Builder|HasMany $query, WalletHistoryRequest $request, array $filters): void
    {
        foreach ($filters as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->validated($field));
            }
        }
        foreach (['fromDate' => '>=', 'toDate' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('created_at', $operator, $request->validated($field));
            }
        }
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array{items: mixed, pagination: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    private function HistoryPage(LengthAwarePaginator $records, string $resourceClass): array
    {
        return ['items' => $resourceClass::collection($records), 'pagination' => [
            'currentPage' => $records->currentPage(), 'lastPage' => $records->lastPage(),
            'perPage' => $records->perPage(), 'total' => $records->total(),
        ]];
    }

    public function CreateForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel
    {
        return DB::transaction(function () use ($userId, $request, $actor): ResponseModel {
            $customer = $this->LockCustomer($userId);
            if ($customer === null) {
                return ResponseModel::NotFound('Customer account was not found!', null);
            }
            if ($customer->wallets()->exists()) {
                return ResponseModel::conflict('This customer already has a wallet!', null);
            }
            $wallet = $customer->wallets()->create(['avaliable_balance' => '0.00', 'locked_balance' => '0.00', 'version' => (string) Str::uuid7()]);
            $this->activities->Record($actor, 'wallet.created', 'wallet', $wallet->id, [
                'customerUserId' => $customer->id, 'reason' => $request->validated('reason'),
            ]);

            return ResponseModel::ok('Customer wallet created successfully!', new WalletResource($wallet));
        }, 3);
    }

    public function AdjustForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel
    {
        return DB::transaction(function () use ($userId, $request, $actor): ResponseModel {
            $customer = $this->LockCustomer($userId);
            if ($customer === null) {
                return ResponseModel::NotFound('Customer account was not found!', null);
            }
            $wallet = $customer->wallets()->lockForUpdate()->first();
            if ($wallet === null) {
                return ResponseModel::NotFound('Customer wallet was not found!', null);
            }
            $data = $request->validated();
            $existing = $wallet->transactions()->where('client_reference', $data['clientReference'])->first();
            if ($existing !== null) {
                if ($existing->type !== 'manual_adjustment' || $existing->direction !== $data['direction']
                    || bccomp($existing->amount, (string) $data['amount'], 2) !== 0 || $existing->reason !== $data['reason']) {
                    return ResponseModel::conflict('Client reference is already used for a different wallet adjustment!', null);
                }

                return ResponseModel::ok('Wallet adjustment already exists!', ['wallet' => new WalletResource($wallet), 'transaction' => new WalletTransactionResource($existing)]);
            }
            $referenceId = (string) Str::uuid7();
            $this->Move($wallet, 'manual_adjustment', $data['direction'], (string) $data['amount'], 'admin_adjustment', $referenceId);
            $movement = $wallet->transactions()->where('idempotency_key', 'manual_adjustment:'.$referenceId)->firstOrFail();
            $movement->fill(['actor_user_id' => $actor->id, 'reason' => $data['reason'], 'client_reference' => $data['clientReference']])->save();
            $this->activities->Record($actor, 'wallet.adjusted', 'wallet', $wallet->id, [
                'customerUserId' => $customer->id, 'transactionId' => $movement->id,
                'amount' => $movement->amount, 'direction' => $movement->direction,
                'balanceBefore' => $movement->balance_before, 'balanceAfter' => $movement->balance_after,
                'clientReference' => $data['clientReference'], 'reason' => $data['reason'],
            ]);

            return ResponseModel::ok('Wallet adjusted successfully!', ['wallet' => new WalletResource($wallet), 'transaction' => new WalletTransactionResource($movement)]);
        }, 3);
    }

    public function DeleteForCustomer(string $userId, WalletManagementRequest $request, User $actor): ResponseModel
    {
        return DB::transaction(function () use ($userId, $request, $actor): ResponseModel {
            $customer = $this->LockCustomer($userId);
            if ($customer === null) {
                return ResponseModel::NotFound('Customer account was not found!', null);
            }
            $wallet = $customer->wallets()->lockForUpdate()->first();
            if ($wallet === null) {
                return ResponseModel::NotFound('Customer wallet was not found!', null);
            }
            if (bccomp($wallet->avaliable_balance, '0', 2) !== 0 || bccomp($wallet->locked_balance, '0', 2) !== 0 || $wallet->transactions()->exists()) {
                return ResponseModel::conflict('Only an empty wallet without transaction history can be deleted!', null);
            }
            $this->activities->Record($actor, 'wallet.deleted', 'wallet', $wallet->id, [
                'customerUserId' => $customer->id, 'reason' => $request->validated('reason'),
            ]);
            $wallet->delete();

            return ResponseModel::ok('Customer wallet deleted successfully!', null);
        }, 3);
    }

    private function LockCustomer(string $userId): ?User
    {
        return User::query()->whereHas('role', fn (Builder $query): Builder => $query->where('name', 'Customer'))->lockForUpdate()->find($userId);
    }

    public function Move(Wallet $wallet, string $type, string $direction, string $amount, string $referenceType, string $referenceId): void
    {
        $before = $wallet->avaliable_balance;
        $after = $direction === 'in' ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2);
        if (bccomp($after, '0', 2) < 0 || bccomp(bcadd($after, $wallet->locked_balance, 2), str_repeat('9', 50).'.99', 2) > 0) {
            throw ValidationException::withMessages(['amount' => 'The wallet cannot accept this balance change.']);
        }
        $wallet->avaliable_balance = $after;
        $wallet->version = (string) Str::uuid7();
        $wallet->save();
        $this->RecordMovement($wallet, $type, $direction, $amount, $before, $referenceType, $referenceId);
    }

    public function RecordMovement(Wallet $wallet, string $type, string $direction, string $amount, string $balanceBefore, string $referenceType, string $referenceId): void
    {
        $wallet->transactions()->create([
            'type' => $type, 'direction' => $direction, 'amount' => $amount,
            'balance_before' => $balanceBefore, 'balance_after' => $wallet->avaliable_balance,
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'idempotency_key' => $type.':'.$referenceId,
        ]);
    }
}
