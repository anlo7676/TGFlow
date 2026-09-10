<?php

namespace App\Providers;

use App\Services\Payment\EpayGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Proxy\MTProxyMaxEngine;
use App\Services\Proxy\ProxyEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(PaymentGateway::class, EpayGateway::class);
        $this->app->bind(ProxyEngine::class, MTProxyMaxEngine::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
