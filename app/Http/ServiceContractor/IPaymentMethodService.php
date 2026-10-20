<?php

namespace App\Http\ServiceContractor;

use App\Models\User;
use App\Response\ResponseModel;

interface IPaymentMethodService
{
    public function DepositPaymentAccounts(): ResponseModel;

    public function WithdrawalPaymentAccounts(User $user): ResponseModel;
}
