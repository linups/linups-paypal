<?php
namespace Linups\LinupsPaypal\Services;

use http\Env\Request;
use Illuminate\Support\Facades\Http;

class PaypalPaymentService {

    private $accessToken;
    private $target;

    public function __construct() {
        if(config('linups-paypal.PAYPAL_SANDBOX') === true) {
            $this->target = 'https://api-m.sandbox.paypal.com';
        } else {
            $this->target = 'https://api-m.paypal.com';
        }

        $this->authorize();
    }
    private function authorize() {
        $responseRaw = Http::withHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic '.base64_encode(config('linups-paypal.PAYPAL_CLIENT_ID').':'.config('linups-paypal.PAYPAL_CLIENT_SECRET')),
        ])->asForm()
            ->post($this->target . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        $response = $responseRaw->getBody()->getContents();

        if($response) {
            $response = json_decode($response);
            $this->accessToken = $response->access_token;
            /****
             * +"access_token": "A21AAJg22y6jyvR_sJDR5AiTsvG-MQqiMeyCvGT0o1HbjbYeqKM8M1TO55L_zkoOOJmSlNjb2TftlR69Q_OdiqhLs2_w0jVFg"
             * +"token_type": "Bearer"
             * +"app_id": "APP-80W284485P519543T"
             * +"expires_in": 32400
             * +"nonce": "2024-04-23T12:01:30Za2lNDxsaxiFwN-3Q3Q4_NamCmNIUiTZnkKn1e3_o_dw"
             * ****/
        }


    }

    private function prepareProductArray(array $products):array {
        $productArray = [];
        if(count($products) == 0) throw new \Exception('Empty products array');
        foreach($products as $product) {
            $productArray[] = [
                'name' => $product['productName'],
                'quantity' => 1,
                'description' => $product['productDescription'],
                'category' => 'DIGITAL_GOODS',
                'image_url' => $product['productImage'],
                'unit_amount' => (object) [
                    'currency_code' => 'USD',
                    'value' => $product['amount'],
                ],
            ];
        }
        return $productArray;
    }

    public function prepareCharge(array $options):string {
        $productItems = $this->prepareProductArray($options['items']); //dd($productItems);

        /*$test = [
            [
                'name' => $productItems[0]['name'],
                'quantity' => 1,
                'description' => $productItems[0]['description'],
                'category' => 'DIGITAL_GOODS',
                'image_url' => $productItems[0]['image_url'],
                'unit_amount' => (object) [
                    'currency_code' => 'USD',
                    'value' => $productItems[0]['unit_amount']->value,
                ],
            ],
            [
                'name' => $productItems[1]['name'],
                'quantity' => 1,
                'description' => $productItems[1]['description'],
                'category' => 'DIGITAL_GOODS',
                'image_url' => $productItems[1]['image_url'],
                'unit_amount' => (object) [
                    'currency_code' => 'USD',
                    'value' => $productItems[1]['unit_amount']->value,
                ],
            ]
        ];*/

        $postObject = new \stdClass();
        $postObject->intent = 'CAPTURE';
        $postObject->purchase_units = [
            (object) [
                'description' => $productItems[0]['description'],
                'soft_descriptor' => $productItems[0]['name'] ?? 'Brittany Watkins',
                'invoice_id' => time(),
                'amount' => (object) [
                    'currency_code' => 'USD',
                    'value' => $options['totalAmount'],
                    'breakdown' => (object) [
                        'item_total' => (object) [
                            'currency_code' => 'USD',
                            'value' => $options['totalAmount'],
                        ]
                    ]
                ],
                'items' => $productItems,
            ]
        ];

        $postObject->payment_source = (object) [
            'paypal' => (object) [
                'experience_context' => (object) [
                    'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                    'brand_name' => 'Brittany Watkins',
                    'locale' => 'en-US',
                    'landing_page' => 'LOGIN',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $options['return_url'],
                    'cancel_url' => $options['cancel_url'],
                ]
            ]
        ];

        $responseRaw = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '. $this->accessToken,
        ])->post($this->target . '/v2/checkout/orders', $postObject);

        if($responseRaw) {
            $response = $responseRaw->getBody()->getContents();
            $response = json_decode($response);
//dd($response);
            //--- Saving order ID
            session(['orderID' => $response->id]);
        }

        return $response->links[1]->href;
    }

    public function processCharge():array {
        $orderID = session('orderID') ?? request()->get('token');
        $order['orderID'] = $orderID;
        $postData = new \stdClass();

        $responseRaw = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '. $this->accessToken,
        ])->post($this->target . '/v2/checkout/orders/'.$orderID.'/capture', $postData);

        if($responseRaw) {
            $response = $responseRaw->getBody()->getContents();
            $response = json_decode($response);
        }
        if($response->status == 'COMPLETED') {
            // returning finalized object
            $order['status'] = 'success';
            $order['transactionID'] = $response->purchase_units[0]->payments->captures[0]->id;
            $order['email'] = $response->payer->email_address;
            $order['amount'] = $response->purchase_units[0]->payments->captures[0]->amount->value;
        } else {
            $order['status'] = 'fail';
            $order['message'] = json_encode($response);
        }

        return $order;
    }

    public function prepareSubscription(array $options) {
        $postData = new \stdClass();
        $postData->plan_id = $options['subscription_plan'];
        $postData->custom_id = $options['customer_id'];

        $postData->application_context = new \stdClass();
        $postData->application_context->return_url = $options['return_url'];
        $postData->application_context->cancel_url = $options['cancel_url'];

        $responseRaw = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '. $this->accessToken,
        ])->post($this->target . '/v1/billing/subscriptions', $postData);

        if($responseRaw) {
            $response = $responseRaw->getBody()->getContents();
            $response = json_decode($response);
        }

        if(isset($response->status) && $response->status == 'APPROVAL_PENDING') {
            return $response->links[0]->href;
        }

        \Log::debug('Failed paypal subscription prepare: <pre>'.print_r($response, true).'</pre>');
        throw new \Exception('Opps. Something wrong. Details in log');
    }

    public function subscription_success(array $request):array { // needs to get info about subscription... user email and etc...
        if(isset($request['subscription_id'])) {
            //--- Getting additional data about subscription, like email, transaction id and so on...
            $responseRaw = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '. $this->accessToken,
            ])->get($this->target . '/v1/billing/subscriptions/'.$request['subscription_id']);

            if($responseRaw) {
                $response = $responseRaw->getBody()->getContents();
                $response = json_decode($response);
                $return['email'] = $response->subscriber->email_address;
            }

            $return['subscription_id'] = $request['subscription_id'];
            $return['ba_token'] = $request['ba_token'];
            $return['token'] = $request['token'];
        } else {
            $return['status'] = 'error';
            $return['error_msg'] = 'missing subscription id';
        }
        return $return;
    }
}