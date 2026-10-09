<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\web\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(["prefix" => '/auth', "group"=>'auth.'],function($router){
    $router->post('/login',[AuthController::class,"Login"])->name("login");
    $router->post('/register',[AuthController::class,"Register"])->name("register");
});
