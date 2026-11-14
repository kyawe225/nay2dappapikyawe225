<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\PaymentMethodRequest;
use App\Http\ServiceContractor\IPaymentMethodService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Admin Payment Method Handler', 'APIs for managing admin and users Payment Method')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
class PaymentMethodController extends Controller
{
    public function __construct(private IPaymentMethodService $service) {}

    public function Index(AdminListRequest $request): JsonResponse
    {
        $response = $this->service->AdminIndex($request);

        return response()->json($response, $response->status);
    }

    public function Show(string $record): JsonResponse
    {
        $response = $this->service->AdminShow($record);

        return response()->json($response, $response->status);
    }

    public function Create(PaymentMethodRequest $request): JsonResponse
    {
        $response = $this->service->AdminCreate($request, $request->user());

        return response()->json($response, $response->status);
    }

    public function Update(PaymentMethodRequest $request, string $record): JsonResponse
    {
        $response = $this->service->AdminUpdate($request, $record, $request->user());

        return response()->json($response, $response->status);
    }

    public function Delete(string $record): JsonResponse
    {
        $response = $this->service->AdminDelete($record, auth()->user());

        return response()->json($response, $response->status);
    }
}
