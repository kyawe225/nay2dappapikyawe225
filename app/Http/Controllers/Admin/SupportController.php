<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportRequest;
use App\Http\ServiceContractor\ISupportService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Admin Support Handler", "APIs for managing admin and users Support.")]
class SupportController extends Controller
{
    public function __construct(private ISupportService $service) {}

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

    public function Create(SupportRequest $request): JsonResponse
    {
        $response = $this->service->AdminCreate($request, $request->user());

        return response()->json($response, $response->status);
    }

    public function Update(SupportRequest $request, string $record): JsonResponse
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
