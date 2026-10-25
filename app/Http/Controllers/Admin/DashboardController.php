<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\ServiceContractor\IAdminManagementService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private IAdminManagementService $service) {}

    public function Index(): JsonResponse
    {
        $response = $this->service->Dashboard();

        return response()->json($response, $response->status);
    }

    public function Activities(AdminListRequest $request): JsonResponse
    {
        $response = $this->service->Activities($request);

        return response()->json($response, $response->status);
    }

    public function CustomerHistory(AdminListRequest $request, string $account): JsonResponse
    {
        $response = $this->service->CustomerHistory($account, $request);

        return response()->json($response, $response->status);
    }
}
