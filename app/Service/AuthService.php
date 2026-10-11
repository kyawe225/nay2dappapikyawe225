<?php

namespace App\Service;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Response\ResponseModel;
use DB;
use Exception;
use Hash;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Str;

class AuthService implements IAuthService
{
    public function Login(LoginRequest $request)
    {
        $arr = $request->validated();

        $user = User::where("email", $arr["email"])->first();

        if ($user == null) {
            return ResponseModel::NotFound("User Not Found", null);
        }

        if (!Hash::check($arr["password"], $user->password)) {
            return ResponseModel::badRequest("Email or password wrong.", null);
        }

        return ResponseModel::ok(
            "Login Successfully!",
            $this->generateTokens($user, null),
        );
    }

    public function Register(RegisterRequest $request)
    {
        $arr = $request->validated();

        if (User::where("email", $arr["email"])->exists()) {
            return ResponseModel::badRequest("Duplicate email.", null);
        }

        $role_id = Role::where("name", "Customer")->first()->id;

        $user = [
            "name" => $arr["name"],
            "email" => $arr["email"],
            "password" => Hash::make($arr["password"]),
            "role_id" => $role_id,
        ];

        $user = User::create($user);

        $isSuccess = $user->save();
        if (!$isSuccess) {
            return ResponseModel::internalServer(
                "User created not Successfully!",
                null,
            );
        }
        return ResponseModel::ok("User created Successfully!", null);
    }

    public function RefreshToken(Request $request)
    {
        if (!$request->bearerToken()) {
            return ResponseModel::badRequest("Not Authorized", null);
        }

        DB::beginTransaction();
        try {
            $lockedToken = auth()->user()->currentAccessToken();

            $user = auth()->user();

            if (!$lockedToken instanceof PersonalAccessToken) {
                return ResponseModel::badRequest("Not Authorized", null);
            }

            // Example token name:
            // refresh-token:550e8400-e29b-41d4-a716-446655440000

            $prefix = "refresh-token:";

            if (!str_starts_with($lockedToken->name, $prefix)) {
                return response()->json(
                    [
                        "message" => "Invalid refresh token.",
                    ],
                    401,
                );
            }

            $sessionId = substr($lockedToken->name, strlen($prefix));

            if (!$sessionId) {
                return response()->json(
                    [
                        "message" => "Invalid token session.",
                    ],
                    401,
                );
            }

            // Delete the matching access token
            $user
                ->tokens()
                ->where("name", "access-token:{$sessionId}")
                ->delete();

            // Delete old refresh token
            $lockedToken->delete();
            $tokens = $this->generateTokens($user, $sessionId);
            // Generate replacement pair for same device
            DB::commit();
            return ResponseModel::ok("new Token Generated!!!",$tokens);
        } catch (Exception $e) {
            DB::rollBack();
        }
    }

    private function generateTokens(User $user, $sessionId): array
    {
        $accessTokenExpiredAt = Carbon::now("utc")->addSeconds(
            (int) env("SESSION_LIFETIME", 30),
        );
        $refreshTokenExpiredAt = Carbon::now("utc")->addDays(env("REFRESH_TOKEN_LIFETIME",30));
        $sessionId = $sessionId ?? Str::uuid7();
        // Access token: default 30 seconds
        $accessToken = $user->createToken(
            "access-token:{$sessionId}",
            ["access"],
            $accessTokenExpiredAt,
        )->plainTextToken;

        // Refresh token: 30 days
        $refreshToken = $user->createToken(
            "refresh-token:{$sessionId}",
            ["refresh"],
            $refreshTokenExpiredAt,
        )->plainTextToken;

        return [
            "token_type" => "Bearer",
            "access_token" => $accessToken,
            "access_token_expire_at" => $accessTokenExpiredAt,
            "refresh_token" => $refreshToken,
            "refresh_token_expire_at" => $refreshTokenExpiredAt,
        ];
    }
}
?>
