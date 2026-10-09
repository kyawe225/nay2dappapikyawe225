<?php

namespace App\Service;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\ServiceContractor\IAuthService;
use App\Models\User;
use App\Response\ResponseModel;
use Hash;
use Illuminate\Support\Carbon;

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

        $expired_at = Carbon::now()->addMinutes(30);

        $token = $user->createToken("accessToken")->plainTextToken;

        return ResponseModel::ok("Login Successfully!", [
            "accessToken" => $token,
            "expired_at" => $expired_at,
        ]);
    }

    public function Register(RegisterRequest $request) {
        $arr = $request->validated();

                $user = User::where("email", $arr["email"])->first();

                if ($user == null) {
                    return ResponseModel::NotFound("User Not Found", null);
                }

                if (!Hash::check($arr["password"], $user->password)) {
                    return ResponseModel::badRequest("Email or password wrong.", null);
                }

                $expired_at = Carbon::now()->addMinutes(30);

                $token = $user->createToken("accessToken")->plainTextToken;

                return ResponseModel::ok("Login Successfully!", [
                    "accessToken" => $token,
                    "expired_at"=>$expired_at
                ]);
    }
}
?>
