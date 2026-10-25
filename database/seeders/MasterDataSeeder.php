<?php

namespace Database\Seeders;

use App\Http\ServiceContractor\IMasterService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([PaymentMethodSeeder::class, SupportSeeder::class]);
            app(IMasterService::class)->RefreshVersion();
        });
    }
}
