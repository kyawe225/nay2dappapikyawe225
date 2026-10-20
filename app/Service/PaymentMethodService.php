<?php

namespace App\Service;

use App\Http\Resources\PaymentMethodResource;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Response\ResponseModel;

class PaymentMethodService implements IPaymentMethodService
{
    public function DepositPaymentAccounts(): ResponseModel
    {
        $depositPaymentAccounts = PaymentMethod::with([
            "paymentAccounts" => function ($query) {
                return $query->where("status", "active");
            },
        ])
            ->where("type", "deposit")
            ->get();

        return ResponseModel::ok(
            "Fetched Deposit Payment Accounts Successfully!",
            PaymentMethodResource::collection($depositPaymentAccounts),
        );
    }

    public function WithdrawalPaymentAccounts(User $user): ResponseModel
    {
        $withdrawalPaymentAccounts = PaymentAccount::with("paymentMethod")
            ->where("type", "withdraw")
            ->where("status", "active")
            ->where("user_id", $user->id)
            ->get();

        return ResponseModel::ok(
            "Withdrawal Accounts Fetched Successfully!",
            PaymentMethodResource::collection($withdrawalPaymentAccounts),
        );
    }
}
