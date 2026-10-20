<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['KBZPay', 'WavePay'] as $name) {
            foreach (['deposit', 'withdraw'] as $type) {
                PaymentMethod::query()->firstOrCreate(
                    ['name' => $name, 'type' => $type],
                    [
                        'payment_method_image_location' => null,
                        'description' => 'Sample payment method. Configure payment accounts before use.',
                    ],
                );
            }
        }
    }
}
