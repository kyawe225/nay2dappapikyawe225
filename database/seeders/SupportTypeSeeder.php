<?php

namespace Database\Seeders;

use App\Models\SupportType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class SupportTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Telegram', 'Viber', 'Phone'] as $index => $name) {
            $imageLocation = ($index + 1).'.jpg';
            Storage::disk('public')->put($imageLocation, file_get_contents(public_path('images/support/'.$imageLocation)));

            SupportType::query()->firstOrCreate(
                ['name' => $name],
                ['image_location' => $imageLocation],
            );
        }
    }
}
