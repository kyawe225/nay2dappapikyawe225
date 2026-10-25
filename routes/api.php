<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BetController as AdminBetController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\PaymentAccountController as AdminPaymentAccountController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PaymentMethodController as AdminPaymentMethodController;
use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Admin\SupportTypeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BetController;
use App\Http\Controllers\Web\MasterController;
use App\Http\Controllers\Web\PaymentAccountController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\PaymentMethodController;
use App\Http\Controllers\Web\SupportController;
use App\Http\Controllers\Web\UserController;
use App\Http\Controllers\Web\WalletController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureSuperAdministrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('forgot-password', [UserController::class, 'ForgotPassword'])->middleware('throttle:5,1')->name('forgot-password');
    Route::post('reset-password', [UserController::class, 'ResetPassword'])->middleware('throttle:10,1')->name('reset-password');
    Route::post('login', [AuthController::class, 'Login'])->middleware('throttle:10,1')->name('login');
    Route::post('register', [AuthController::class, 'Register'])->middleware('throttle:10,1')->name('register');
    Route::post('refresh', [AuthController::class, 'RefreshToken'])
        ->middleware(['auth:sanctum', EnsureActiveAccount::class, 'abilities:refresh', 'throttle:30,1'])->name('refresh');
});

Route::middleware(['auth:sanctum', EnsureActiveAccount::class, 'abilities:access'])->group(function (): void {
    Route::get('user', fn (Request $request) => $request->user()->load('role'))->name('user');
    Route::post('auth/logout', [AuthController::class, 'Logout'])->name('auth.logout');

    Route::get('profile', [UserController::class, 'Profile'])->name('web.profile.index');
    Route::patch('profile', [UserController::class, 'UpdateProfile'])->name('web.profile.update');
    Route::post('profile/password', [UserController::class, 'ChangePassword'])->middleware('throttle:10,1')->name('web.profile.password');

    Route::get('master', [MasterController::class, 'index'])->name('web.master.index');
    Route::get('support', [SupportController::class, 'Index'])->name('web.support.index');

    Route::get('paymentmethod/deposit', [PaymentMethodController::class, 'DepositPaymentAccounts']);
    Route::get('paymentmethod/withdrawal', [PaymentMethodController::class, 'WithdrawalPaymentAccounts']);

    Route::get('paymentaccount/withdrawal', [PaymentAccountController::class, 'WithdrawalAccounts'])->name('web.paymentaccount.withdrawal.index');
    Route::post('paymentaccount/withdrawal', [PaymentAccountController::class, 'CreateWithdrawalAccount']);
    Route::delete('paymentaccount/withdrawal/{account}', [PaymentAccountController::class, 'DeleteWithdrawalAccount'])->whereUuid('account')->name('web.paymentaccount.withdrawal.delete');

    Route::post('payment/deposit', [PaymentController::class, 'CreateDepositRequest']);
    Route::post('payment/withdrawal', [PaymentController::class, 'CreateWithdrawalRequest']);
    Route::get('payment/deposit/history', [PaymentController::class, 'DepositPaymentHistory']);
    Route::get('payment/withdrawal/history', [PaymentController::class, 'WithdrawalHistory']);
    Route::get('payment/withdrawal/request/history', [PaymentController::class, 'WithdrawalRequestHistory'])->name('web.payment.withdrawal.requests');

    Route::get('wallet', [WalletController::class, 'Index'])->name('web.wallet.index');
    Route::get('wallet/transactions', [WalletController::class, 'History'])->name('web.wallet.history');

    Route::group(['prefix' => '{game}', 'where' => ['game' => 'twod|threed']], function (): void {
        Route::get('odds', [BetController::class, 'Odds'])->name('web.betting.odds');
        Route::get('results', [BetController::class, 'Results'])->name('web.betting.results');
        Route::get('bets', [BetController::class, 'Index'])->name('web.betting.index');
        Route::get('bets/{bet}', [BetController::class, 'Show'])->whereUuid('bet')->name('web.betting.show');
        Route::post('bets', [BetController::class, 'Create'])->name('web.betting.create');
    });
    Route::prefix('admin')->name('admin.')->middleware(EnsureAdministrator::class)->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'Index'])->name('dashboard');
        Route::get('activities', [DashboardController::class, 'Activities'])->name('activities');
        Route::get('audit-logs', [AuditLogController::class, 'Index'])->name('audit-logs');
        Route::prefix('accounts/{account}/wallet')->whereUuid('account')->name('accounts.wallet.')->group(function (): void {
            Route::get('/', [AdminWalletController::class, 'Show'])->name('show');
            Route::get('history', [AdminWalletController::class, 'History'])->name('history');
            Route::get('transactions/{transaction}', [AdminWalletController::class, 'Transaction'])->whereUuid('transaction')->name('transactions.show');
            Route::get('withdrawal-requests/{withdrawal}', [AdminWalletController::class, 'Withdrawal'])->whereUuid('withdrawal')->name('withdrawal-requests.show');
            Route::get('wallet-transactions/{movement}', [AdminWalletController::class, 'Movement'])->whereUuid('movement')->name('wallet-transactions.show');
            Route::post('/', [AdminWalletController::class, 'Create'])->name('create');
            Route::patch('/', [AdminWalletController::class, 'Adjust'])->name('adjust');
            Route::delete('/', [AdminWalletController::class, 'Delete'])->name('delete');
        });
        Route::get('accounts/{account}/history', [DashboardController::class, 'CustomerHistory'])->whereUuid('account')->name('accounts.history');
        Route::middleware(EnsureSuperAdministrator::class)->group(function (): void {
            Route::get('accounts', [AdminUserController::class, 'Index'])->name('accounts.index');
            Route::post('accounts', [AdminUserController::class, 'Create'])->name('accounts.create');
            Route::get('accounts/{account}', [AdminUserController::class, 'Show'])->whereUuid('account')->name('accounts.show');
            Route::patch('accounts/{account}', [AdminUserController::class, 'Update'])->whereUuid('account')->name('accounts.update');
            Route::get('roles', [AdminUserController::class, 'Roles'])->name('roles');
        });
        Route::prefix('supports')->name('supports.')->group(function (): void {
            Route::get('/', [AdminSupportController::class, 'Index'])->name('index');
            Route::post('/', [AdminSupportController::class, 'Create'])->name('create');
            Route::get('{record}', [AdminSupportController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('{record}', [AdminSupportController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('{record}', [AdminSupportController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::prefix('support-types')->name('support-types.')->group(function (): void {
            Route::get('/', [SupportTypeController::class, 'Index'])->name('index');
            Route::post('/', [SupportTypeController::class, 'Create'])->name('create');
            Route::get('{record}', [SupportTypeController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('{record}', [SupportTypeController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('{record}', [SupportTypeController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::prefix('payment-methods')->name('payment-methods.')->group(function (): void {
            Route::get('/', [AdminPaymentMethodController::class, 'Index'])->name('index');
            Route::post('/', [AdminPaymentMethodController::class, 'Create'])->name('create');
            Route::get('{record}', [AdminPaymentMethodController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('{record}', [AdminPaymentMethodController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('{record}', [AdminPaymentMethodController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::group(['prefix' => 'master/{resource}', 'where' => ['resource' => 'payment-methods|support-types|supports']], function (): void {
            Route::get('/', [MasterDataController::class, 'Index'])->name('master.index');
            Route::post('/', [MasterDataController::class, 'Create'])->name('master.create');
            Route::patch('{record}', [MasterDataController::class, 'Update'])->whereUuid('record')->name('master.update');
            Route::delete('{record}', [MasterDataController::class, 'Delete'])->whereUuid('record')->name('master.delete');
        });

        Route::group(['prefix' => '{game}', 'where' => ['game' => 'twod|threed']], function (): void {
            Route::get('odds', [AdminBetController::class, 'Odds'])->name('betting.odds');
            Route::post('odds', [AdminBetController::class, 'CreateOdd'])->name('betting.odds.create');
            Route::patch('odds/{odd}', [AdminBetController::class, 'UpdateOdd'])->whereUuid('odd')->name('betting.odds.update');
            Route::get('bets', [AdminBetController::class, 'Index'])->name('betting.index');
            Route::get('results', [AdminBetController::class, 'Results'])->name('betting.results');
            Route::post('results', [AdminBetController::class, 'SaveResult'])->name('betting.results.create');
            Route::post('results/preview', [AdminBetController::class, 'Preview'])->name('betting.preview');
            Route::post('results/settle', [AdminBetController::class, 'Settle'])->name('betting.settle');
            Route::post('results/recalculate', [AdminBetController::class, 'Recalculate'])->name('betting.recalculate');
            Route::post('results/cancel', [AdminBetController::class, 'Cancel'])->name('betting.cancel');
        });
        Route::get('paymentaccount/deposit', [AdminPaymentAccountController::class, 'DepositAccounts'])->name('paymentaccount.deposit.index');
        Route::post('paymentaccount/deposit', [AdminPaymentAccountController::class, 'CreateDepositAccount'])->name('paymentaccount.deposit.create');
        Route::patch('paymentaccount/deposit/{account}/status', [AdminPaymentAccountController::class, 'ChangeDepositAccountStatus'])->whereUuid('account')->name('paymentaccount.deposit.status');

        Route::get('payment/deposit', [AdminPaymentController::class, 'DepositRequests'])->name('payment.deposit.index');
        Route::patch('payment/deposit/{transaction}/status', [AdminPaymentController::class, 'ReviewDeposit'])->whereUuid('transaction')->name('payment.deposit.status');
        Route::get('payment/withdrawal', [AdminPaymentController::class, 'WithdrawalRequests'])->name('payment.withdrawal.index');
        Route::patch('payment/withdrawal/{withdrawal}/status', [AdminPaymentController::class, 'ReviewWithdrawal'])->whereUuid('withdrawal')->name('payment.withdrawal.status');
    });
});
