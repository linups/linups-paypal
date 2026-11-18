<?php

namespace Linups\LinupsPaypal\Tests;

use Linups\LinupsPaypal\LinupsPaypalServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LinupsPaypalServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('linups-paypal.PAYPAL_CLIENT_ID', 'client-id');
        $app['config']->set('linups-paypal.PAYPAL_CLIENT_SECRET', 'client-secret');
        $app['config']->set('linups-paypal.PAYPAL_SANDBOX', true);
    }
}
