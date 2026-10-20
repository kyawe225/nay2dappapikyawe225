<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\IMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterController extends Controller
{
    public function __construct(private IMasterService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['version' => ['sometimes', 'nullable', 'string', 'max:64']]);

        return response()->json($this->service->Index($validated['version'] ?? ''));
    }
}
