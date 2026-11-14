<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentHistoryRequest;
use App\Http\ServiceContractor\IPaymentService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Customer Wallet Handler', 'APIs for managing users Wallet')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
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
