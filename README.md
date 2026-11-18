# linups/linups-paypal

Laravel wrapper for PayPal **Orders v2** (one-off payments) and **Subscriptions v1**.

Supports PHP 8.1+ and Laravel 10–13.

## Installation

```bash
composer require linups/linups-paypal
```

The service provider is auto-discovered. Publishing the config is optional:

```bash
php artisan vendor:publish --tag=linups-paypal-config
```

## Configuration (`.env`)

| Variable | Default | Notes |
|---|---|---|
| `PAYPAL_CLIENT_ID` | – | required |
| `PAYPAL_CLIENT_SECRET` | – | required |
| `PAYPAL_SANDBOX` | live | `true`, `1`, `yes`, `on` → sandbox |
| `PAYPAL_CURRENCY` | `USD` | |
| `PAYPAL_BRAND_NAME` | `Brittany Watkins` | shown on the PayPal checkout page |
| `PAYPAL_LOCALE` | `en-US` | |
| `PAYPAL_ITEM_CATEGORY` | `DIGITAL_GOODS` | |
| `PAYPAL_TIMEOUT` | `30` | seconds |
| `PAYPAL_CACHE_TOKEN` | `true` | cache the OAuth token in the Laravel cache (~9h) |

## Usage

Resolve the service from the container (singleton) or instantiate it directly:

```php
use Linups\LinupsPaypal\Services\PaypalPaymentService;

$paypal = app(PaypalPaymentService::class); // or: new PaypalPaymentService()
```

### One-off payment

```php
// 1. Create order and redirect the buyer
$url = $paypal->prepareCharge([
    'items' => [
        [
            'productName' => 'Course',
            'productDescription' => 'Lifetime access',   // optional
            'productImage' => 'https://…/image.png',      // optional
            'amount' => '49.00',
            'quantity' => 1,                              // optional, default 1
        ],
    ],
    'totalAmount' => '49.00',          // optional, computed from items when omitted
    'return_url' => route('paypal.return'),
    'cancel_url' => route('paypal.cancel'),
    // optional: currency, brand_name, locale, invoice_id, custom_id, soft_descriptor
]);

return redirect()->away($url);

// 2. On return_url: capture
$result = $paypal->processCharge(); // or processCharge($orderId)
// ['orderID' => …, 'status' => 'success', 'transactionID' => …, 'email' => …, 'amount' => …]
// ['orderID' => …, 'status' => 'fail', 'message' => '<PayPal response JSON>']
```

The order ID is stored in `session('orderID')`; `processCharge()` falls back to the
`token` query parameter PayPal appends to `return_url`.

### Subscription

```php
$url = $paypal->prepareSubscription([
    'subscription_plan' => 'P-XXXXXXXX',
    'customer_id' => $user->id,        // sent as custom_id
    'return_url' => route('paypal.subscription.return'),
    'cancel_url' => route('paypal.cancel'),
    // optional: brand_name, locale
]);

// On return_url
$result = $paypal->subscriptionSuccess($request->query()); // alias of subscription_success()
// ['email', 'subscription_id', 'ba_token', 'token', 'subscription_status', 'custom_id']
```

Always check `subscription_status` (e.g. `ACTIVE`) before granting access – the return URL can be opened by anyone.

### Errors

API failures throw `Linups\LinupsPaypal\Exceptions\PaypalException` (extends `\Exception`).
`$e->getResponse()` returns the decoded PayPal error body and `$e->getCode()` the HTTP status.
`processCharge()` never throws for a declined capture – it returns `status => 'fail'`.

## Testing

```bash
composer install
composer test
```
