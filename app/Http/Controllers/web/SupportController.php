<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\ISupportService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;

#[Group("Customer Support Handler", "APIs for managing users Support")]
class SupportController extends Controller
{
    private ISupportService $service;

    public function __construct(ISupportService $service)
    {
        $this->service = $service;
    }

    public function Index(): JsonResponse
    {
        $response = $this->service->Index();

        return response()->json($response, $response->status);
    }
}
