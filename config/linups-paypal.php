<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    | Key names are kept uppercase for backward compatibility with already
    | published config files.
    */
    'PAYPAL_CLIENT_ID' => env('PAYPAL_CLIENT_ID'),
    'PAYPAL_CLIENT_SECRET' => env('PAYPAL_CLIENT_SECRET'),

    // true / "true" / 1 / "1" / "yes" / "on" => sandbox, anything else => live
    'PAYPAL_SANDBOX' => env('PAYPAL_SANDBOX'),

    /*
    |--------------------------------------------------------------------------
    | Checkout defaults (can be overridden per call via $options)
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYPAL_CURRENCY', 'USD'),
    'brand_name' => env('PAYPAL_BRAND_NAME', 'Brittany Watkins'),
    'locale' => env('PAYPAL_LOCALE', 'en-US'),
    'item_category' => env('PAYPAL_ITEM_CATEGORY', 'DIGITAL_GOODS'),

    /*
    |--------------------------------------------------------------------------
    | HTTP / auth
    |--------------------------------------------------------------------------
    */
    // Request timeout in seconds
    'timeout' => env('PAYPAL_TIMEOUT', 30),

    // Cache the OAuth access token (valid ~9h) instead of requesting a new one
    // for every service instance.
    'cache_token' => env('PAYPAL_CACHE_TOKEN', true),
];
