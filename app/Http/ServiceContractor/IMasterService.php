<?php

namespace App\Http\ServiceContractor;

use App\Response\ResponseModel;

interface IMasterService
{
    public function Index(string $version): ResponseModel;

    public function RefreshVersion(): void;
}
