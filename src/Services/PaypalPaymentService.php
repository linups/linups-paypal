<?php

namespace Linups\LinupsPaypal\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Linups\LinupsPaypal\Exceptions\PaypalException;
use Throwable;

/**
 * Thin wrapper around PayPal Orders v2 and Subscriptions v1 REST APIs.
 */
class PaypalPaymentService
{
    public const LIVE_URL = 'https://api-m.paypal.com';
    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    /** Currencies PayPal does not accept decimals for. */
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    /** @var string|null */
    private $accessToken;

    /** @var string */
    private $target;

    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $this->target = $this->isSandbox() ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /* ---------------------------------------------------------------------
     | Public API
     * ------------------------------------------------------------------- */

    /**
     * Creates a PayPal order and returns the URL the buyer must be redirected to.
     *
     * Required options: items[] (productName, amount, optional productDescription,
     * productImage, quantity, category), return_url, cancel_url.
     * Optional options: totalAmount (computed from items if omitted), currency,
     * brand_name, locale, invoice_id, custom_id, soft_descriptor.
     *
     * The created order ID is stored in session('orderID').
     *
     * @return string
     *
     * @throws PaypalException
     */
    public function prepareCharge(array $options)
    {
        $currency = $options['currency'] ?? $this->config('currency', 'USD');
        $brandName = $options['brand_name'] ?? $this->config('brand_name', 'Brittany Watkins');
        $productItems = $this->prepareProductArray($options['items'] ?? [], $currency);

        $total = isset($options['totalAmount'])
            ? $this->formatAmount($options['totalAmount'], $currency)
            : $this->sumItems($productItems, $currency);

        $purchaseUnit = $this->withoutNulls([
            'description' => $productItems[0]['description'] ?? null,
            'soft_descriptor' => $this->truncate($options['soft_descriptor'] ?? $productItems[0]['name'] ?? $brandName, 22),
            'invoice_id' => (string) ($options['invoice_id'] ?? $this->generateInvoiceId()),
            'custom_id' => isset($options['custom_id']) ? (string) $options['custom_id'] : null,
            'amount' => [
                'currency_code' => $currency,
                'value' => $total,
                'breakdown' => [
                    'item_total' => [
                        'currency_code' => $currency,
                        'value' => $total,
                    ],
                ],
            ],
            'items' => $productItems,
        ]);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$purchaseUnit],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'brand_name' => $this->truncate($brandName, 127),
                        'locale' => $options['locale'] ?? $this->config('locale', 'en-US'),
                        'landing_page' => 'LOGIN',
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $options['return_url'],
                        'cancel_url' => $options['cancel_url'],
                    ],
                ],
            ],
        ];

        $response = $this->send('POST', '/v2/checkout/orders', $payload);
        $data = $response->object();

        if (! $response->successful() || empty($data->id)) {
            $this->logFailure('order create', $response);
            throw $this->exceptionFor('PayPal order creation failed', $response);
        }

        session(['orderID' => $data->id]);

        return $this->findLink($data, ['payer-action', 'approve'], 1);
    }

    /**
     * Captures a previously approved order.
     *
     * Order ID resolution: $orderId argument, then session('orderID'),
     * then the `token` query parameter PayPal appends to return_url.
     *
     * @return array{orderID: ?string, status: string, transactionID?: ?string, email?: ?string, amount?: ?string, message?: string}
     */
    public function processCharge(?string $orderId = null)
    {
        $orderID = $orderId ?? session('orderID') ?? request()->get('token');
        $order['orderID'] = $orderID;

        if (empty($orderID)) {
            $order['status'] = 'fail';
            $order['message'] = 'Missing PayPal order ID';

            return $order;
        }

        $response = $this->send('POST', '/v2/checkout/orders/'.rawurlencode($orderID).'/capture', new \stdClass());
        $data = $response->object();

        if (($data->status ?? null) === 'COMPLETED') {
            $capture = $data->purchase_units[0]->payments->captures[0] ?? null;

            $order['status'] = 'success';
            $order['transactionID'] = $capture->id ?? null;
            $order['email'] = $data->payer->email_address ?? null;
            $order['amount'] = $capture->amount->value ?? null;
        } else {
            $order['status'] = 'fail';
            $order['message'] = json_encode($data);
        }

        return $order;
    }

    /**
     * Creates a subscription and returns the URL the buyer must be redirected to.
     *
     * Required options: subscription_plan, return_url, cancel_url.
     * Optional options: customer_id (sent as custom_id), brand_name, locale.
     *
     * @return string
     *
     * @throws PaypalException
     */
    public function prepareSubscription(array $options)
    {
        $payload = $this->withoutNulls([
            'plan_id' => $options['subscription_plan'],
            'custom_id' => isset($options['customer_id']) ? (string) $options['customer_id'] : null,
            'application_context' => $this->withoutNulls([
                'brand_name' => isset($options['brand_name']) ? $this->truncate($options['brand_name'], 127) : null,
                'locale' => $options['locale'] ?? null,
                'return_url' => $options['return_url'],
                'cancel_url' => $options['cancel_url'],
            ]),
        ]);

        $response = $this->send('POST', '/v1/billing/subscriptions', $payload);
        $data = $response->object();

        if (($data->status ?? null) === 'APPROVAL_PENDING') {
            return $this->findLink($data, ['approve'], 0);
        }

        $this->logFailure('subscription prepare', $response);
        throw $this->exceptionFor('Opps. Something wrong. Details in log', $response, false);
    }

    /**
     * Collects subscription details after the buyer returns from PayPal.
     *
     * Expects the return_url query parameters (subscription_id, ba_token, token).
     * Note: always check `subscription_status` (e.g. ACTIVE) before granting access.
     *
     * @return array
     *
     * @throws PaypalException when PayPal cannot return the subscription
     */
    public function subscription_success(array $request)
    {
        if (empty($request['subscription_id'])) {
            return [
                'status' => 'error',
                'error_msg' => 'missing subscription id',
            ];
        }

        $response = $this->send('GET', '/v1/billing/subscriptions/'.rawurlencode($request['subscription_id']));
        $data = $response->object();

        if (! $response->successful() || ! is_object($data)) {
            $this->logFailure('subscription details', $response);
            throw $this->exceptionFor('PayPal subscription lookup failed', $response);
        }

        return [
            'email' => $data->subscriber->email_address ?? null,
            'subscription_id' => $request['subscription_id'],
            'ba_token' => $request['ba_token'] ?? null,
            'token' => $request['token'] ?? null,
            'subscription_status' => $data->status ?? null,
            'custom_id' => $data->custom_id ?? null,
        ];
    }

    /**
     * camelCase alias of subscription_success().
     *
     * @return array
     */
    public function subscriptionSuccess(array $request)
    {
        return $this->subscription_success($request);
    }

    /* ---------------------------------------------------------------------
     | Auth
     * ------------------------------------------------------------------- */

    private function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt !== null && $this->tokenExpiresAt > time()) {
            return $this->accessToken;
        }

        return $this->authorize();
    }

    /**
     * @throws PaypalException
     */
    private function authorize(): string
    {
        $clientId = (string) $this->config('PAYPAL_CLIENT_ID');
        $secret = (string) $this->config('PAYPAL_CLIENT_SECRET');

        if ($clientId === '' || $secret === '') {
            throw new PaypalException('PayPal credentials are not configured (PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET).');
        }

        $cacheKey = 'linups-paypal.token.'.sha1($clientId.'|'.$this->target);

        if ($this->shouldCacheToken()) {
            $cached = $this->cacheGet($cacheKey);

            if (is_array($cached) && ! empty($cached['token']) && ($cached['expires_at'] ?? 0) > time()) {
                $this->accessToken = $cached['token'];
                $this->tokenExpiresAt = (int) $cached['expires_at'];

                return $this->accessToken;
            }
        }

        $response = Http::withBasicAuth($clientId, $secret)
            ->asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($this->target.'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        $data = $response->json();

        if (! $response->successful() || empty($data['access_token'])) {
            throw $this->exceptionFor('PayPal authorization failed', $response);
        }

        // Refresh a minute before PayPal expires it.
        $ttl = max(0, (int) ($data['expires_in'] ?? 0) - 60);

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + $ttl;

        if ($ttl > 0 && $this->shouldCacheToken()) {
            $this->cachePut($cacheKey, ['token' => $this->accessToken, 'expires_at' => $this->tokenExpiresAt], $ttl);
        }

        return $this->accessToken;
    }

    private function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = null;

        if ($this->shouldCacheToken()) {
            $clientId = (string) $this->config('PAYPAL_CLIENT_ID');

            try {
                Cache::forget('linups-paypal.token.'.sha1($clientId.'|'.$this->target));
            } catch (Throwable) {
                // cache unavailable – nothing to forget
            }
        }
    }

    /* ---------------------------------------------------------------------
     | HTTP
     * ------------------------------------------------------------------- */

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->target)
            ->withToken($this->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout());
    }

    /**
     * Sends an authenticated request; on 401 the token is refreshed and the request retried once.
     *
     * @param  array|object|null  $data
     */
    private function send(string $method, string $uri, $data = null): Response
    {
        $attempt = function () use ($method, $uri, $data): Response {
            return $method === 'GET'
                ? $this->client()->get($uri)
                : $this->client()->post($uri, $data ?? new \stdClass());
        };

        $response = $attempt();

        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $attempt();
        }

        return $response;
    }

    /**
     * @param  string[]  $rels
     *
     * @throws PaypalException
     */
    private function findLink(object $response, array $rels, int $legacyIndex): string
    {
        $links = $response->links ?? [];

        foreach ($rels as $rel) {
            foreach ($links as $link) {
                if (($link->rel ?? null) === $rel && ! empty($link->href)) {
                    return $link->href;
                }
            }
        }

        if (! empty($links[$legacyIndex]->href)) {
            return $links[$legacyIndex]->href;
        }

        throw new PaypalException('PayPal response does not contain an approval link.', json_decode(json_encode($response), true));
    }

    private function exceptionFor(string $message, Response $response, bool $withStatus = true): PaypalException
    {
        $json = $response->json();

        return new PaypalException(
            $withStatus ? $message.' (HTTP '.$response->status().').' : $message,
            is_array($json) ? $json : null,
            $response->status()
        );
    }

    private function logFailure(string $operation, Response $response): void
    {
        Log::warning('PayPal '.$operation.' failed', [
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Payload helpers
     * ------------------------------------------------------------------- */

    /**
     * @throws PaypalException
     */
    private function prepareProductArray(array $products, ?string $currency = null): array
    {
        if (count($products) == 0) {
            throw new PaypalException('Empty products array');
        }

        $currency ??= $this->config('currency', 'USD');
        $productArray = [];

        foreach ($products as $product) {
            $productArray[] = $this->withoutNulls([
                'name' => $this->truncate($product['productName'], 127),
                'quantity' => (string) ($product['quantity'] ?? 1),
                'description' => isset($product['productDescription']) && $product['productDescription'] !== ''
                    ? $this->truncate($product['productDescription'], 127)
                    : null,
                'category' => $product['category'] ?? $this->config('item_category', 'DIGITAL_GOODS'),
                'image_url' => ! empty($product['productImage']) ? $product['productImage'] : null,
                'unit_amount' => [
                    'currency_code' => $currency,
                    'value' => $this->formatAmount($product['amount'], $currency),
                ],
            ]);
        }

        return $productArray;
    }

    private function sumItems(array $items, string $currency): string
    {
        $sum = 0.0;

        foreach ($items as $item) {
            $sum += (float) $item['unit_amount']['value'] * (int) $item['quantity'];
        }

        return $this->formatAmount($sum, $currency);
    }

    /**
     * Numbers are formatted as PayPal expects ("10.00"); strings are passed through untouched.
     *
     * @param  int|float|string  $value
     */
    private function formatAmount($value, string $currency): string
    {
        if (is_string($value)) {
            return $value;
        }

        $decimals = in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;

        return number_format((float) $value, $decimals, '.', '');
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function withoutNulls(array $data): array
    {
        return array_filter($data, static fn ($value) => $value !== null);
    }

    private function generateInvoiceId(): string
    {
        return time().'-'.Str::random(6);
    }

    /* ---------------------------------------------------------------------
     | Config
     * ------------------------------------------------------------------- */

    private function config(string $key, $default = null)
    {
        return config('linups-paypal.'.$key, $default) ?? $default;
    }

    private function isSandbox(): bool
    {
        return filter_var($this->config('PAYPAL_SANDBOX'), FILTER_VALIDATE_BOOLEAN);
    }

    private function shouldCacheToken(): bool
    {
        return filter_var($this->config('cache_token', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function timeout(): int
    {
        return (int) $this->config('timeout', 30);
    }

    private function cacheGet(string $key)
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function cachePut(string $key, array $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable) {
            // cache unavailable – token stays in memory for this instance only
        }
    }
}
