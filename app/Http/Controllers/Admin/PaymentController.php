<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentReviewRequest;
use App\Http\Requests\PaymentHistoryRequest;
use App\Http\ServiceContractor\IPaymentService;
use App\Models\Transaction;
use App\Models\WithdrawRequest;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private IPaymentService $service) {}

    public function DepositRequests(PaymentHistoryRequest $request): JsonResponse
    {
        $response = $this->service->DepositRequests($request);

        return response()->json($response, $response->status);
    }

    public function WithdrawalRequests(PaymentHistoryRequest $request): JsonResponse
    {
        $response = $this->service->WithdrawalRequests($request);

        return response()->json($response, $response->status);
    }

    public function ReviewDeposit(PaymentReviewRequest $request, Transaction $transaction): JsonResponse
    {
        $response = $this->service->ReviewDeposit($request, $transaction, auth()->user());

        return response()->json($response, $response->status);
    }

    public function ReviewWithdrawal(PaymentReviewRequest $request, WithdrawRequest $withdrawal): JsonResponse
    {
        $response = $this->service->ReviewWithdrawal($request, $withdrawal, auth()->user());

        return response()->json($response, $response->status);
    }
}
