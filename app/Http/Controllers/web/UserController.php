<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\ServiceContractor\IUserService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Customer Profile Handler', 'APIs for managing users Profile')]
#[Authenticated]
class UserController extends Controller
{
    public function __construct(private IUserService $service) {}

    #[Response(content: ['status' => 200, 'message' => 'Profile fetched successfully!', 'data' => ['id' => '019a0000-0000-7000-8000-000000000001', 'name' => 'Example Customer', 'email' => 'customer@example.com', 'status' => 'active', 'role' => ['roleName' => 'Customer', 'status' => 'active']]])]
    #[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid access token.')]
    public function Profile(): JsonResponse
    {
        $response = $this->service->Profile(auth()->user());

        return response()->json($response, $response->status);
    }

    #[Response(content: ['status' => 200, 'message' => 'Profile fetched successfully!', 'data' => ['id' => '019a0000-0000-7000-8000-000000000001', 'name' => 'Example Customer', 'email' => 'customer@example.com', 'status' => 'active', 'role' => ['roleName' => 'Customer', 'status' => 'active']]])]
    #[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid access token.')]
    public function UpdateProfile(ProfileRequest $request): JsonResponse
    {
        $response = $this->service->UpdateProfile($request, auth()->user());

        return response()->json($response, $response->status);
    }

    #[Response(content: ['status' => 200, 'message' => 'Password changed successfully! Please log in again.', 'data' => null])]
    #[Response(status: 401, content: ['message' => 'Unauthenticated.'], description: 'Missing or invalid access token.')]
    public function ChangePassword(ChangePasswordRequest $request): JsonResponse
    {
        $response = $this->service->ChangePassword($request, auth()->user());

        return response()->json($response, $response->status);
    }

    #[Unauthenticated]
    #[Response(content: ['status' => 200, 'message' => 'If this email has an active account, password reset instructions have been sent.', 'data' => null])]
    public function ForgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $response = $this->service->ForgotPassword($request);

        return response()->json($response, $response->status);
    }

    #[Unauthenticated]
    #[Response(content: ['status' => 200, 'message' => 'Password reset successfully! Please log in again.', 'data' => null])]
    public function ResetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $response = $this->service->ResetPassword($request);

        return response()->json($response, $response->status);
    }
}
