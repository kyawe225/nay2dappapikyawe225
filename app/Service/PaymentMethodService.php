<?php

namespace App\Service;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\PaymentMethodRequest;
use App\Http\Resources\PaymentAccountResource;
use App\Http\Resources\PaymentMethodResource;
use App\Http\ServiceContractor\IAdminManagementService;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Response\ResponseModel;

class PaymentMethodService implements IPaymentMethodService
{
    public function __construct(private IAdminManagementService $management) {}

    public function AdminIndex(AdminListRequest $request): ResponseModel
    {
        return $this->management->Index('payment-methods', $request);
    }

    public function AdminShow(string $recordId): ResponseModel
    {
        $record = PaymentMethod::query()->with('paymentAccounts')->find($recordId);

        return $record === null ? ResponseModel::NotFound('Payment method was not found!', null)
            : ResponseModel::ok('Payment method fetched successfully!', new PaymentMethodResource($record));
    }

    public function AdminCreate(PaymentMethodRequest $request, User $actor): ResponseModel
    {
        return $this->management->Save('payment-methods', $request, $actor);
    }

    public function AdminUpdate(PaymentMethodRequest $request, string $recordId, User $actor): ResponseModel
    {
        return $this->management->Save('payment-methods', $request, $actor, $recordId);
    }

    public function AdminDelete(string $recordId, User $actor): ResponseModel
    {
        return $this->management->Delete('payment-methods', $recordId, $actor);
    }

    public function DepositPaymentAccounts(): ResponseModel
    {
        $depositPaymentAccounts = PaymentMethod::with([
            'paymentAccounts' => function ($query) {
                return $query->where('status', 'active')->where('type', 'deposit')->where('status', 'active')->whereHas('user', fn ($user) => $user->where('status', 'active'))->whereHas('user.role', fn ($role) => $role->whereIn('name', ['Super Admin', 'Admin', 'Staff'])->where('status', 'active'));
            },
        ])
            ->where('type', 'deposit')->where('status', 'active')
            ->get();

        return ResponseModel::ok(
            'Fetched Deposit Payment Accounts Successfully!',
            PaymentMethodResource::collection($depositPaymentAccounts),
        );
    }

    public function WithdrawalPaymentAccounts(User $user): ResponseModel
    {
        $withdrawalPaymentAccounts = PaymentAccount::with('paymentMethod')
            ->where('type', 'withdraw')
            ->where('status', 'active')
            ->where('user_id', $user->id)->whereHas('paymentMethod', fn ($method) => $method->where('status', 'active'))
            ->get();

        return ResponseModel::ok(
            'Withdrawal Accounts Fetched Successfully!',
            PaymentAccountResource::collection($withdrawalPaymentAccounts),
        );
    }
}
