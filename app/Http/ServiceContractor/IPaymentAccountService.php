<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface IPaymentAccountService
{
    public function CreateNewWithdrawalAccount(WithdrawalAccountRequest $request,User $user): ResponseModel;
    public function CreateNewWithdrawalRequest(WithdrawalRequest $request,User $user): ResponseModel;
    public function CreateNewDepositAccount(DepositAccountRequest $request,User $user) : ResponseModel;
    public function CreateNewDepositRequest(DepositRequest $request, User $user):ResponseModel;
}
