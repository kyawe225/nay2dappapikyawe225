<?php

namespace App\Response;

class ResponseModel
{
    public function __construct(public int $status, public string $message, public mixed $data) {}

    public static function ok(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(200, $message, $data);
    }

    public static function badRequest(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(400, $message, $data);
    }

    public static function conflict(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(409, $message, $data);
    }

    public static function unauthorized(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(401, $message, $data);
    }

    public static function internalServer(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(500, $message, $data);
    }

    public static function NotFound(string $message, mixed $data): ResponseModel
    {
        return new ResponseModel(404, $message, $data);
    }
}
