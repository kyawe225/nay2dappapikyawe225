<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\DepositRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Response\ResponseModel;
use Illuminate\Http\Request;

interface IAuthService
{
    public function WithdrawalRequest(WithdrawalRequest $request): ResponseModel;

    public function DepositRequest(DepositRequest $request): ResponseModel;
}
