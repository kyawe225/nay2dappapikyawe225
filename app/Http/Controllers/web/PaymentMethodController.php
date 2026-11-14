<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\IPaymentMethodService;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Group('Customer Payment Methods Handler', 'APIs for managing users Payment Methods')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
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
