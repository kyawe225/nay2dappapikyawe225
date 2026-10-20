<?php

namespace Database\Seeders;

use App\Models\MasterDataVersion;
use App\Models\PaymentMethod;
use App\Models\SupportType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([PaymentMethodSeeder::class, SupportSeeder::class]);

            $masterData = [
                // 'payment_methods' => PaymentMethod::query()->orderBy('id')->get([
                //     'id', 'name', 'payment_method_image_location', 'type', 'description', 'open_time', 'cutoff_time',
                // ])->toArray(),
                'support_types' => SupportType::query()->orderBy('id')->get(['id', 'name', 'image_location'])->toArray(),
            ];

            $currentVersion = MasterDataVersion::query()->firstOrNew();
            $currentVersion->fill([
                'version' => hash('sha256', json_encode($masterData, JSON_THROW_ON_ERROR)),
            ])->save();
        });
    }
}
