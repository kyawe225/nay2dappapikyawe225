<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\ServiceContractor\IPaymentAccountService;
use Illuminate\Http\Request;

class PaymentAccountController extends Controller
{
    public function __construct(private IPaymentAccountService $service)
    {
        $this->service = $service;
    }

    public function createWithdrawalAccount(WithdrawalAccountRequest $request)
    {
        $response = $this->service->CreateNewWithdrawalAccount($request, auth()->user());
        return response()->json($response);
    }
    public function createDepositAccount(DepositAccountRequest $request)
    {
        $response = $this->service->CreateNewDepositAccount($request, auth()->user());
        return response()->json($response);
    }
}
