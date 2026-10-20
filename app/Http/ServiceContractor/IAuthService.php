<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Response\ResponseModel;
use Illuminate\Http\Request;

interface IAuthService
{
    public function Login(LoginRequest $request): ResponseModel;

    public function Register(RegisterRequest $request): ResponseModel;

    public function RefreshToken(Request $request): ResponseModel;

    public function Logout(Request $request): ResponseModel;
}
