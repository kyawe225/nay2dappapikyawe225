<?php

namespace App\Http\ServiceContractor;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;

interface IAuthService{
    public function Login(LoginRequest $request);
    public function Register(RegisterRequest $request);
}

?>
