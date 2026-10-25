<?php

namespace App\Providers;

use App\Http\ServiceContractor\IAdminManagementService;
use App\Http\ServiceContractor\IAuthService;
use App\Http\ServiceContractor\IBetService;
use App\Http\ServiceContractor\IMasterService;
use App\Http\ServiceContractor\IPaymentAccountService;
use App\Http\ServiceContractor\IPaymentMethodService;
use App\Http\ServiceContractor\IPaymentService;
use App\Http\ServiceContractor\ISupportService;
use App\Http\ServiceContractor\ISupportTypeService;
use App\Http\ServiceContractor\IUserService;
use App\Http\ServiceContractor\IWalletService;
use App\Service\AdminManagementService;
use App\Service\AuthService;
use App\Service\BetService;
use App\Service\MasterService;
use App\Service\PaymentAccountService;
use App\Service\PaymentMethodService;
use App\Service\PaymentService;
use App\Service\SupportService;
use App\Service\SupportTypeService;
use App\Service\UserService;
use App\Service\WalletService;
use Illuminate\Support\ServiceProvider;

class RepositoryProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(IAuthService::class, AuthService::class);
        $this->app->bind(IUserService::class, UserService::class);
        $this->app->bind(IAdminManagementService::class, AdminManagementService::class);
        $this->app->bind(IBetService::class, BetService::class);
        $this->app->bind(IWalletService::class, WalletService::class);
        $this->app->bind(ISupportService::class, SupportService::class);
        $this->app->bind(ISupportTypeService::class, SupportTypeService::class);
        $this->app->bind(IMasterService::class, MasterService::class);
        $this->app->bind(IPaymentMethodService::class, PaymentMethodService::class);
        $this->app->bind(IPaymentAccountService::class, PaymentAccountService::class);
        $this->app->bind(IPaymentService::class, PaymentService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
