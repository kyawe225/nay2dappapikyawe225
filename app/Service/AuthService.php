<?php

namespace App\Service;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Response\ResponseModel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService implements IAuthService
{
    public function Login(LoginRequest $request): ResponseModel
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return ResponseModel::unauthorized('Email or password is incorrect.', null);
        }

        return ResponseModel::ok('Login successfully.', DB::transaction(fn (): array => $this->generateTokens($user)));
    }

    public function Register(RegisterRequest $request): ResponseModel
    {
        $data = $request->validated();
        $role = Role::query()->where('name', 'Customer')->where('status', 'active')->first();

        if ($role === null) {
            return ResponseModel::internalServer('Customer registration is unavailable.', null);
        }

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $role->id,
        ]);

        return ResponseModel::ok('User created successfully.', null);
    }

    public function RefreshToken(Request $request): ResponseModel
    {
        $currentToken = $request->user()->currentAccessToken();

        if (! $currentToken instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($request, $currentToken): ResponseModel {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $lockedToken = $user->tokens()->lockForUpdate()->find($currentToken->id);

            if ($lockedToken === null || ! $lockedToken->can('refresh')
                || ! str_starts_with($lockedToken->name, 'refresh-token:')
                || ($lockedToken->expires_at !== null && $lockedToken->expires_at->isPast())) {
                throw new AuthenticationException;
            }

            $sessionId = substr($lockedToken->name, strlen('refresh-token:'));

            if (! Str::isUuid($sessionId)) {
                throw new AuthenticationException;
            }

            $user->tokens()->whereIn('name', ['access-token:'.$sessionId, 'refresh-token:'.$sessionId])->delete();

            return ResponseModel::ok('New tokens generated.', $this->generateTokens($user, $sessionId));
        }, 3);
    }

    public function Logout(Request $request): ResponseModel
    {
        $currentToken = $request->user()->currentAccessToken();

        if (! $currentToken instanceof PersonalAccessToken || ! str_starts_with($currentToken->name, 'access-token:')) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($request, $currentToken): ResponseModel {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $sessionId = substr($currentToken->name, strlen('access-token:'));
            $user->tokens()->whereIn('name', ['access-token:'.$sessionId, 'refresh-token:'.$sessionId])->delete();

            return ResponseModel::ok('Logged out successfully.', null);
        }, 3);
    }

    /** @return array{token_type: string, access_token: string, access_token_expire_at: Carbon, refresh_token: string, refresh_token_expire_at: Carbon, user: User} */
    private function generateTokens(User $user, ?string $sessionId = null): array
    {
        $accessTokenExpiredAt = now('UTC')->addSeconds(max(1, (int) config('sanctum.access_token_lifetime_seconds')));
        $refreshTokenExpiredAt = now('UTC')->addDays(max(1, (int) config('sanctum.refresh_token_lifetime_days')));
        $sessionId ??= (string) Str::uuid7();

        return [
            'token_type' => 'Bearer',
            'access_token' => $user->createToken('access-token:'.$sessionId, ['access'], $accessTokenExpiredAt)->plainTextToken,
            'access_token_expire_at' => $accessTokenExpiredAt,
            'refresh_token' => $user->createToken('refresh-token:'.$sessionId, ['refresh'], $refreshTokenExpiredAt)->plainTextToken,
            'refresh_token_expire_at' => $refreshTokenExpiredAt,
            'user' => $user->load('role'),
        ];
    }
}
