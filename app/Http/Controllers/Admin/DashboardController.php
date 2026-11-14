<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\ServiceContractor\IAdminManagementService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Response;

#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
class DashboardController extends Controller
{
    public function __construct(private IAdminManagementService $service) {}

    public function Index(): JsonResponse
    {
        $response = $this->service->Dashboard();

        return response()->json($response, $response->status);
    }

    public function Customers(): JsonResponse
    {
        $response = $this->service->DashboardCustomers();

        return response()->json($response, $response->status);
    }

    public function Activities(AdminListRequest $request): JsonResponse
    {
        $response = $this->service->Activities($request);

        return response()->json($response, $response->status);
    }
}
