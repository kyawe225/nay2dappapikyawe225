<?php

namespace Database\Seeders;

use App\Models\Support;
use App\Models\SupportType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(SupportTypeSeeder::class);

            foreach (['Telegram', 'Viber', 'Phone'] as $name) {
                $supportType = SupportType::query()->where('name', $name)->firstOrFail();

                Support::query()->firstOrCreate(
                    ['support_type_id' => $supportType->id, 'name' => $name.' Support'],
                    [
                        'type' => 'link',
                        'value' => 'http://localhost:8000/storage/1.jpg',
                        'status' => 'inactive',
                    ],
                );
            }
        });
    }
}
