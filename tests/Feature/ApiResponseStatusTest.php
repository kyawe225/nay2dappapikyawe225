<?php

use App\Http\ServiceContractor\IMasterService;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Http\ServiceContractor\IPaymentService;
use App\Http\ServiceContractor\ISupportService;
use App\Models\User;
use App\Response\ResponseModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('controller HTTP status matches its service response status', function (string $path, string $contract, string $method): void {
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create(['role_name' => 'Super Admin']);
    Sanctum::actingAs($administrator, ['access']);
    $this->mock($contract, function (MockInterface $mock) use ($method): void {
        $mock->shouldReceive($method)->once()->andReturn(ResponseModel::internalServer('Request failed!', null));
    });

    $this->getJson($path)->assertInternalServerError()->assertExactJson(['status' => 500, 'message' => 'Request failed!', 'data' => null]);
})->with([
    'support' => ['/api/support_list', ISupportService::class, 'Index'],
    'master' => ['/api/master_list', IMasterService::class, 'Index'],
    'deposit methods' => ['/api/payment_method_deposit_list', IPaymentMethodService::class, 'DepositPaymentAccounts'],
    'withdrawal methods' => ['/api/payment_method_withdrawal_list', IPaymentMethodService::class, 'WithdrawalPaymentAccounts'],
    'deposit history' => ['/api/payment_deposit_history', IPaymentService::class, 'DepositHistory'],
    'withdrawal history' => ['/api/payment_withdrawal_history', IPaymentService::class, 'WithdrawHistory'],
    'withdrawal request history' => ['/api/payment_withdrawal_request_history', IPaymentService::class, 'WithdrawRequestHistory'],
    'withdrawal accounts' => ['/api/payment_account_withdrawal_list', IPaymentAccountService::class, 'WithdrawalAccounts'],
    'wallet' => ['/api/wallet_show', IPaymentService::class, 'Wallet'],
    'wallet movements' => ['/api/wallet_history', IPaymentService::class, 'WalletHistory'],
    'admin deposits' => ['/api/admin/payment_deposit_list', IPaymentService::class, 'DepositRequests'],
    'admin withdrawals' => ['/api/admin/payment_withdrawal_list', IPaymentService::class, 'WithdrawalRequests'],
    'admin deposit accounts' => ['/api/admin/payment_account_deposit_list', IPaymentAccountService::class, 'DepositAccounts'],
]);
