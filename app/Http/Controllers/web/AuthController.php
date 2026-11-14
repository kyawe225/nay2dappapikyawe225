<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Admin and User Auth Things', 'APIs for managing users')]
class AuthController extends Controller
{
    public function __construct(private IAuthService $service) {}

    #[Unauthenticated]
    #[Response(content: ['status' => 200, 'message' => 'Login successfully.', 'data' => ['token_type' => 'Bearer', 'access_token' => '1|example-access-token', 'access_token_expire_at' => '2026-12-11T12:00:00.000000Z', 'user' => ['id' => '019a0000-0000-7000-8000-000000000001', 'name' => 'Example Customer', 'email' => 'customer@example.com']]], description: 'Send the access token as a Bearer token on protected routes.')]
    #[Response(status: 401, content: ['status' => 401, 'message' => 'Email or password is incorrect.', 'data' => null])]
    public function Login(LoginRequest $request): JsonResponse
    {
        $response = $this->service->Login($request);

        return response()->json($response, $response->status);
    }

    #[Unauthenticated]
    #[Response(content: ['status' => 200, 'message' => 'User created successfully.', 'data' => null], description: 'Log in to obtain an access token.')]
    public function Register(RegisterRequest $request): JsonResponse
    {
        $response = $this->service->Register($request);

        return response()->json($response, $response->status);
    }

    #[Authenticated]
    #[Response(content: ['status' => 200, 'message' => 'Logged out successfully.', 'data' => null])]
    #[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid access token.')]
    public function Logout(Request $request): JsonResponse
    {
        $response = $this->service->Logout($request);

        return response()->json($response, $response->status);
    }
}
