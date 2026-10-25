<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\UserManagementRequest;
use App\Http\ServiceContractor\IUserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private IUserService $service) {}

    public function Index(AdminListRequest $request): JsonResponse
    {
        $response = $this->service->Index($request);

        return response()->json($response, $response->status);
    }

    public function Show(string $account): JsonResponse
    {
        $response = $this->service->Show($account);

        return response()->json($response, $response->status);
    }

    public function Create(UserManagementRequest $request): JsonResponse
    {
        $response = $this->service->Create($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Update(UserManagementRequest $request, string $account): JsonResponse
    {
        $response = $this->service->Update($request, $account, auth()->user());

        return response()->json($response, $response->status);
    }

    public function Roles(): JsonResponse
    {
        $response = $this->service->Roles();

        return response()->json($response, $response->status);
    }
}
