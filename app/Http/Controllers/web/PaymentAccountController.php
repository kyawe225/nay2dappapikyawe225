<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalAccountRequest;
use App\Http\ServiceContractor\IPaymentAccountService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Customer Payment Account Handler", "APIs for managing users Payment Accounts")]
class PaymentAccountController extends Controller
{
    public function __construct(private IPaymentAccountService $service) {}

    public function CreateWithdrawalAccount(WithdrawalAccountRequest $request): JsonResponse
    {
        $response = $this->service->CreateNewWithdrawalAccount($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function WithdrawalAccounts(): JsonResponse
    {
        $response = $this->service->WithdrawalAccounts(auth()->user());

        return response()->json($response, $response->status);
    }

    public function DeleteWithdrawalAccount(string $account): JsonResponse
    {
        $response = $this->service->DeleteWithdrawalAccount($account, auth()->user());

        return response()->json($response, $response->status);
    }
}
