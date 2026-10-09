<?php

namespace App\Response;

class ResponseModel
{
    public int $status;
    public string $message;
    public $data;

    public function __construct(int $status, string $message, $data)
    {
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
    }

    public static function ok(string $message, $data): ResponseModel
    {
        return new ResponseModel(200, $message, $data);
    }

    public static function badRequest(string $message, $data): ResponseModel
    {
        return new ResponseModel(400, $message, $data);
    }


    public static function internalServer(string $message, $data): ResponseModel
    {
        return new ResponseModel(500, $message, $data);
    }

    public static function NotFound(string $message, $data): ResponseModel
    {
        return new ResponseModel(404, $message, $data);
    }
}

?>
