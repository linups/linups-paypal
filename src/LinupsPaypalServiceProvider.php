<?php

namespace Linups\LinupsPaypal;

use Illuminate\Support\ServiceProvider;
use Linups\LinupsPaypal\Services\PaypalPaymentService;

class LinupsPaypalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Package defaults; a published config/linups-paypal.php still takes precedence.
        $this->mergeConfigFrom(__DIR__.'/../config/linups-paypal.php', 'linups-paypal');

        $this->app->singleton(PaypalPaymentService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/linups-paypal.php' => config_path('linups-paypal.php'),
            ], 'linups-paypal-config');
        }
    }
}
