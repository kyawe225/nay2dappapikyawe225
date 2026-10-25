<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\ServiceContractor\IUserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private IUserService $service) {}

    public function Profile(): JsonResponse
    {
        $response = $this->service->Profile(auth()->user());

        return response()->json($response, $response->status);
    }

    public function UpdateProfile(ProfileRequest $request): JsonResponse
    {
        $response = $this->service->UpdateProfile($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function ChangePassword(ChangePasswordRequest $request): JsonResponse
    {
        $response = $this->service->ChangePassword($request, auth()->user());

        return response()->json($response, $response->status);
    }

    public function ForgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $response = $this->service->ForgotPassword($request);

        return response()->json($response, $response->status);
    }

    public function ResetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $response = $this->service->ResetPassword($request);

        return response()->json($response, $response->status);
    }
}
