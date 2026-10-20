<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\ServiceContractor\IPaymentAccountService;

class PaymentAccountController extends Controller
{
    public function __construct(private IPaymentAccountService $service)
    {
        $this->service = $service;
    }

    public function CreateWithdrawalAccount(WithdrawalAccountRequest $request)
    {
        $response = $this->service->CreateNewWithdrawalAccount($request, auth()->user());
        return response()->json($response);
    }
}
