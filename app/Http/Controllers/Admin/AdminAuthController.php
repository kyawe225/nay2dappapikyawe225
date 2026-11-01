<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group("Admin Auth Things", "APIs for managing users")]
class AdminAuthController extends Controller
{
    public function __construct(private IAuthService $service) {}
    #[Unauthenticated]
    public function Login(LoginRequest $request): JsonResponse
    {
        $response = $this->service->AdminLogin($request);

        return response()->json($response, $response->status);
    }
}
