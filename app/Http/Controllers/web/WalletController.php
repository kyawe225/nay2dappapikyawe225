<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentHistoryRequest;
use App\Http\ServiceContractor\IPaymentService;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function __construct(private IPaymentService $service) {}

    public function Index(): JsonResponse
    {
        $response = $this->service->Wallet(auth()->user());

        return response()->json($response, $response->status);
    }

    public function History(PaymentHistoryRequest $request): JsonResponse
    {
        $response = $this->service->WalletHistory($request, auth()->user());

        return response()->json($response, $response->status);
    }
}
