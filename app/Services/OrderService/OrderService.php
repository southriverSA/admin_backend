<?php

namespace App\Services\OrderService;

use App\Helpers\NotificationHelper;
use App\Helpers\ResponseError;
use App\Helpers\Utility;
use App\Models\Language;
use App\Models\Payment;
use App\Models\PushNotification;
use App\Models\Receipt;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Settings;
use App\Models\Shop;
use App\Models\User;
use App\Services\CartService\CartService;
use App\Services\CoreService;
use App\Services\Interfaces\OrderServiceInterface;
use App\Services\TransactionService\TransactionService;
use App\Traits\Notification;
use DB;
use Exception;
use Str;
use Throwable;

class OrderService extends CoreService implements OrderServiceInterface
{
    use Notification;

    protected function getModelClass(): string
    {
        return Order::class;
    }

    private function with(): array
    {
        $locale = data_get(Language::languagesList()->where('default', 1)->first(), 'locale');

        return [
            'user' => fn($u) => $u
                ->withCount([
                    'orders' => fn($u) => $u->where('status', Order::STATUS_DELIVERED)
                ])
                ->withSum([
                    'orders' => fn($u) => $u->where('status', Order::STATUS_DELIVERED)
                ], 'total_price'),
            'review',
            'pointHistories',
            'currency',
            'deliveryMan' => fn($d) => $d->withAvg('assignReviews', 'rating'),
            'deliveryMan.deliveryManSetting',
            'coupon',
            'shop:id,location,tax,price,price_per_km,background_img,logo_img,uuid,phone',
            'shop.translation' => fn($st) => $st->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),

            'orderDetails' => fn($od) => $od->whereNull('parent_id'),
            'orderDetails.stock.countable.discounts' => fn($q) => $q->where('start', '<=', today())
                ->where('end', '>=', today())
                ->where('active', 1),
            'orderDetails.stock.countable.unit.translation' => fn($q) => $q
               ->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),
            'orderDetails.stock.countable.translation' => fn($ct) => $ct
               ->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),
            'orderDetails.stock.stockExtras.group.translation' => function ($cgt) use($locale) {
                $cgt
					->select('id', 'extra_group_id', 'locale', 'title')
					->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale));
            },
            'orderDetails.children.stock.countable.translation' => fn($ct) => $ct
               ->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale)),
            'orderDetails.children.stock.stockExtras.group.translation' => function ($cgt) use($locale) {
                $cgt
					->select('id', 'extra_group_id', 'locale', 'title')
					->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale));
            },
            'orderRefunds',
            'transaction.paymentSystem',
            'galleries',
            'myAddress',
        ];
    }

	/**
	 * @param array $data
	 * @return array
	 */
    public function create(array $data): array
    {
        $checkPhoneIfRequired = $this->checkPhoneIfRequired($data);

        if (!data_get($checkPhoneIfRequired, 'status')) {
            return $checkPhoneIfRequired;
        }

        /** @var Shop $shop */
        $shop = Shop::find(data_get($data, 'shop_id'));

        if (data_get($data, 'table_id')) {

            $order = Order::select([
                'id',
                'status',
                'table_id'
            ])
            ->where([
                'status'   => Order::STATUS_NEW,
                'table_id' => $data['table_id']
            ])
            ->first();

            if (!empty($order)) {
                /** @var Order $order */
                return $this->update($order->id, $data);
            }

        }

        try {
            $order = DB::transaction(function () use ($data, $shop) {

                $time = data_get($data, 'delivery_date') . ' ' . data_get($data, 'delivery_time', date('H:i'));

                $nameOfDay = Str::lower(date('l', strtotime($time)));
                $time = Str::lower(date('H:i', strtotime($time)));

                $isClosed   = $shop->closedDates?->where('date', data_get($data, 'delivery_date'))?->first()?->id;
                $workingDay = $shop->workingDays?->where('day', $nameOfDay)->where('from')?->first();

                $from       = date('H:i', strtotime(str_replace('-', ':', $workingDay?->from)));
                $to         = date('H:i', strtotime(str_replace('-', ':', $workingDay?->to)));

				if (!$shop->open || $isClosed || $workingDay?->disabled || ($time < $from || $time > $to)) {
					return [
						'status'  => false,
						'message' => __('errors.' . ResponseError::ERROR_118, locale: $this->language),
						'code'    => ResponseError::ERROR_118
					];
				}

                /** @var Order $order */
                $order = $this->model()->create($this->setOrderParams($data, $shop));

                if (data_get($data, 'images.0')) {
                    $order->update(['img' => data_get($data, 'images.0')]);
                    $order->uploads(data_get($data, 'images'));
                }

                if (data_get($data, 'cart_id')) {
                    $order = (new OrderDetailService)->createOrderUser($order, data_get($data, 'cart_id', 0), data_get($data, 'notes', []));
                } else {
                    $order = (new OrderDetailService)->create($order, data_get($data, 'products', []));
                }

                $this->calculateOrder($order, $shop, $data);

				if (data_get($data, 'payment_id')) {

					$data['payment_sys_id'] = data_get($data, 'payment_id');

					$result = (new TransactionService)->orderTransaction($order->id, $data);

					if (!data_get($result, 'status')) {
						throw new Exception(data_get($result, 'message'));
					}

				}

				if (data_get($data, 'table_id')) {

					$data['payment_sys_id'] = Payment::where('tag', 'cash')->first()?->id;

					$result = (new TransactionService)->orderTransaction($order->id, $data);

					if (!data_get($result, 'status')) {
						throw new Exception(data_get($result, 'message'));
					}

				}

                return $order;
            });

            if (!data_get($order, 'status')) {
                return $order;
            }

            return [
                'status'    => true,
                'message'   => ResponseError::NO_ERROR,
                'data'      => clone $order->fresh($this->with())
            ];
        } catch (Throwable $e) {
            $this->error($e);

            return [
                'status'    => false,
                'message'   => $e->getMessage(),
                'code'      => ResponseError::ERROR_501
            ];
        }
    }

    /**
     * @param int $id
     * @param array $data
     * @return array
     */
    public function update(int $id, array $data): array
    {
        try {

            $checkPhoneIfRequired = $this->checkPhoneIfRequired($data);

            if (!data_get($checkPhoneIfRequired, 'status')) {
                return $checkPhoneIfRequired;
            }

            $order = DB::transaction(function () use ($data, $id) {

                /** @var Shop $shop */
                $shop  = Shop::with(['seller'])->find(data_get($data, 'shop_id'));

                /** @var Order $order */
                $order = $this->model()->with('orderDetails')->find($id);

                if (!$order) {
                    throw new Exception(__('errors.' . ResponseError::ORDER_NOT_FOUND, locale: $this->language));
                }

                $data['user_id']     = $order->user_id;
                $data['deliveryman'] = $order->deliveryman;

                $order->update($this->setOrderParams($data, $shop));

                if (data_get($data, 'images.0')) {

                    $order->galleries()->delete();
                    $order->update(['img' => data_get($data, 'images.0')]);
                    $order->uploads(data_get($data, 'images'));

                }

                $order = (new OrderDetailService)->create($order, data_get($data, 'products', []));

                $this->calculateOrder($order, $shop, $data);

                return $order;
            });

            return [
                'status' => true,
                'message' => ResponseError::NO_ERROR,
                'data' => clone $order->fresh($this->with())
            ];
        } catch (Throwable $e) {
            $this->error($e);

            return [
                'status'    => false,
                'message'   => $e->getMessage(),
                'code'      => ResponseError::ERROR_502
            ];
        }
    }

    /**
     * @param int $id
     * @param array $data
     * @return Order|null
     */
    public function updateTips(int $id, array $data): ?Order
    {
        $order = Order::find($id);

        $totalPrice = $order->total_price;
        $tipsRate   = data_get($data, 'tip_type') === 'fix' ?
            data_get($data, 'tips') / $order->rate :
            $totalPrice / 100 * data_get($data, 'tips');

        $totalPrice += $tipsRate;

        $order->update([
            'total_price' => $totalPrice,
            'tips'        => $tipsRate
        ]);

        return $order;
    }

	/**
	 * @param Order $order
	 * @param Shop|null $shop
	 * @param array $data
	 * @return void
	 * @throws Exception
	 */
    private function calculateOrder(Order $order, ?Shop $shop, array $data): void
    {
        /** @var Order $order */
        $order = $order->fresh(['orderDetails', 'transaction']);

		$totalDiscount  = $order->orderDetails->sum('discount');
		$totalPrice     = $order->orderDetails->sum('total_price');

        $shopTax = max($totalPrice / 100 * $shop?->tax, 0);

        $totalPrice += ($order->delivery_fee + $shopTax + $totalDiscount);

        $totalDiscount += $this->recalculateReceipt($order);

        $isSubscribe = (int)Settings::where('key', 'by_subscription')->first()?->value;
        $serviceFee  = (double)Settings::where('key', 'service_fee')->first()?->value ?? 0;

        $totalPrice = max(max($totalPrice, 0) - max($totalDiscount, 0), 0);

		$coupon = Coupon::checkCoupon(data_get($data, 'coupon'), $order->shop_id)->first();

		$deliveryFee = $order->delivery_fee;

		if ($coupon?->for === 'delivery_fee') {

			$deliveryFee -= max($this->checkCoupon($coupon, $order, $deliveryFee), 0);

		} else if ($coupon?->for === 'total_price') {

			$totalPrice -= max($this->checkCoupon($coupon, $order, $totalPrice - $deliveryFee), 0);

		}

        $totalPrice += $serviceFee;

        $waiterFeeRate = $order->waiter_fee;

        if ($order->delivery_type === Order::DINE_IN) {
            $waiterFeeRate = $totalPrice / 100 * $shop->service_fee;
        }

        $tipsRate = 0;

        if (data_get($data, 'tip_type') && data_get($data, 'tips')) {

            $tipsRate += data_get($data, 'tip_type') === 'fix' ?
                data_get($data, 'tips') :
                $totalPrice / 100 * data_get($data, 'tips');

            $totalPrice += $tipsRate;

        }

        $totalPrice += $waiterFeeRate;

        $commissionFee = !$isSubscribe ?
            max(($totalPrice / 100 * ($shop?->percentage <= 0.99 ? 1 : $shop?->percentage)), 0)
            : 0;

        $order->update([
            'total_price'    => $totalPrice,
            'delivery_fee'   => $deliveryFee,
            'commission_fee' => $commissionFee,
            'service_fee'    => $serviceFee,
            'total_discount' => max($totalDiscount, 0),
            'tax'            => $shopTax,
            'waiter_fee'     => $waiterFeeRate,
            'tips'           => $tipsRate,
        ]);

        $isSubscribe = (int)Settings::where('key', 'by_subscription')->first()?->value;

        if ($isSubscribe) {

            $orderLimit = $order->shop?->subscription?->subscription?->order_limit;

            $shopOrdersCount = DB::table('orders')
				->select(['shop_id'])
                ->where('shop_id', $order->shop_id)
                ->count('shop_id');

            if ($orderLimit < $shopOrdersCount) {
                $shop?->update([
                    'visibility' => 0
                ]);
            }

        }

    }

    /**
     * @param Coupon $coupon
     * @param Order $order
     * @param $totalPrice
     * @return float|int|null
     */
    private function checkCoupon(Coupon $coupon, Order $order, $totalPrice): float|int|null
    {
        if ($coupon->qty <= 0) {
            return 0;
        }

        $couponPrice = $coupon->type === 'percent' ? ($totalPrice / 100) * $coupon->price : $coupon->price;

        $order->coupon()->create([
            'user_id'   => $order->user_id,
            'name'      => $coupon->name,
            'price'     => $couponPrice,
        ]);

        $coupon->decrement('qty');

        return $couponPrice;
    }

    /**
     * @param int|null $orderId
     * @param int $deliveryman
     * @param int|null $shopId
     * @return array
     */
    public function updateDeliveryMan(?int $orderId, int $deliveryman, ?int $shopId = null): array
    {
        try {
            /** @var Order $order */
            $order = Order::when($shopId, fn($q) => $q->where('shop_id', $shopId))->find($orderId);

            if (!$order) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ];
            }

            if ($order->delivery_type !== Order::DELIVERY) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            /** @var User $user */
            $user = User::with('deliveryManSetting')->find($deliveryman);

            if (!$user || !$user->hasRole('deliveryman')) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_211,
                    'message' => __('errors.' . ResponseError::ERROR_211, locale: $this->language)
                ];
            }

            if (!$user->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_212,
                    'message' => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

//            if ($order->transaction->request == Transaction::REQUEST_WAITING) {
//                (new TransactionService)->payDebit($user, $order);
//            }

            $order->update([
                'deliveryman' => $user->id,
            ]);

            $this->sendNotification(
                is_array($user->firebase_token) ? $user->firebase_token : [$user->firebase_token],
                __('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language),
                $order->id,
                (new NotificationHelper)->deliveryManOrder($order, PushNotification::NEW_ORDER),
                [$user->id]
            );

            return [
                'status'    => true,
                'message'   => ResponseError::NO_ERROR,
                'data'      => $order,
                'user'      => $user
            ];
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language)
            ];
        }
    }

    /**
     * @param int|null $id
     * @return array
     */
    public function attachDeliveryMan(?int $id): array
    {
        try {
            /** @var Order $order */
            $order = Order::with('user')->find($id);

            if ($order->delivery_type !== Order::DELIVERY) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            if (!empty($order->deliveryman)) {
                return [
                    'status'    => false,
                    'code'      => ResponseError::ERROR_210,
                    'message'   => __('errors.' . ResponseError::ERROR_210, locale: $this->language)
                ];
            }

            /** @var User $user */
            $user = auth('sanctum')->user();

            if (!$user?->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'    => false,
                    'code'      => ResponseError::ERROR_212,
                    'message'   => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

//            if ($order->transaction->request == Transaction::REQUEST_WAITING) {
//                (new TransactionService)->payDebit($user, $order);
//            }

            $order->update([
                'deliveryman' => auth('sanctum')->id(),
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * @param int $orderId
     * @param int $waiterId
     * @param int|null $shopId
     * @return array
     */
    public function updateWaiter(int $orderId, int $waiterId, int|null $shopId = null): array
    {
        try {
            /** @var Order $order */
            $waiter = User::with(['roles'])
                ->when($shopId, fn($q) => $q->whereHas('invitations', fn($q) => $q->where('shop_id', $shopId)))
                ->find($waiterId);

            $order = Order::with('user')
                ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
                ->find($orderId);

            if (!$order) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ];
            }

            if ($order->delivery_type !== Order::DINE_IN) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            if (!$waiter || !$waiter->hasRole('waiter')) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_211,
                    'message' => __('errors.' . ResponseError::ERROR_211, locale: $this->language)
                ];
            }

            /** @var User $waiter */
            if (!$waiter->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_212,
                    'message' => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

            $order->update([
                'waiter_id' => $waiter->id,
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * @param int $orderId
     * @param int $cookId
     * @param int|null $shopId
     * @return array
     */
    public function updateCook(int $orderId, int $cookId, int|null $shopId = null): array
    {
        try {
            /** @var Order $order */
            $cook = User::with(['roles'])
                ->when($shopId, fn($q) => $q->whereHas('invitations', fn($q) => $q->where('shop_id', $shopId)))
                ->find($cookId);

            $order = Order::with('user')
                ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
                ->find($orderId);

            if (!$order) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ];
            }

            if ($order->delivery_type !== Order::DINE_IN) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            if (!$cook || !$cook->hasRole('cook')) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_211,
                    'message' => __('errors.' . ResponseError::ERROR_211, locale: $this->language)
                ];
            }

            /** @var User $cook */
            if (!$cook->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_212,
                    'message' => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

            $order->update([
                'cook_id' => $cook->id,
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * @param int|null $id
     * @return array
     */
    public function attachWaiter(?int $id): array
    {
        try {
            /** @var Order $order */
            $order = Order::with('user')->find($id);

            if ($order->delivery_type !== Order::DINE_IN) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            if (!empty($order->waiter_id)) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::WAITER_NOT_EMPTY,
                    'message' => __('errors.' . ResponseError::WAITER_NOT_EMPTY, locale: $this->language)
                ];
            }

            /** @var User $user */
            $user = auth('sanctum')->user();

            if (!$user?->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_212,
                    'message' => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

            $order->update([
                'waiter_id' => auth('sanctum')->id(),
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * @param int|null $id
     * @return array
     */
    public function attachCook(?int $id): array
    {
        try {
            /** @var Order $order */
            $order = Order::with('user')->find($id);

            if ($order->delivery_type !== Order::DINE_IN) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_502,
                    'message' => __('errors.' . ResponseError::ORDER_PICKUP, locale: $this->language)
                ];
            }

            if (!empty($order->cook_id)) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::COOKER_NOT_EMPTY,
                    'message' => __('errors.' . ResponseError::COOKER_NOT_EMPTY, locale: $this->language)
                ];
            }

            /** @var User $user */
            $user = auth('sanctum')->user();

            if (!$user?->invitations?->where('shop_id', $order->shop_id)?->first()?->id) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_212,
                    'message' => __('errors.' . ResponseError::ERROR_212, locale: $this->language)
                ];
            }

            $order->update([
                'cook_id' => auth('sanctum')->id(),
            ]);

            return ['status' => true, 'message' => ResponseError::NO_ERROR, 'data' => $order];
        } catch (Throwable) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

	/**
	 * @param array $data
	 * @param Shop $shop
	 * @return array
	 */
    private function setOrderParams(array $data, Shop $shop): array
    {
        $defaultCurrencyId = Currency::whereDefault(1)->first('id');

        $currencyId  = data_get($data, 'currency_id', data_get($defaultCurrencyId, 'id'));
		$currency	 = Currency::find($currencyId);

        $waiterFeeRate   = 0;
		$deliveryFeeRate = 0;

		if (data_get($data, 'location') && data_get($data, 'delivery_type') === Order::DELIVERY) {
			$helper      = new Utility;
			$km          = $helper->getDistance($shop->location, data_get($data, 'location'));

			$deliveryFee = $helper->getPriceByDistance($km, $shop, (float)data_get($data, 'rate', 1));

			$deliveryFeeRate = $deliveryFee / data_get($data, 'rate');
		}

        $serviceFee  = (double)Settings::where('key', 'service_fee')->first()?->value;
        $serviceFee  = $serviceFee ?: 0;

        return [
            'user_id'           => data_get($data, 'user_id', auth('sanctum')->id()),
            'waiter_id'         => data_get($data, 'waiter_id'),
            'cook_id'           => data_get($data, 'cook_id'),
            'table_id'          => data_get($data, 'table_id'),
            'booking_id'        => data_get($data, 'booking_id'),
            'user_booking_id'   => data_get($data, 'user_booking_id'),
            'total_price'       => 0,
            'currency_id'       => $currency?->id ?? $currencyId,
            'rate'              => $currency?->rate ?? 1,
            'note'              => data_get($data, 'note'),
            'shop_id'           => data_get($data, 'shop_id'),
            'phone'             => data_get($data, 'phone'),
            'username'          => data_get($data, 'username'),
            'tax'               => 0,
            'commission_fee'    => 0,
            'service_fee'       => $serviceFee,
            'status'            => data_get($data, 'status', 'new'),
            'delivery_fee'      => max($deliveryFeeRate, 0),
            'waiter_fee'        => max($waiterFeeRate, 0),
            'delivery_type'     => data_get($data, 'delivery_type'),
            'location'          => data_get($data, 'location'),
            'address'           => data_get($data, 'address'),
            'address_id'        => data_get($data, 'address_id'),
            'deliveryman'       => data_get($data, 'deliveryman'),
            'delivery_date'     => data_get($data, 'delivery_date'),
            'delivery_time'     => data_get($data, 'delivery_time'),
            'total_discount'    => 0,
        ];
    }

    /**
     * @param array|null $ids
     * @param int|null $shopId
     * @return array
     */
    public function destroy(?array $ids = [], ?int $shopId = null): array
    {
        $errors = [];

        foreach (Order::when($shopId, fn($q) => $q->where('shop_id', $shopId))->find(is_array($ids) ? $ids : []) as $order) {
            try {
                $order->delete();
            } catch (Throwable $e) {
                $errors[] = $order->id;

                $this->error($e);
            }
        }

        return $errors;
    }

    /**
     * @param int $id
     * @param int|null $userId
     * @return array
     */
    public function setCurrent(int $id, ?int $userId = null): array
    {
        $errors = [];

        $orders = Order::when($userId, fn($q) => $q->where('deliveryman', $userId))
            ->where('current', 1)
            ->orWhere('id', $id)
            ->get();

        $getOrder = new Order;

        foreach ($orders as $order) {

            try {

                if ($order->id === $id) {

                    $order->update([
                        'current' => true,
                    ]);

                    $getOrder = $order;

                    continue;

                }

                $order->update([
                    'current' => false,
                ]);

            } catch (Throwable $e) {
                $errors[] = $order->id;

                $this->error($e);
            }

        }

        return count($errors) === 0 ? [
            'status' => true,
            'code' => ResponseError::NO_ERROR,
            'data' => $getOrder
        ] : [
            'status'  => false,
            'code'    => ResponseError::ERROR_400,
            'message' => __(
                'errors.' . ResponseError::CANT_UPDATE_ORDERS,
                [
                    'ids' => implode(', #', $errors)
                ],
                $this->language
            )
        ];
    }

    /**
     * @param Order $order
     * @return int|float
     */
    public function recalculateReceipt(Order $order): int|float
    {
        $inReceipts = $order->orderDetails
            ?->where('bonus', 0)
            ?->pluck('quantity', 'stock_id')
            ?->toArray();

        foreach ($order->orderDetails as $orderDetail) {

            if (empty($orderDetail?->stock)) {
                $orderDetail->delete();
                continue;
            }

            if (!$orderDetail->bonus) {
                $inReceipts[$orderDetail->stock_id] = $orderDetail->quantity;
            }

        }

        /** @var Receipt|null $receipt */
        $receipts = Receipt::with('stocks')
            ->whereHas('stocks')
            ->where('shop_id', $order->shop_id)
            ->get();

        return (new CartService)->receipts($receipts, $inReceipts);
    }

    /**
     * @param array $data
     * @return array|bool[]
     */
    private function checkPhoneIfRequired(array $data): array
    {
        $existPhone = DB::table('users')
            ->whereNotNull('phone')
            ->where('id', data_get($data, 'user_id'))
            ->exists();

		$beforeOrderPhoneRequired = Settings::where('key', 'before_order_phone_required')
            ->first();

		if (data_get($data, 'delivery_type') == Order::DELIVERY && $beforeOrderPhoneRequired?->value && (!$existPhone && !data_get($data, 'phone'))) {
            return [
                'status'  => false,
                'message' => __('errors.' . ResponseError::ERROR_117, locale: $this->language),
                'code'    => ResponseError::ERROR_117
            ];
        }

        return ['status' => true];
    }
}
