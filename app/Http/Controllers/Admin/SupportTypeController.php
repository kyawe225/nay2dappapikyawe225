<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\SupportTypeRequest;
use App\Http\ServiceContractor\ISupportTypeService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Admin Support Type Handler', 'APIs for managing admin and users Support Type')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
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
