<?php

namespace App\Providers;

use App\Http\ServiceContractor\IAuthService;
use App\Http\ServiceContractor\IMasterService;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Http\ServiceContractor\ISupportService;
use App\Service\AuthService;
use App\Service\MasterService;
use App\Service\PaymentAccountService;
use App\Service\PaymentMethodService;
use App\Service\PaymentService;
use App\Service\SupportService;
use Illuminate\Support\ServiceProvider;

class RepositoryProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(IAuthService::class, AuthService::class);
        $this->app->bind(ISupportService::class, SupportService::class);
        $this->app->bind(IMasterService::class, MasterService::class);
        $this->app->bind(IPaymentMethodService::class, PaymentMethodService::class);
        $this->app->bind(IPaymentAccountService::class, PaymentAccountService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
