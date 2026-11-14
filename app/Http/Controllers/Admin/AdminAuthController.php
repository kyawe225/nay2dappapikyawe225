<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\ServiceContractor\IAuthService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Admin Auth Things', 'APIs for managing users')]
class AdminAuthController extends Controller
{
    public function __construct(private IAuthService $service) {}

    #[Unauthenticated]
    #[Response(content: ['status' => 200, 'message' => 'Login successfully.', 'data' => ['token_type' => 'Bearer', 'access_token' => '1|example-admin-access-token', 'access_token_expire_at' => '2026-12-11T12:00:00.000000Z', 'user' => ['id' => '019a0000-0000-7000-8000-000000000002', 'name' => 'Example Administrator', 'email' => 'admin@example.com']]], description: 'Send the access token as a Bearer token on admin routes.')]
    #[Response(status: 401, content: ['status' => 401, 'message' => 'Email or password is incorrect.', 'data' => null])]
    public function Login(LoginRequest $request): JsonResponse
    {
        $response = $this->service->AdminLogin($request);

        return response()->json($response, $response->status);
    }
}
