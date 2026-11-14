<?php

use App\Http\Controllers\Admin\AdminAuthController;
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
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Response;

Route::name('auth.')->group(function (): void {
    Route::post('auth_forgot_password', [UserController::class, 'ForgotPassword'])->middleware('throttle:5,1')->name('forgot-password');
    Route::post('auth_reset_password', [UserController::class, 'ResetPassword'])->middleware('throttle:10,1')->name('reset-password');
    Route::post('auth_login', [AuthController::class, 'Login'])->middleware('throttle:10,1')->name('login');
    Route::post('auth_register', [AuthController::class, 'Register'])->middleware('throttle:10,1')->name('register');
});

Route::middleware(['auth:sanctum', EnsureActiveAccount::class, 'abilities:access'])->group(function (): void {
    Route::get('user_current', #[Authenticated] #[Response(content: ['id' => '019a0000-0000-7000-8000-000000000001', 'name' => 'Example Customer', 'email' => 'customer@example.com', 'role_name' => 'Customer', 'status' => 'active', 'role' => ['role_name' => 'Customer', 'status' => 'active']])] #[Response(status: 401, content: ['message' => 'Unauthenticated.'])] fn (Request $request) => $request->user()->load('role'))->name('user');
    Route::post('auth_logout', [AuthController::class, 'Logout'])->name('auth.logout');

    Route::get('profile_show', [UserController::class, 'Profile'])->name('web.profile.index');
    Route::patch('profile_update', [UserController::class, 'UpdateProfile'])->name('web.profile.update');
    Route::post('profile_password_update', [UserController::class, 'ChangePassword'])->middleware('throttle:10,1')->name('web.profile.password');

    Route::get('master_list', [MasterController::class, 'index'])->name('web.master.index');
    Route::get('support_list', [SupportController::class, 'Index'])->name('web.support.index');

    Route::get('payment_method_deposit_list', [PaymentMethodController::class, 'DepositPaymentAccounts']);
    Route::get('payment_method_withdrawal_list', [PaymentMethodController::class, 'WithdrawalPaymentAccounts']);

    Route::get('payment_account_withdrawal_list', [PaymentAccountController::class, 'WithdrawalAccounts'])->name('web.paymentaccount.withdrawal.index');
    Route::post('payment_account_withdrawal_create', [PaymentAccountController::class, 'CreateWithdrawalAccount']);
    Route::delete('payment_account_withdrawal_delete/{account}', [PaymentAccountController::class, 'DeleteWithdrawalAccount'])->whereUuid('account')->name('web.paymentaccount.withdrawal.delete');

    Route::post('payment_deposit_create', [PaymentController::class, 'CreateDepositRequest']);
    Route::post('payment_withdrawal_create', [PaymentController::class, 'CreateWithdrawalRequest']);
    Route::get('payment_deposit_history', [PaymentController::class, 'DepositPaymentHistory']);
    Route::get('payment_withdrawal_history', [PaymentController::class, 'WithdrawalHistory']);
    Route::get('payment_withdrawal_request_history', [PaymentController::class, 'WithdrawalRequestHistory'])->name('web.payment.withdrawal.requests');

    Route::get('wallet_show', [WalletController::class, 'Index'])->name('web.wallet.index');
    Route::get('wallet_history', [WalletController::class, 'History'])->name('web.wallet.history');

    Route::group(['where' => ['game' => 'twod|threed']], function (): void {
        Route::get('betting_odds_list/{game}', [BetController::class, 'Odds'])->name('web.betting.odds');
        Route::get('betting_results_list/{game}', [BetController::class, 'Results'])->name('web.betting.results');
        Route::get('betting_draws_list/{game}', [BetController::class, 'AvailableDraws'])->name('web.betting.draws');
        Route::get('betting_bets_list/{game}', [BetController::class, 'Index'])->name('web.betting.index');
        Route::get('betting_bets_show/{game}/{bet}', [BetController::class, 'Show'])->whereUuid('bet')->name('web.betting.show');
        Route::post('betting_bets_create/{game}', [BetController::class, 'Create'])->name('web.betting.create');
    });
    Route::prefix('admin')->name('admin.')->middleware(EnsureAdministrator::class)->group(function (): void {
        Route::get('dashboard_show', [DashboardController::class, 'Index'])->name('dashboard');
        Route::get('dashboard_customers_summary', [DashboardController::class, 'Customers'])->name('dashboard.customers');
        Route::get('activities_list', [DashboardController::class, 'Activities'])->name('activities');
        Route::get('audit_logs_list', [AuditLogController::class, 'Index'])->name('audit-logs');
        Route::name('accounts.wallet.')->group(function (): void {
            Route::get('accounts_wallet_show/{account}', [AdminWalletController::class, 'Show'])->whereUuid('account')->name('show');
            Route::get('accounts_wallet_history/{account}', [AdminWalletController::class, 'History'])->whereUuid('account')->name('history');
            Route::get('accounts_wallet_transaction_show/{account}/{transaction}', [AdminWalletController::class, 'Transaction'])->whereUuid('account')->whereUuid('transaction')->name('transactions.show');
            Route::get('accounts_wallet_withdrawal_show/{account}/{withdrawal}', [AdminWalletController::class, 'Withdrawal'])->whereUuid('account')->whereUuid('withdrawal')->name('withdrawal-requests.show');
            Route::get('accounts_wallet_movement_show/{account}/{movement}', [AdminWalletController::class, 'Movement'])->whereUuid('account')->whereUuid('movement')->name('wallet-transactions.show');
            Route::post('accounts_wallet_create/{account}', [AdminWalletController::class, 'Create'])->whereUuid('account')->name('create');
            Route::patch('accounts_wallet_adjust/{account}', [AdminWalletController::class, 'Adjust'])->whereUuid('account')->name('adjust');
            Route::delete('accounts_wallet_delete/{account}', [AdminWalletController::class, 'Delete'])->whereUuid('account')->name('delete');
        });
        Route::middleware(EnsureSuperAdministrator::class)->group(function (): void {
            Route::get('accounts_list', [AdminUserController::class, 'Index'])->name('accounts.index');
            Route::post('accounts_create', [AdminUserController::class, 'Create'])->name('accounts.create');
            Route::get('accounts_show/{account}', [AdminUserController::class, 'Show'])->whereUuid('account')->name('accounts.show');
            Route::patch('accounts_update/{account}', [AdminUserController::class, 'Update'])->whereUuid('account')->name('accounts.update');
            Route::get('roles_list', [AdminUserController::class, 'Roles'])->name('roles');
        });
        Route::name('supports.')->group(function (): void {
            Route::get('supports_list', [AdminSupportController::class, 'Index'])->name('index');
            Route::post('supports_create', [AdminSupportController::class, 'Create'])->name('create');
            Route::get('supports_show/{record}', [AdminSupportController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('supports_update/{record}', [AdminSupportController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('supports_delete/{record}', [AdminSupportController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::name('support-types.')->group(function (): void {
            Route::get('support_types_list', [SupportTypeController::class, 'Index'])->name('index');
            Route::post('support_types_create', [SupportTypeController::class, 'Create'])->name('create');
            Route::get('support_types_show/{record}', [SupportTypeController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('support_types_update/{record}', [SupportTypeController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('support_types_delete/{record}', [SupportTypeController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::name('payment-methods.')->group(function (): void {
            Route::get('payment_methods_list', [AdminPaymentMethodController::class, 'Index'])->name('index');
            Route::post('payment_methods_create', [AdminPaymentMethodController::class, 'Create'])->name('create');
            Route::get('payment_methods_show/{record}', [AdminPaymentMethodController::class, 'Show'])->whereUuid('record')->name('show');
            Route::patch('payment_methods_update/{record}', [AdminPaymentMethodController::class, 'Update'])->whereUuid('record')->name('update');
            Route::delete('payment_methods_delete/{record}', [AdminPaymentMethodController::class, 'Delete'])->whereUuid('record')->name('delete');
        });
        Route::group(['where' => ['resource' => 'payment-methods|support-types|supports']], function (): void {
            Route::get('master_list/{resource}', [MasterDataController::class, 'Index'])->name('master.index');
            Route::post('master_create/{resource}', [MasterDataController::class, 'Create'])->name('master.create');
            Route::patch('master_update/{resource}/{record}', [MasterDataController::class, 'Update'])->whereUuid('record')->name('master.update');
            Route::delete('master_delete/{resource}/{record}', [MasterDataController::class, 'Delete'])->whereUuid('record')->name('master.delete');
        });

        Route::group(['where' => ['game' => 'twod|threed']], function (): void {
            Route::get('betting_odds_list/{game}', [AdminBetController::class, 'Odds'])->name('betting.odds');
            Route::post('betting_odds_create/{game}', [AdminBetController::class, 'CreateOdd'])->name('betting.odds.create');
            Route::patch('betting_odds_update/{game}/{odd}', [AdminBetController::class, 'UpdateOdd'])->whereUuid('odd')->name('betting.odds.update');
            Route::get('betting_bets_list/{game}', [AdminBetController::class, 'Index'])->name('betting.index');
            Route::get('betting_results_list/{game}', [AdminBetController::class, 'Results'])->name('betting.results');
            Route::post('betting_draws_create/{game}', [AdminBetController::class, 'Schedule'])->name('betting.draws.create');
            Route::patch('betting_draws_cutoff_update/{game}', [AdminBetController::class, 'UpdateDrawCutoff'])->name('betting.draws.cutoff.update');
            Route::get('betting_cutoff_settings_list/{game}', [AdminBetController::class, 'BettingCutoffSettings'])->name('betting.cutoff-settings');
            Route::patch('betting_cutoff_settings_update/{game}', [AdminBetController::class, 'SaveBettingCutoffSetting'])->name('betting.cutoff-settings.update');
            Route::get('betting_calculation_settings_list/{game}', [AdminBetController::class, 'CalculationSettings'])->name('betting.calculation-settings');
            Route::patch('betting_calculation_settings_update/{game}', [AdminBetController::class, 'SaveCalculationSetting'])->name('betting.calculation-settings.update');
            Route::patch('betting_results_calculation_update/{game}', [AdminBetController::class, 'ScheduleCalculation'])->name('betting.calculation.update');
            Route::post('betting_results_calculate_now/{game}', [AdminBetController::class, 'Recalculate'])->name('betting.calculate-now');
            Route::post('betting_results_create/{game}', [AdminBetController::class, 'SaveResult'])->name('betting.results.create');
            Route::post('betting_results_preview/{game}', [AdminBetController::class, 'Preview'])->name('betting.preview');
            Route::post('betting_results_settle/{game}', [AdminBetController::class, 'Settle'])->name('betting.settle');
            Route::post('betting_results_recalculate/{game}', [AdminBetController::class, 'Recalculate'])->name('betting.recalculate');
            Route::post('betting_results_cancel/{game}', [AdminBetController::class, 'Cancel'])->name('betting.cancel');
        });
        Route::get('payment_account_deposit_list', [AdminPaymentAccountController::class, 'DepositAccounts'])->name('paymentaccount.deposit.index');
        Route::post('payment_account_deposit_create', [AdminPaymentAccountController::class, 'CreateDepositAccount'])->name('paymentaccount.deposit.create');
        Route::patch('payment_account_deposit_status_update/{account}', [AdminPaymentAccountController::class, 'ChangeDepositAccountStatus'])->whereUuid('account')->name('paymentaccount.deposit.status');

        Route::get('payment_deposit_list', [AdminPaymentController::class, 'DepositRequests'])->name('payment.deposit.index');
        Route::post('payment_deposit_approve/{transaction}', [AdminPaymentController::class, 'ReviewDeposit'])->whereUuid('transaction')->name('payment.deposit.approve');
        Route::patch('payment_deposit_status_update/{transaction}', [AdminPaymentController::class, 'ReviewDeposit'])->whereUuid('transaction')->name('payment.deposit.status');
        Route::get('payment_withdrawal_list', [AdminPaymentController::class, 'WithdrawalRequests'])->name('payment.withdrawal.index');
        Route::post('payment_withdrawal_approve/{withdrawal}', [AdminPaymentController::class, 'ReviewWithdrawal'])->whereUuid('withdrawal')->name('payment.withdrawal.approve');
        Route::patch('payment_withdrawal_status_update/{withdrawal}', [AdminPaymentController::class, 'ReviewWithdrawal'])->whereUuid('withdrawal')->name('payment.withdrawal.status');
    });
});

Route::post('admin/auth_login', [AdminAuthController::class, 'Login']);
