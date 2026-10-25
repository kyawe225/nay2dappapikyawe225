<?php

namespace App\Service;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\UserManagementRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Http\ServiceContractor\IUserService;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordRecoveryNotification;
use App\Response\ResponseModel;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserService implements IUserService
{
    public function __construct(private AdminActivityService $activities) {}

    public function Profile(User $user): ResponseModel
    {
        return ResponseModel::ok('Profile fetched successfully!', new UserResource($user->load('role')));
    }

    public function UpdateProfile(ProfileRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $user): ResponseModel {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $data = $request->validated();
            if (isset($data['email']) && ! Hash::check($data['currentPassword'], $user->password)) {
                return ResponseModel::badRequest('Current password is incorrect!', null);
            }
            if (isset($data['name'])) {
                $user->name = $data['name'];
            }
            if (isset($data['email']) && $data['email'] !== $user->email) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->email = $data['email'];
                $user->email_verified_at = null;
                $this->RevokeSessions($user);
            }
            $user->save();

            return $this->Profile($user);
        }, 3);
    }

    public function ChangePassword(ChangePasswordRequest $request, User $user): ResponseModel
    {
        return DB::transaction(function () use ($request, $user): ResponseModel {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($request->validated('currentPassword'), $user->password)) {
                return ResponseModel::badRequest('Current password is incorrect!', null);
            }
            $user->password = $request->validated('password');
            $user->remember_token = Str::random(60);
            $user->save();
            $this->RevokeSessions($user);

            return ResponseModel::ok('Password changed successfully! Please log in again.', null);
        }, 3);
    }

    public function ForgotPassword(ForgotPasswordRequest $request): ResponseModel
    {
        Password::sendResetLink(['email' => $request->validated('email'), 'status' => 'active'], function (User $user, string $token): void {
            $user->notify(new PasswordRecoveryNotification($token));
        });

        return ResponseModel::ok('If this email has an active account, password reset instructions have been sent.', null);
    }

    public function ResetPassword(ResetPasswordRequest $request): ResponseModel
    {
        return DB::transaction(function () use ($request): ResponseModel {
            $data = $request->validated();
            User::query()->where('email', $data['email'])->lockForUpdate()->first();
            $status = Password::reset([...$data, 'status' => 'active'], function (User $user, string $password): void {
                $user->password = $password;
                $user->remember_token = Str::random(60);
                $user->save();
                $this->RevokeSessions($user);
                event(new PasswordReset($user));
            });

            return $status === Password::PASSWORD_RESET
                ? ResponseModel::ok('Password reset successfully! Please log in again.', null)
                : ResponseModel::badRequest('This password reset token is invalid or expired!', null);
        }, 3);
    }

    public function Index(AdminListRequest $request): ResponseModel
    {
        $query = User::query()->with('role');
        foreach (['status' => 'status', 'roleId' => 'role_id'] as $field => $column) {
            if ($request->filled($field)) {
                $query->where($column, $request->validated($field));
            }
        }
        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        $users = $query->latest()->latest('id')->paginate($request->integer('perPage', 20));

        return ResponseModel::ok('Accounts fetched successfully!', ['items' => UserResource::collection($users), 'pagination' => ['currentPage' => $users->currentPage(), 'lastPage' => $users->lastPage(), 'perPage' => $users->perPage(), 'total' => $users->total()]]);
    }

    public function Show(string $userId): ResponseModel
    {
        $user = User::query()->with('role')->find($userId);

        return $user === null ? ResponseModel::NotFound('Account was not found!', null) : $this->Profile($user);
    }

    public function Roles(): ResponseModel
    {
        return ResponseModel::ok('Roles fetched successfully!', Role::query()->where('status', 'active')->get(['id', 'name']));
    }

    public function Create(UserManagementRequest $request, User $actor): ResponseModel
    {
        return DB::transaction(function () use ($request, $actor): ResponseModel {
            $data = $request->validated();
            $roleId = $data['roleId'] ?? Role::query()->where('name', 'Customer')->where('status', 'active')->firstOrFail()->id;
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role_id' => $roleId, 'status' => $data['status'] ?? 'active']);
            $this->activities->Record($actor, 'account.created', 'users', $user->id, ['roleId' => $roleId, 'status' => $user->status]);

            return ResponseModel::ok('Account created successfully!', new UserResource($user->load('role')));
        }, 3);
    }

    public function Update(UserManagementRequest $request, string $userId, User $actor): ResponseModel
    {
        return DB::transaction(function () use ($request, $userId, $actor): ResponseModel {
            Role::query()->where('name', 'Super Admin')->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->find($userId);
            if ($user === null) {
                return ResponseModel::NotFound('Account was not found!', null);
            }
            $data = $request->validated();
            if ($user->id === $actor->id && (isset($data['roleId']) || isset($data['status']))) {
                return new ResponseModel(403, 'Use another Super Admin to change your own role or account status!', null);
            }
            $roleId = $data['roleId'] ?? $user->role_id;
            $status = $data['status'] ?? $user->status;
            $newRole = Role::query()->findOrFail($roleId);
            if ($user->isSuperAdministrator() && ($newRole->name !== 'Super Admin' || $status !== 'active')
                && ! User::query()->where('id', '!=', $user->id)->where('status', 'active')->whereHas('role', fn (Builder $query): Builder => $query->where('name', 'Super Admin')->where('status', 'active'))->exists()) {
                return ResponseModel::conflict('The last active Super Admin cannot be removed!', null);
            }
            $sensitiveChange = $roleId !== $user->role_id || $status !== $user->status || (isset($data['email']) && $data['email'] !== $user->email);
            if (isset($data['email']) && $data['email'] !== $user->email) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            foreach (['name', 'email'] as $field) {
                if (isset($data[$field])) {
                    $user->{$field} = $data[$field];
                }
            }
            if (isset($data['email'])) {
                $user->email_verified_at = null;
            }
            $user->role_id = $roleId;
            $user->status = $status;
            $user->save();
            if ($sensitiveChange) {
                $this->RevokeSessions($user);
                $allowedType = $user->isAdministrator() ? 'deposit' : 'withdraw';
                $user->paymentAccounts()->where('type', '!=', $allowedType)->where('status', 'active')->update(['status' => 'inactive']);
            }
            $this->activities->Record($actor, 'account.updated', 'users', $user->id, array_diff_key($data, ['password' => true]));

            return ResponseModel::ok('Account updated successfully!', new UserResource($user->load('role')));
        }, 3);
    }

    private function RevokeSessions(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
