<?php

namespace App\Http\Controllers\web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\ISupportService;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function __construct(private ISupportService $service) {}

    public function Index(): JsonResponse
    {
        return response()->json($this->service->Index());
    }
}
