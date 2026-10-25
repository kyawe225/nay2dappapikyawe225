<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\IPaymentMethodService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class PaymentMethodController extends Controller
{
    public function __construct(private IPaymentMethodService $service)
    {
        $this->service = $service;
    }

    public function DepositPaymentAccounts(): JsonResponse
    {
        $depositAccounts = $this->service->DepositPaymentAccounts();

        return response()->json($depositAccounts, $depositAccounts->status);
    }

    public function WithdrawalPaymentAccounts(Request $request): JsonResponse
    {
        $withdrawalPaymentAccounts = $this->service->WithdrawalPaymentAccounts(auth()->user());

        return response()->json($withdrawalPaymentAccounts, $withdrawalPaymentAccounts->status);
    }
}
