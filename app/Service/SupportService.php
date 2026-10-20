<?php

namespace App\Service;

use App\Http\Resources\SupportResource;
use App\Http\ServiceContractor\ISupportService;
use App\Models\Support;
use App\Response\ResponseModel;

class SupportService implements ISupportService
{
    public function Index(): ResponseModel
    {
        $supports = Support::with(['supportType'])
            ->where('status', 'active')
            ->get();

        return ResponseModel::ok(
            'fetched the support data!',
            SupportResource::collection($supports),
        );
    }
}
