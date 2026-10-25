<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\MasterDataRequest;
use App\Http\ServiceContractor\IAdminManagementService;
use Illuminate\Http\JsonResponse;

class MasterDataController extends Controller
{
    public function __construct(private IAdminManagementService $service) {}

    public function Index(AdminListRequest $request, string $resource): JsonResponse
    {
        $response = $this->service->Index($resource, $request);

        return response()->json($response, $response->status);
    }

    public function Create(MasterDataRequest $request, string $resource): JsonResponse
    {
        $response = $this->service->Save($resource, $request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Update(MasterDataRequest $request, string $resource, string $record): JsonResponse
    {
        $response = $this->service->Save($resource, $request, auth()->user(), $record);

        return response()->json($response, $response->status);
    }

    public function Delete(string $resource, string $record): JsonResponse
    {
        $response = $this->service->Delete($resource, $record, auth()->user());

        return response()->json($response, $response->status);
    }
}
