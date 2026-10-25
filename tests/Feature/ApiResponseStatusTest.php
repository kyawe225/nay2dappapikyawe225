<?php

use App\Http\ServiceContractor\IMasterService;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Http\ServiceContractor\IPaymentService;
use App\Http\ServiceContractor\ISupportService;
use App\Models\Role;
use App\Models\User;
use App\Response\ResponseModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('controller HTTP status matches its service response status', function (string $path, string $contract, string $method): void {
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create(['role_id' => Role::query()->where('name', 'Super Admin')->firstOrFail()->id]);
    Sanctum::actingAs($administrator, ['access']);
    $this->mock($contract, function (MockInterface $mock) use ($method): void {
        $mock->shouldReceive($method)->once()->andReturn(ResponseModel::internalServer('Request failed!', null));
    });

    $this->getJson($path)->assertInternalServerError()->assertExactJson(['status' => 500, 'message' => 'Request failed!', 'data' => null]);
})->with([
    'support' => ['/api/support', ISupportService::class, 'Index'],
    'master' => ['/api/master', IMasterService::class, 'Index'],
    'deposit methods' => ['/api/paymentmethod/deposit', IPaymentMethodService::class, 'DepositPaymentAccounts'],
    'withdrawal methods' => ['/api/paymentmethod/withdrawal', IPaymentMethodService::class, 'WithdrawalPaymentAccounts'],
    'deposit history' => ['/api/payment/deposit/history', IPaymentService::class, 'DepositHistory'],
    'withdrawal history' => ['/api/payment/withdrawal/history', IPaymentService::class, 'WithdrawHistory'],
    'withdrawal request history' => ['/api/payment/withdrawal/request/history', IPaymentService::class, 'WithdrawRequestHistory'],
    'withdrawal accounts' => ['/api/paymentaccount/withdrawal', IPaymentAccountService::class, 'WithdrawalAccounts'],
    'wallet' => ['/api/wallet', IPaymentService::class, 'Wallet'],
    'wallet movements' => ['/api/wallet/transactions', IPaymentService::class, 'WalletHistory'],
    'admin deposits' => ['/api/admin/payment/deposit', IPaymentService::class, 'DepositRequests'],
    'admin withdrawals' => ['/api/admin/payment/withdrawal', IPaymentService::class, 'WithdrawalRequests'],
    'admin deposit accounts' => ['/api/admin/paymentaccount/deposit', IPaymentAccountService::class, 'DepositAccounts'],
]);
