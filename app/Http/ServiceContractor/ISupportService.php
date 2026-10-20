<?php

namespace App\Http\ServiceContractor;

use App\Response\ResponseModel;

interface ISupportService
{
    public function Index(): ResponseModel;
}
