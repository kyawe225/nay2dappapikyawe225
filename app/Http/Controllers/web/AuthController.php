<?php

namespace App\Http\Controllers\web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Service\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    private AuthService $service;
        public function __construct(AuthService $service){
            $this->service = $service;
        }
        public function Login(LoginRequest $request){
            $response = $this->service->Login($request);
            return response()->json($response);
        }
        public function Register(RegisterRequest $request){
            $response = $this->service->Register($request);
            return response()->json($response);
        }

        public function RefreshToken(Request $request){
            $response = $this->service->RefreshToken($request);
            return response()->json($response);
        }
}
