<?php

namespace App\Http\Controllers\Web;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function __construct(private IAuthService $service) {}

    public function Login(LoginRequest $request): JsonResponse
    {
        $response = $this->service->Login($request);

        return response()->json($response, $response->status);
    }

    public function Register(RegisterRequest $request): JsonResponse
    {
        $response = $this->service->Register($request);

        return response()->json($response, $response->status);
    }

    public function RefreshToken(Request $request): JsonResponse
    {
        $response = $this->service->RefreshToken($request);

        return response()->json($response, $response->status);
    }

    public function Logout(Request $request): JsonResponse
    {
        $response = $this->service->Logout($request);

        return response()->json($response, $response->status);
    }
}
