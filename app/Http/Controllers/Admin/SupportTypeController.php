<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportTypeRequest;
use App\Http\ServiceContractor\ISupportTypeService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Admin Support Type Handler", "APIs for managing admin and users Support Type")]
class SupportTypeController extends Controller
{
    public function __construct(private ISupportTypeService $service) {}

    public function Index(AdminListRequest $request): JsonResponse
    {
        $response = $this->service->Index($request);

        return response()->json($response, $response->status);
    }

    public function Show(string $record): JsonResponse
    {
        $response = $this->service->Show($record);

        return response()->json($response, $response->status);
    }

    public function Create(SupportTypeRequest $request): JsonResponse
    {
        $response = $this->service->Create($request, $request->user());

        return response()->json($response, $response->status);
    }

    public function Update(SupportTypeRequest $request, string $record): JsonResponse
    {
        $response = $this->service->Update($request, $record, $request->user());

        return response()->json($response, $response->status);
    }

    public function Delete(string $record): JsonResponse
    {
        $response = $this->service->Delete($record, auth()->user());

        return response()->json($response, $response->status);
    }
}
