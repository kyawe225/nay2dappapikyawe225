<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\MasterController;
use App\Http\Controllers\Web\PaymentAccountController;
use App\Http\Controllers\Web\PaymentMethodController;
use App\Http\Controllers\Web\SupportController;
use App\Http\Controllers\Web\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [AuthController::class, 'Login'])->middleware('throttle:10,1')->name('login');
    Route::post('register', [AuthController::class, 'Register'])->middleware('throttle:10,1')->name('register');
    Route::post('refresh', [AuthController::class, 'RefreshToken'])
        ->middleware(['auth:sanctum', 'abilities:refresh', 'throttle:30,1'])->name('refresh');
});

Route::middleware(['auth:sanctum', 'abilities:access'])->group(function (): void {
    Route::get('user', fn (Request $request) => $request->user()->load('role'))->name('user');
    Route::post('auth/logout', [AuthController::class, 'Logout'])->name('auth.logout');

    Route::get('master', [MasterController::class, 'index'])->name('web.master.index');

    Route::get('support', [SupportController::class, 'Index'])->name('web.support.index');

    Route::get('paymentmethod/deposit', [PaymentMethodController::class, 'DepositPaymentAccounts']);
    Route::get('paymentmethod/withdrawal', [PaymentMethodController::class, 'WithdrawalPaymentAccounts']);


    // Route::post('paymentaccount/deposit', [PaymentAccountController::class, 'createDepositAccount']);
    Route::post('paymentaccount/withdrawal', [PaymentAccountController::class, 'CreateWithdrawalAccount']);
    Route::post('payment/deposit', [PaymentController::class, 'CreateDepositRequest']);
    Route::post('payment/withdrawal', [PaymentController::class, 'CreateWithdrawalRequest']);
    // Route::prefix('admin')->name('admin.')->middleware(EnsureAdministrator::class)->group(function (): void {
    //     Route::apiResource('payment-methods', PaymentMethodController::class)->whereNumber('payment_method');
    //     Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    //     Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction')->name('transactions.show');
    //     Route::get('payments', [TransactionController::class, 'payments'])->name('payments.index');
    //     Route::get('payments/{payment}', [TransactionController::class, 'payment'])->whereNumber('payment')->name('payments.show');
    //     Route::patch('payments/{payment}/status', [TransactionController::class, 'changeStatus'])->whereNumber('payment')->name('payments.status');
    // });
});
