<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\ISupportService;
use Illuminate\Http\JsonResponse;

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
