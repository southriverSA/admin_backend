<?php

namespace App\Services\PaymentService;

use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Subscription;
use Exception;
use Http;
use Illuminate\Database\Eloquent\Model;
use Str;

class MoyasarService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @return PaymentProcess|Model
     * @throws Exception
     */
    public function orderProcessTransaction(array $data): Model|PaymentProcess
    {
        $payment = Payment::where('tag', 'moya-sar')->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host = request()->getSchemeAndHttpHost();

        $token = base64_encode(data_get($payload, 'secret_key'));

        $headers = [
            'Authorization' => "Basic $token"
        ];

        /** @var Order $order */
        $order = data_get($data, 'parcel_id')
            ? ParcelOrder::find(data_get($data, 'parcel_id'))
            : Order::find(data_get($data, 'order_id'));

        $totalPrice     = ceil($order->rate_total_price * 2 * 100) / 2;

        $request = Http::withHeaders($headers)
            ->post('https://api.moyasar.com/v1/invoices', [
                'amount'      => $totalPrice,
                'currency'    => Str::lower($order->currency?->title ?? data_get($payload, 'currency')),
                'description' => "Payment for products",
                'back_url'    => "$host/payment-success?order_id=$order->id&lang=$this->language",
                'success_url' => "$host/payment-success?order_id=$order->id&lang=$this->language",
            ]);

        $response = $request->json();

        if ($request->status() !== 200) {
            throw new Exception($request->json('message', 'error in moya-sar'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $order->id,
            'model_type' => get_class($order),

        ], [
            'id' => data_get($response, 'id'),
            'data' => [
                'url'      => $response->url,
                'price'    => $totalPrice,
                'order_id' => $order->id
            ]
        ]);
    }

    /**
     * @param array $data
     * @param $shop
     * @param $currency
     * @return PaymentProcess|Model
     * @throws Exception
     */
    public function subscriptionProcessTransaction(array $data, $shop, $currency): Model|PaymentProcess
    {
        $payment = Payment::where('tag', 'moya-sar')->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host = request()->getSchemeAndHttpHost();

        $token = base64_encode(data_get($payload, 'secret_key'));

        $headers = [
            'Authorization' => "Basic $token"
        ];

        $subscription   = Subscription::find(data_get($data, 'subscription_id'));

        $request = Http::withHeaders($headers)
            ->post('https://api.moyasar.com/v1/invoices', [
                'amount'      => $subscription->price,
                'currency' => Str::lower(data_get($paymentPayload?->payload, 'currency', $currency)),
                'description' => "Payment for products",
                'back_url'    => "$host/payment-success?subscription_id=$subscription->id&lang=$this->language",
                'success_url' => "$host/payment-success?subscription_id=$subscription->id&lang=$this->language",
            ]);

        $response = $request->json();

        if ($request->status() !== 200) {
            throw new Exception($request->json('message', 'error in moya-sar'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $subscription->id,
            'model_type' => Subscription::class,

        ], [
            'id' => data_get($response, 'id'),
            'data' => [
                'url'      => $response->url,
                'price'    => $subscription->price,
                'order_id' => $subscription->id,
                'shop_id'  => $shop->id,

            ]
        ]);
    }

}
