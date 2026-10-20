<?php

namespace App\Service;

use App\Http\Resources\SupportTypeResource;
use App\Http\ServiceContractor\IMasterService;
use App\Models\MasterDataVersion;
use App\Models\PaymentMethod;
use App\Models\SupportType;
use App\Response\ResponseModel;

class MasterService implements IMasterService
{
    public function Index(string $version): ResponseModel
    {
        $currentVersion = MasterDataVersion::query()->latest('created_at')->latest('id')->value('version');

        if ($currentVersion !== null && $currentVersion === $version) {
            return ResponseModel::ok('fetched the payment datas successfully!', null);
        }

        return ResponseModel::ok('fetched the payment datas successfully!', [
            'deposit_payment_methods' => PaymentMethod::query()->where('type', 'deposit')->get(),
            'withdrawal_payment_methods' => PaymentMethod::query()->where('type', 'withdraw')->get(),
            'master_version' => $currentVersion,
            'support_types' => SupportTypeResource::collection(SupportType::query()->get()),
        ]);
    }
}
