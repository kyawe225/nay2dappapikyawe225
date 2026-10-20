<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\ServiceContractor\IPaymentAccountService;

class PaymentController extends Controller
{
    public function __construct(private IPaymentAccountService $service) {
        $this->service = $service;
    }

    public function CreateDepositRequest(DepositRequest $request){
        $response = $this->service->CreateNewDepositRequest($request,auth()->user());
        return response()->json($response);
    }

    public function CreateWithdrawalRequest(WithdrawalRequest $request){
        $response = $this->service->CreateNewWithdrawalRequest($request,auth()->user());
        return response()->json($response);
    }
}
