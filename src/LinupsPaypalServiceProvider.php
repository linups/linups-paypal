<?php

namespace Linups\LinupsPaypal;

use Illuminate\Support\ServiceProvider;

class LinupsPaypalServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        //
    }


    public function boot(): void
    {
        // In addition to publishing assets, we also publish the config
        $this->publishes([
            __DIR__.'/../config/linups-paypal.php' => config_path('linups-paypal.php'),
        ], 'linups-paypal-config');
    }
}
