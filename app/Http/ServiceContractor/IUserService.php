<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\UserManagementRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Response\ResponseModel;

interface IUserService
{
    public function Profile(User $user): ResponseModel;

    public function UpdateProfile(ProfileRequest $request, User $user): ResponseModel;

    public function ChangePassword(ChangePasswordRequest $request, User $user): ResponseModel;

    public function ForgotPassword(ForgotPasswordRequest $request): ResponseModel;

    public function ResetPassword(ResetPasswordRequest $request): ResponseModel;

    public function Index(AdminListRequest $request): ResponseModel;

    public function Show(string $userId): ResponseModel;

    public function Create(UserManagementRequest $request, User $actor): ResponseModel;

    public function Update(UserManagementRequest $request, string $userId, User $actor): ResponseModel;

    public function Roles(): ResponseModel;
}
