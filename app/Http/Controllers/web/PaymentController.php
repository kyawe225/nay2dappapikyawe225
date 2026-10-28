<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Customer Payment Handler", "APIs for managing users payments")]
class PaymentController extends Controller
{
    public function __construct(private IPaymentAccountService $service, private IPaymentService $paymentService) {}

    public function CreateDepositRequest(DepositRequest $request): JsonResponse
    {
        $response = $this->service->CreateNewDepositRequest($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function CreateWithdrawalRequest(WithdrawalRequest $request): JsonResponse
    {
        $response = $this->service->CreateNewWithdrawalRequest($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function DepositPaymentHistory(): JsonResponse
    {
        $response = $this->paymentService->DepositHistory(auth()->user());

        return response()->json($response, $response->status);
    }

    public function WithdrawalHistory(): JsonResponse
    {
        $response = $this->paymentService->WithdrawHistory(auth()->user());

        return response()->json($response, $response->status);
    }

    public function WithdrawalRequestHistory(): JsonResponse
    {
        $response = $this->paymentService->WithdrawRequestHistory(auth()->user());

        return response()->json($response, $response->status);
    }
}
