<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepositAccountRequest;
use App\Http\Requests\Admin\PaymentAccountStatusRequest;
use App\Http\ServiceContractor\IPaymentAccountService;
use Illuminate\Http\JsonResponse;

class PaymentAccountController extends Controller
{
    public function __construct(private IPaymentAccountService $service) {}

    public function CreateDepositAccount(DepositAccountRequest $request): JsonResponse
    {
        $response = $this->service->CreateNewDepositAccount($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function DepositAccounts(): JsonResponse
    {
        $response = $this->service->DepositAccounts();

        return response()->json($response, $response->status);
    }

    public function ChangeDepositAccountStatus(PaymentAccountStatusRequest $request, string $account): JsonResponse
    {
        $response = $this->service->ChangeDepositAccountStatus($request, $account);

        return response()->json($response, $response->status);
    }
}
