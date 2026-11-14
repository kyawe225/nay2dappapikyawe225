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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService implements IAuthService
{
    public function Login(LoginRequest $request): ResponseModel
    {
        return DB::transaction(function () use ($request): ResponseModel {
            $data = $request->validated();
            $user = User::query()->where('email', $data['email'])->whereHas('role', function ($query) {
                $query->where('role_name', 'Customer');
            })->lockForUpdate()->first();
            if ($user === null || ! Hash::check($data['password'], $user->password)) {
                return ResponseModel::unauthorized('Email or password is incorrect.', null);
            }
            if ($user->status !== 'active' || ! $user->role()->where('status', 'active')->exists()) {
                return new ResponseModel(403, 'This account is not active!', null);
            }

            return ResponseModel::ok('Login successfully.', $this->generateTokens($user));
        }, 3);
    }

    public function AdminLogin(LoginRequest $request): ResponseModel
    {
        return DB::transaction(function () use ($request): ResponseModel {
            $data = $request->validated();
            $user = User::query()->where('email', $data['email'])->whereHas('role', function ($query) {
                $query->where('role_name', '!=', 'Customer');
            })->lockForUpdate()->first();
            if ($user === null || ! Hash::check($data['password'], $user->password)) {
                return ResponseModel::unauthorized('Email or password is incorrect.', null);
            }
            if ($user->status !== 'active' || ! $user->role()->where('status', 'active')->exists()) {
                return new ResponseModel(403, 'This account is not active!', null);
            }

            return ResponseModel::ok('Login successfully.', $this->generateTokens($user));
        }, 3);
    }

    public function Register(RegisterRequest $request): ResponseModel
    {
        return DB::transaction(function () use ($request): ResponseModel {
            $data = $request->validated();
            $role = Role::query()->where('role_name', 'Customer')->where('status', 'active')->first();

            if ($role === null) {
                return ResponseModel::internalServer('Customer registration is unavailable.', null);
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_name' => $role->role_name,
            ]);
            $user->wallets()->create([
                'avaliable_balance' => '0.00',
                'locked_balance' => '0.00',
                'version' => (string) Str::uuid7(),
            ]);

            return ResponseModel::ok('User created successfully.', null);
        }, 3);
    }

    public function Logout(Request $request): ResponseModel
    {
        $currentToken = $request->user()->currentAccessToken();

        if (! $currentToken instanceof PersonalAccessToken || ! $currentToken->can('access')) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($request, $currentToken): ResponseModel {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $user->tokens()->whereKey($currentToken->id)->delete();

            return ResponseModel::ok('Logged out successfully.', null);
        }, 3);
    }

    /** @return array{token_type: string, access_token: string, user: User} */
    private function generateTokens(User $user): array
    {
        $accessTokenExpiredAt = now('UTC')->addSeconds(max(1, (int) config('sanctum.access_token_lifetime_seconds')));

        return [
            'token_type' => 'Bearer',
            'access_token' => $user->createToken('access-token', ['access'], $accessTokenExpiredAt)->plainTextToken,
            'user' => $user->load('role'),
        ];
    }
}
