<?php

namespace App\Service;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\MasterDataRequest;
use App\Http\Resources\PaymentAccountResource;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\SupportResource;
use App\Http\Resources\SupportTypeResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletTransactionResource;
use App\Http\Resources\WithdrawResource;
use App\Http\ServiceContractor\IAdminManagementService;
use App\Http\ServiceContractor\IMasterService;
use App\Models\PaymentMethod;
use App\Models\Support;
use App\Models\SupportType;
use App\Models\ThreedEntry;
use App\Models\Transaction;
use App\Models\TwodEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Response\ResponseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class AdminManagementService implements IAdminManagementService
{
    public function __construct(private IMasterService $masterService, private AdminActivityService $activities) {}

    public function Index(string $resource, AdminListRequest $request): ResponseModel
    {
        $query = $this->Query($resource);
        if ($request->filled('status') && $resource !== 'support-types') {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->validated('search').'%');
        }
        if ($resource === 'supports') {
            $query->with('supportType');
        }
        if ($resource === 'payment-methods') {
            $query->with('paymentAccounts');
        }
        $records = $query->latest()->latest('id')->paginate($request->integer('perPage', 20));
        $resourceClass = $this->Resource($resource);

        return ResponseModel::ok('Records fetched successfully!', ['items' => $resourceClass::collection($records), 'pagination' => ['currentPage' => $records->currentPage(), 'lastPage' => $records->lastPage(), 'perPage' => $records->perPage(), 'total' => $records->total()]]);
    }

    public function Save(string $resource, MasterDataRequest $request, User $user, ?string $recordId = null): ResponseModel
    {
        return DB::transaction(function () use ($resource, $request, $user, $recordId): ResponseModel {
            $record = $recordId === null ? $this->Query($resource)->getModel()->newInstance() : $this->Query($resource)->lockForUpdate()->find($recordId);
            if ($record === null) {
                return ResponseModel::NotFound('Record was not found!', null);
            }
            $data = $request->validated();
            if ($resource === 'payment-methods' && $recordId !== null && isset($data['type']) && $data['type'] !== $record->type
                && ($record->paymentAccounts()->exists() || $record->transactions()->exists())) {
                return ResponseModel::conflict('The type of a payment method with existing accounts or history cannot be changed!', null);
            }
            $mapping = ['imageLocation' => $resource === 'payment-methods' ? 'payment_method_image_location' : 'image_location', 'supportTypeId' => 'support_type_id'];
            foreach ($data as $field => $value) {
                $record->{$mapping[$field] ?? $field} = $value;
            }
            if ($recordId === null && $resource !== 'support-types') {
                $record->status = $data['status'] ?? 'inactive';
            }
            if ($resource === 'supports' && $record->status === 'active') {
                if (! is_string($record->value) || trim($record->value) === ''
                    || ($record->type === 'link' && (! filter_var($record->value, FILTER_VALIDATE_URL) || ! in_array(parse_url($record->value, PHP_URL_SCHEME), ['http', 'https'], true)))) {
                    return ResponseModel::badRequest('An active support contact needs a valid value. Links must use HTTP or HTTPS!', null);
                }
            }
            $record->save();
            $this->masterService->RefreshVersion();
            $this->activities->Record($user, 'master.saved', $record->getTable(), $record->id, $data);
            if ($resource === 'supports') {
                $record->load('supportType');
            }
            $resourceClass = $this->Resource($resource);

            return ResponseModel::ok('Record saved successfully!', new $resourceClass($record));
        }, 3);
    }

    public function Delete(string $resource, string $recordId, User $user): ResponseModel
    {
        return DB::transaction(function () use ($resource, $recordId, $user): ResponseModel {
            $record = $this->Query($resource)->lockForUpdate()->find($recordId);
            if ($record === null) {
                return ResponseModel::NotFound('Record was not found!', null);
            }
            if (($resource === 'payment-methods' && ($record->paymentAccounts()->exists() || $record->transactions()->exists()))
                || ($resource === 'support-types' && $record->support()->exists())) {
                return ResponseModel::conflict('This record is still in use and cannot be deleted!', null);
            }
            $this->activities->Record($user, 'master.deleted', $record->getTable(), $record->id);
            $record->delete();
            $this->masterService->RefreshVersion();

            return ResponseModel::ok('Record deleted successfully!', null);
        }, 3);
    }

    public function Dashboard(): ResponseModel
    {
        return ResponseModel::ok('Dashboard fetched successfully!', [
            'customerCount' => User::query()->whereHas('role', fn (Builder $query): Builder => $query->where('name', 'Customer'))->count(),
            'pendingDeposits' => Transaction::query()->where('type', 'deposit')->whereIn('status', ['pending', 'pending_approval'])->count(),
            'pendingWithdrawals' => WithdrawRequest::query()->where('status', 'requested')->count(),
            'availableWalletBalance' => $this->Sum(Wallet::query(), 'avaliable_balance'),
            'lockedWalletBalance' => $this->Sum(Wallet::query(), 'locked_balance'),
            'twodAcceptedStake' => $this->Sum(TwodEntry::query()->where('status', '!=', 'cancelled'), 'stake_amount'),
            'threedAcceptedStake' => $this->Sum(ThreedEntry::query()->where('status', '!=', 'cancelled'), 'stake_amount'),
            'paidWinnings' => $this->Sum(WalletTransaction::query()->where('type', 'bet_won'), 'amount'),
            'refundedBets' => $this->Sum(WalletTransaction::query()->where('type', 'bet_refunded'), 'amount'),
        ]);
    }

    public function Activities(AdminListRequest $request): ResponseModel
    {
        return ResponseModel::ok('Admin activity fetched successfully!', app(AuditDatabase::class)->Connection()->table('admin_activities')->latest()->orderByDesc('id')->paginate($request->integer('perPage', 20)));
    }

    public function CustomerHistory(string $userId, AdminListRequest $request): ResponseModel
    {
        $user = User::query()->with('role')->find($userId);
        if ($user === null) {
            return ResponseModel::NotFound('Account was not found!', null);
        }
        $perPage = $request->integer('perPage', 20);
        $transactions = $user->transactions()->latest()->latest('id')->paginate($perPage, ['*'], 'paymentsPage');
        $withdrawals = $user->withdrawRequests()->latest()->latest('id')->paginate($perPage, ['*'], 'withdrawalsPage');
        $movements = WalletTransaction::query()->whereHas('wallet', fn (Builder $query): Builder => $query->where('user_id', $user->id))->latest()->latest('id')->paginate($perPage, ['*'], 'movementsPage');

        return ResponseModel::ok('Account history fetched successfully!', [
            'user' => new UserResource($user), 'wallet' => $user->wallets()->first(),
            'paymentAccounts' => PaymentAccountResource::collection($user->paymentAccounts()->get()),
            'payments' => ['items' => TransactionResource::collection($transactions), 'total' => $transactions->total(), 'currentPage' => $transactions->currentPage()],
            'withdrawals' => ['items' => WithdrawResource::collection($withdrawals), 'total' => $withdrawals->total(), 'currentPage' => $withdrawals->currentPage()],
            'movements' => ['items' => WalletTransactionResource::collection($movements), 'total' => $movements->total(), 'currentPage' => $movements->currentPage()],
        ]);
    }

    private function Query(string $resource): Builder
    {
        return match ($resource) {
            'payment-methods' => PaymentMethod::query(), 'support-types' => SupportType::query(),
            'supports' => Support::query(), default => abort(404),
        };
    }

    /** @return class-string<JsonResource> */
    private function Resource(string $resource): string
    {
        return match ($resource) {
            'payment-methods' => PaymentMethodResource::class, 'support-types' => SupportTypeResource::class,
            'supports' => SupportResource::class, default => abort(404),
        };
    }

    private function Sum(Builder $query, string $column): string
    {
        $total = '0.00';
        $query->select(['id', $column])->chunkById(500, function ($records) use (&$total, $column): void {
            foreach ($records as $record) {
                $total = bcadd($total, (string) $record->{$column}, 2);
            }
        });

        return $total;
    }
}
