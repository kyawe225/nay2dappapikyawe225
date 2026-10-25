<?php

namespace App\Service;

use App\Http\Resources\SupportTypeResource;
use App\Http\ServiceContractor\IMasterService;
use App\Models\MasterDataVersion;
use App\Models\PaymentMethod;
use App\Models\Support;
use App\Models\SupportType;
use App\Response\ResponseModel;
use Illuminate\Support\Facades\DB;

class MasterService implements IMasterService
{
    public function Index(string $version): ResponseModel
    {
        return DB::transaction(function () use ($version): ResponseModel {
            $currentVersion = $this->Version();
            if ($currentVersion === $version) {
                return ResponseModel::ok('Master data is unchanged!', null);
            }

            return ResponseModel::ok('Master data fetched successfully!', [
                'deposit_payment_methods' => PaymentMethod::query()->where('type', 'deposit')->where('status', 'active')->get(),
                'withdrawal_payment_methods' => PaymentMethod::query()->where('type', 'withdraw')->where('status', 'active')->get(),
                'master_version' => $currentVersion,
                'support_types' => SupportTypeResource::collection(SupportType::query()->get()),
            ]);
        });
    }

    public function RefreshVersion(): void
    {
        $version = MasterDataVersion::query()->firstOrNew();
        $version->version = $this->Version();
        $version->save();
    }

    private function Version(): string
    {
        return hash('sha256', json_encode([
            'payment_methods' => PaymentMethod::query()->orderBy('id')->get(['id', 'name', 'type', 'description', 'payment_method_image_location', 'status'])->toArray(),
            'support_types' => SupportType::query()->orderBy('id')->get(['id', 'name', 'image_location'])->toArray(),
            'supports' => Support::query()->orderBy('id')->get(['id', 'support_type_id', 'name', 'type', 'value', 'status'])->toArray(),
        ], JSON_THROW_ON_ERROR));
    }
}
