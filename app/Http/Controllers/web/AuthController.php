<?php

namespace App\Http\Controllers\Web;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group("Admin and User Auth Things", "APIs for managing users")]
class AuthController extends Controller
{
    public function __construct(private IAuthService $service) {}
    #[Unauthenticated]
    public function Login(LoginRequest $request): JsonResponse
    {
        $response = $this->service->Login($request);

        return response()->json($response, $response->status);
    }
    #[Unauthenticated]
    public function Register(RegisterRequest $request): JsonResponse
    {
        $response = $this->service->Register($request);

        return response()->json($response, $response->status);
    }
    #[Authenticated]
    public function RefreshToken(Request $request): JsonResponse
    {
        $response = $this->service->RefreshToken($request);

        return response()->json($response, $response->status);
    }
    #[Authenticated]
    public function Logout(Request $request): JsonResponse
    {
        $response = $this->service->Logout($request);

        return response()->json($response, $response->status);
    }
}
