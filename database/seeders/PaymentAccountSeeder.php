<?php

namespace Database\Seeders;

use App\Models\PaymentAccount;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PaymentAccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([RoleSeeder::class, PaymentMethodSeeder::class]);

            $sampleUsers = [
                ['name' => 'Admin', 'email' => 'test@example.com', 'role' => 'Super Admin', 'phone_number' => '09900000001'],
                ['name' => 'Sample Customer One', 'email' => 'customer1@example.com', 'role' => 'Customer', 'phone_number' => '09900000002'],
                ['name' => 'Sample Customer Two', 'email' => 'customer2@example.com', 'role' => 'Customer', 'phone_number' => '09900000003'],
            ];

            foreach ($sampleUsers as $sampleUser) {
                $user = User::query()->firstOrCreate(
                    ['email' => $sampleUser['email']],
                    [
                        'name' => $sampleUser['name'],
                        'password' => Hash::make('password'),
                        'role_id' => Role::query()->where('name', $sampleUser['role'])->firstOrFail()->id,
                    ],
                );

                $type = $user->isAdministrator() ? 'deposit' : 'withdraw';
                $paymentMethods = PaymentMethod::query()
                    ->whereIn('name', ['KBZPay', 'WavePay'])
                    ->where('type', $type)
                    ->get();

                foreach ($paymentMethods as $paymentMethod) {
                    PaymentAccount::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'payment_method_id' => $paymentMethod->id,
                            'type' => $type,
                        ],
                        [
                            'user_name' => $user->name,
                            'phone_number' => $sampleUser['phone_number'],
                            'bank_account_number' => null,
                            'descriptions' => 'Sample '.$paymentMethod->name.' '.$type.' account.',
                            'status' => 'active',
                        ],
                    );
                }
            }
        });
    }
}
