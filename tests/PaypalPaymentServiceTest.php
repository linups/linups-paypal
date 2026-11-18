<?php

namespace Linups\LinupsPaypal\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Linups\LinupsPaypal\Exceptions\PaypalException;
use Linups\LinupsPaypal\Services\PaypalPaymentService;
use PHPUnit\Framework\Attributes\DataProvider;

class PaypalPaymentServiceTest extends TestCase
{
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';
    private const LIVE = 'https://api-m.paypal.com';

    private function tokenResponse(): array
    {
        return ['*/v1/oauth2/token' => Http::response(['access_token' => 'TOKEN', 'token_type' => 'Bearer', 'expires_in' => 32400])];
    }

    private function chargeOptions(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['productName' => 'Course', 'productDescription' => 'Great course', 'productImage' => 'https://example.com/i.png', 'amount' => '49.00'],
            ],
            'totalAmount' => '49.00',
            'return_url' => 'https://example.com/ok',
            'cancel_url' => 'https://example.com/cancel',
        ], $overrides);
    }

    private function orderCreated(?array $links = null): array
    {
        return ['*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER123',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => $links ?? [
                ['href' => self::SANDBOX.'/v2/checkout/orders/ORDER123', 'rel' => 'self'],
                ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'rel' => 'payer-action'],
            ],
        ])];
    }

    public function test_constructor_does_not_call_paypal(): void
    {
        Http::fake();

        new PaypalPaymentService();

        Http::assertNothingSent();
    }

    public function test_service_is_resolvable_from_container_and_config_is_merged(): void
    {
        $this->assertInstanceOf(PaypalPaymentService::class, app(PaypalPaymentService::class));
        $this->assertSame('USD', config('linups-paypal.currency'));
        $this->assertSame('DIGITAL_GOODS', config('linups-paypal.item_category'));
    }

    public function test_token_is_fetched_once_and_cached_between_instances(): void
    {
        Http::fake($this->tokenResponse() + $this->orderCreated());

        (new PaypalPaymentService())->prepareCharge($this->chargeOptions());
        (new PaypalPaymentService())->prepareCharge($this->chargeOptions());

        Http::assertSentCount(3); // 1 token + 2 orders
    }

    #[DataProvider('sandboxValues')]
    public function test_sandbox_flag_parsing($value, string $expectedHost): void
    {
        config(['linups-paypal.PAYPAL_SANDBOX' => $value]);
        Http::fake($this->tokenResponse() + $this->orderCreated());

        (new PaypalPaymentService())->prepareCharge($this->chargeOptions());

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), $expectedHost.'/v2/checkout/orders'));
    }

    public static function sandboxValues(): array
    {
        return [
            'bool true' => [true, self::SANDBOX],
            'string 1' => ['1', self::SANDBOX],
            'string true' => ['true', self::SANDBOX],
            'bool false' => [false, self::LIVE],
            'null' => [null, self::LIVE],
        ];
    }

    public function test_prepare_charge_sends_legacy_defaults_and_stores_order_id(): void
    {
        Http::fake($this->tokenResponse() + $this->orderCreated());

        $url = (new PaypalPaymentService())->prepareCharge($this->chargeOptions());

        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', $url);
        $this->assertSame('ORDER123', session('orderID'));

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/v2/checkout/orders')) {
                return false;
            }
            $unit = $r['purchase_units'][0];
            $ctx = $r['payment_source']['paypal']['experience_context'];

            return $r->hasHeader('Authorization', 'Bearer TOKEN')
                && $r['intent'] === 'CAPTURE'
                && $unit['amount']['currency_code'] === 'USD'
                && $unit['amount']['value'] === '49.00'
                && $unit['items'][0]['category'] === 'DIGITAL_GOODS'
                && $unit['items'][0]['quantity'] === '1'
                && $unit['items'][0]['image_url'] === 'https://example.com/i.png'
                && $unit['description'] === 'Great course'
                && $ctx['brand_name'] === 'Brittany Watkins'
                && $ctx['locale'] === 'en-US'
                && $ctx['return_url'] === 'https://example.com/ok';
        });
    }

    public function test_prepare_charge_finds_link_by_rel_regardless_of_order(): void
    {
        Http::fake($this->tokenResponse() + $this->orderCreated([
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'rel' => 'payer-action'],
            ['href' => self::SANDBOX.'/v2/checkout/orders/ORDER123', 'rel' => 'self'],
        ]));

        $url = (new PaypalPaymentService())->prepareCharge($this->chargeOptions());

        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', $url);
    }

    public function test_prepare_charge_respects_paypal_field_limits_and_computes_total(): void
    {
        Http::fake($this->tokenResponse() + $this->orderCreated());

        (new PaypalPaymentService())->prepareCharge($this->chargeOptions([
            'items' => [
                ['productName' => str_repeat('N', 200), 'productDescription' => str_repeat('D', 200), 'amount' => 10, 'quantity' => 2],
                ['productName' => 'Second', 'amount' => 5.5],
            ],
            'totalAmount' => null,
        ]));

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/v2/checkout/orders')) {
                return false;
            }
            $unit = $r['purchase_units'][0];

            return mb_strlen($unit['soft_descriptor']) === 22
                && mb_strlen($unit['items'][0]['name']) === 127
                && mb_strlen($unit['items'][0]['description']) === 127
                && $unit['items'][0]['unit_amount']['value'] === '10.00'
                && $unit['items'][1]['unit_amount']['value'] === '5.50'
                && ! array_key_exists('image_url', $unit['items'][1])
                && $unit['amount']['value'] === '25.50';
        });
    }

    public function test_prepare_charge_throws_on_empty_items(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Empty products array');

        (new PaypalPaymentService())->prepareCharge($this->chargeOptions(['items' => []]));
    }

    public function test_prepare_charge_throws_paypal_exception_on_api_error(): void
    {
        Http::fake($this->tokenResponse() + ['*/v2/checkout/orders' => Http::response(['name' => 'UNPROCESSABLE_ENTITY'], 422)]);

        try {
            (new PaypalPaymentService())->prepareCharge($this->chargeOptions());
            $this->fail('Exception expected');
        } catch (PaypalException $e) {
            $this->assertInstanceOf(\Exception::class, $e);
            $this->assertSame(422, $e->getCode());
            $this->assertSame('UNPROCESSABLE_ENTITY', $e->getResponse()['name']);
        }
    }

    public function test_auth_failure_throws(): void
    {
        Http::fake(['*/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(PaypalException::class);

        (new PaypalPaymentService())->prepareCharge($this->chargeOptions());
    }

    public function test_process_charge_success_shape(): void
    {
        session(['orderID' => 'ORDER123']);
        Http::fake($this->tokenResponse() + ['*/v2/checkout/orders/ORDER123/capture' => Http::response([
            'id' => 'ORDER123',
            'status' => 'COMPLETED',
            'payer' => ['email_address' => 'buyer@example.com'],
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1', 'amount' => ['value' => '49.00']]]]]],
        ])]);

        $result = (new PaypalPaymentService())->processCharge();

        $this->assertSame([
            'orderID' => 'ORDER123',
            'status' => 'success',
            'transactionID' => 'CAP1',
            'email' => 'buyer@example.com',
            'amount' => '49.00',
        ], $result);
    }

    public function test_process_charge_fail_shape_and_token_fallback(): void
    {
        $this->app['request']->query->set('token', 'ORDER999');
        Http::fake($this->tokenResponse() + ['*/v2/checkout/orders/ORDER999/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY'], 422)]);

        $result = (new PaypalPaymentService())->processCharge();

        $this->assertSame('ORDER999', $result['orderID']);
        $this->assertSame('fail', $result['status']);
        $this->assertSame(json_encode(['name' => 'UNPROCESSABLE_ENTITY']), $result['message']);
    }

    public function test_prepare_subscription_returns_approve_link(): void
    {
        Http::fake($this->tokenResponse() + ['*/v1/billing/subscriptions' => Http::response([
            'id' => 'I-SUB',
            'status' => 'APPROVAL_PENDING',
            'links' => [
                ['href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1', 'rel' => 'approve'],
                ['href' => self::SANDBOX.'/v1/billing/subscriptions/I-SUB', 'rel' => 'edit'],
            ],
        ], 201)]);

        $url = (new PaypalPaymentService())->prepareSubscription([
            'subscription_plan' => 'P-1',
            'customer_id' => 42,
            'return_url' => 'https://example.com/ok',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        $this->assertSame('https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-1', $url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/billing/subscriptions')
            && $r['plan_id'] === 'P-1'
            && $r['custom_id'] === '42'
            && $r['application_context']['return_url'] === 'https://example.com/ok');
    }

    public function test_prepare_subscription_failure_keeps_legacy_message(): void
    {
        Http::fake($this->tokenResponse() + ['*/v1/billing/subscriptions' => Http::response(['name' => 'INVALID_REQUEST'], 400)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Opps. Something wrong. Details in log');

        (new PaypalPaymentService())->prepareSubscription([
            'subscription_plan' => 'P-1',
            'customer_id' => 42,
            'return_url' => 'https://example.com/ok',
            'cancel_url' => 'https://example.com/cancel',
        ]);
    }

    public function test_subscription_success_missing_id_keeps_legacy_shape(): void
    {
        Http::fake();

        $this->assertSame(
            ['status' => 'error', 'error_msg' => 'missing subscription id'],
            (new PaypalPaymentService())->subscription_success([])
        );
        Http::assertNothingSent();
    }

    public function test_subscription_success_returns_details_and_tolerates_missing_tokens(): void
    {
        Http::fake($this->tokenResponse() + ['*/v1/billing/subscriptions/I-SUB' => Http::response([
            'id' => 'I-SUB',
            'status' => 'ACTIVE',
            'custom_id' => '42',
            'subscriber' => ['email_address' => 'buyer@example.com'],
        ])]);

        $service = new PaypalPaymentService();
        $result = $service->subscription_success(['subscription_id' => 'I-SUB', 'token' => 'T']);

        $this->assertSame([
            'email' => 'buyer@example.com',
            'subscription_id' => 'I-SUB',
            'ba_token' => null,
            'token' => 'T',
            'subscription_status' => 'ACTIVE',
            'custom_id' => '42',
        ], $result);
        $this->assertSame($result, $service->subscriptionSuccess(['subscription_id' => 'I-SUB', 'token' => 'T']));
    }

    public function test_expired_token_is_refreshed_once_on_401(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'OLD', 'expires_in' => 32400])
                ->push(['access_token' => 'NEW', 'expires_in' => 32400]),
            '*/v2/checkout/orders' => Http::sequence()
                ->push(['name' => 'AUTHENTICATION_FAILURE'], 401)
                ->push(['id' => 'ORDER123', 'links' => [['href' => 'https://pay', 'rel' => 'payer-action']]]),
        ]);

        $url = (new PaypalPaymentService())->prepareCharge($this->chargeOptions());

        $this->assertSame('https://pay', $url);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/checkout/orders') && $r->hasHeader('Authorization', 'Bearer NEW'));
    }
}
