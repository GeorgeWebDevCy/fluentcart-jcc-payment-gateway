<?php

namespace FluentCartJcc\Payment;

use FluentCart\Framework\Support\Arr;

class OrderPayloadBuilder
{
    public static function buildGatewayOrderNumber($order): string
    {
        $orderNumber = '';

        if (method_exists($order, 'getOrderNumber')) {
            $orderNumber = (string) $order->getOrderNumber();
        } elseif (property_exists($order, 'order_number')) {
            $orderNumber = (string) $order->order_number;
        } elseif (property_exists($order, 'id')) {
            $orderNumber = (string) $order->id;
        } else {
            $orderNumber = (string) Arr::get((array) $order, 'id');
        }

        $orderNumber = trim(str_replace('#', '', $orderNumber));

        if ($orderNumber === '') {
            $orderNumber = uniqid('fc-order-', true);
        }

        return $orderNumber . '_' . time();
    }

    public static function buildJsonParams(JccSettings $settings): array
    {
        $params = [
            'CMS' => 'WordPress ' . get_bloginfo('version') . ' + FluentCart',
            'Module-Version' => FC_JCC_PLUGIN_VERSION,
        ];

        if ($settings->get('google_pay.enabled') === 'yes') {
            $params['CMS_googlePayEnabled'] = $settings->get('google_pay.mode') === 'PRODUCTION';
        }

        $backUrl = $settings->get('back_to_shop_url');
        if ($backUrl) {
            $params['backToShopUrl'] = $backUrl;
        }

        $cashboxId = $settings->get('fes_cashbox_id');
        if ($cashboxId) {
            $params['fes_cashboxId'] = $cashboxId;
        }

        return $params;
    }

    public static function buildBillingData($order): array
    {
        $billing = [];

        if (method_exists($order, 'getBilling')) {
            $billing = (array) $order->getBilling();
        } elseif (property_exists($order, 'billing')) {
            $billing = (array) $order->billing;
        } else {
            $billing = (array) Arr::get((array) $order, 'billing', []);
        }

        $allowed = '/^[A-Za-z0-9\s\'"!#$%&@^~*+=\-_.,:;<>|，΄´–\/?\\\\{}()\[\]\n]+$/';
        $map = [
            'billingCity' => 'city',
            'billingCountry' => 'country',
            'billingAddressLine1' => 'address_1',
            'billingAddressLine2' => 'address_2',
            'billingAddressLine3' => 'address_3',
            'billingPostalCode' => 'postal_code',
            'billingState' => 'state',
        ];

        $payload = [];

        foreach ($map as $target => $source) {
            $value = $billing[$source] ?? $billing[str_replace('_', '', $source)] ?? '';
            if ($value && preg_match($allowed, $value)) {
                $payload[$target] = $value;
            }
        }

        return $payload;
    }

    public static function buildOrderBundle($order, JccSettings $settings): array
    {
        $enabled = $settings->get('send_order') === 'yes';
        if (!$enabled) {
            return [];
        }

        $items = self::collectItems($order, $settings);
        if (!$items) {
            return [];
        }

        $createdAt = null;
        if (method_exists($order, 'created_at')) {
            $createdAt = $order->created_at;
        } elseif (property_exists($order, 'created_at')) {
            $createdAt = $order->created_at;
        } elseif (method_exists($order, 'getCreatedAt')) {
            $createdAt = $order->getCreatedAt();
        }

        if ($createdAt instanceof \DateTimeInterface) {
            $timestamp = $createdAt->getTimestamp();
        } elseif (is_numeric($createdAt)) {
            $timestamp = (int) $createdAt;
        } else {
            $timestamp = time();
        }

        $bundle = [
            'orderCreationDate' => $timestamp,
            'cartItems' => [
                'items' => $items,
            ],
        ];

        $billing = self::buildBillingData($order);
        if ($billing) {
            $bundle['customerDetails'] = array_merge(
                $bundle['customerDetails'] ?? [],
                $billing
            );
        }

        $email = self::extractCustomerEmail($order);
        if ($email) {
            $bundle['customerDetails']['email'] = $email;
        }

        return $bundle;
    }

    private static function extractCustomerEmail($order): ?string
    {
        if (method_exists($order, 'getCustomer')) {
            $customer = $order->getCustomer();
            if (is_array($customer) && isset($customer['email'])) {
                return $customer['email'];
            }
            if (is_object($customer) && property_exists($customer, 'email')) {
                return $customer->email;
            }
        }

        $billing = [];
        if (property_exists($order, 'billing')) {
            $billing = (array) $order->billing;
        } else {
            $billing = (array) Arr::get((array) $order, 'billing', []);
        }

        return $billing['email'] ?? null;
    }

    private static function collectItems($order, JccSettings $settings): array
    {
        $items = [];

        if (method_exists($order, 'getItems')) {
            $items = $order->getItems();
        } elseif (property_exists($order, 'items')) {
            $items = $order->items;
        } else {
            $items = Arr::get((array) $order, 'items', []);
        }

        if (!$items || !is_iterable($items)) {
            return [];
        }

        $results = [];
        $index = 1;

        foreach ($items as $item) {
            $name = self::valueFromMixed($item, ['name', 'item_name', 'title'], 'Item ' . $index);
            $quantity = (float) self::valueFromMixed($item, ['quantity', 'qty'], 1);
            $total = (float) self::valueFromMixed($item, ['total', 'line_total', 'subtotal'], 0);
            $tax = (float) self::valueFromMixed($item, ['tax_total', 'tax'], 0);
            $sku = self::valueFromMixed($item, ['sku', 'product_sku', 'id'], (string) $index);

            $unit = $quantity > 0 ? ($total + $tax) / $quantity : 0;
            $unitMinor = (int) round($unit * 100);

            $results[] = [
                'positionId' => $index,
                'name' => $name,
                'quantity' => [
                    'value' => $quantity,
                    'measure' => $settings->get('version_ffd') === 'v1_05' ? 'pcs' : '0',
                ],
                'itemAmount' => (int) round(($total + $tax) * 100),
                'itemCode' => $sku,
                'tax' => [
                    'taxType' => $settings->get('tax_type', '0'),
                ],
                'itemPrice' => $unitMinor,
                'itemAttributes' => [
                    'attributes' => [
                        ['name' => 'paymentMethod', 'value' => $settings->get('payment_method_type', '1')],
                        ['name' => 'paymentObject', 'value' => $settings->get('payment_object_type', '1')],
                    ],
                ],
            ];

            $index++;
        }

        $shippingTotal = self::valueFromMixed($order, ['shipping_total', 'shipping'], 0);
        if ($shippingTotal) {
            $results[] = [
                'positionId' => $index,
                'name' => __('Delivery', 'fluentcart-jcc'),
                'quantity' => [
                    'value' => 1,
                    'measure' => $settings->get('version_ffd') === 'v1_05' ? 'pcs' : '0',
                ],
                'itemAmount' => (int) round($shippingTotal * 100),
                'itemCode' => 'delivery',
                'tax' => [
                    'taxType' => $settings->get('tax_type', '0'),
                ],
                'itemPrice' => (int) round($shippingTotal * 100),
                'itemAttributes' => [
                    'attributes' => [
                        ['name' => 'paymentMethod', 'value' => $settings->get('payment_object_type_delivery', '4')],
                        ['name' => 'paymentObject', 'value' => '4'],
                    ],
                ],
            ];
        }

        return $results;
    }

    private static function valueFromMixed($source, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (is_object($source) && isset($source->$key)) {
                return $source->$key;
            }
        }

        return $default;
    }
}
