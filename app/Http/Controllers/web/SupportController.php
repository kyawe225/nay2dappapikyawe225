<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\ServiceContractor\ISupportService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

#[Group('Customer Support Handler', 'APIs for managing users Support')]
#[Authenticated]
#[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid bearer token.')]
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
