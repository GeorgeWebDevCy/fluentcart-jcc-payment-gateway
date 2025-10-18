<?php

namespace FluentCartJcc\Payment;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;

class OrderPayloadBuilder
{
    private Order $order;
    private OrderTransaction $transaction;
    private JccSettings $settings;
    private StoreSettings $storeSettings;

    public function __construct(Order $order, OrderTransaction $transaction, JccSettings $settings)
    {
        $this->order = $order;
        $this->transaction = $transaction;
        $this->settings = $settings;
        $this->storeSettings = new StoreSettings();
    }

    public function buildRegistrationPayload(array $context = []): array
    {
        $mode = $this->settings->getMode();
        $currency = strtoupper($this->transaction->currency ?: $this->order->currency ?: $this->storeSettings->get('currency', 'EUR'));
        $numericCurrency = $this->currencyToNumeric($currency);

        $payload = [
            'userName'     => $this->settings->getMerchantId($mode),
            'password'     => $this->settings->getPassword($mode),
            'amount'       => (int) $this->transaction->total,
            'currency'     => $numericCurrency ?: '',
            'orderNumber'  => $this->resolveGatewayOrderNumber(),
            'returnUrl'    => $context['return_url'] ?? '',
            'failUrl'      => $context['fail_url'] ?? ($context['return_url'] ?? ''),
            'description'  => $this->buildDescription($context, $currency),
            'clientId'     => $this->resolveClientId(),
            'jsonParams'   => wp_json_encode($this->buildJsonParams($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (!empty($context['callback_url'])) {
            $payload['callbackUrl'] = $context['callback_url'];
        }

        if (!empty($context['dynamic_callback_url'])) {
            $payload['dynamicCallbackUrl'] = $context['dynamic_callback_url'];
        }

        if ($numericCurrency) {
            $payload['currency'] = $numericCurrency;
        } else {
            unset($payload['currency']);
        }

        if ($this->settings->shouldSendOrderBundle()) {
            $payload['orderBundle'] = wp_json_encode($this->buildOrderBundle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($taxSystem = $this->settings->get('fiscal_tax_system')) {
                $payload['taxSystem'] = $taxSystem;
            }
        }

        if ($this->settings->shouldSendBilling()) {
            $billing = $this->buildBillingPayerData();
            if (!empty($billing)) {
                $payload['billingPayerData'] = wp_json_encode($billing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($this->settings->get('enable_fiscalisation') === 'yes' && $this->settings->get('fiscal_cashbox_id')) {
            $payload['jsonParams'] = wp_json_encode(array_merge(
                json_decode($payload['jsonParams'], true),
                ['fes_cashboxId' => $this->settings->get('fiscal_cashbox_id')]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return array_filter($payload, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    public function buildBillingPayerData(): array
    {
        $address = $this->order->billing_address()->first();

        if (!$address instanceof OrderAddress) {
            return [];
        }

        $meta = $address->meta ?? [];

        $phone = Arr::get($meta, 'phone');
        if (!$phone && $this->order->customer) {
            $phone = Arr::get($this->order->customer->meta, 'phone');
        }

        $billing = array_filter([
            'billingCity'    => $this->normalizeText($address->city),
            'billingCountry' => $this->normalizeText($address->country),
            'billingPostAddress' => $this->normalizeText(trim($address->address_1 . ' ' . $address->address_2)),
            'billingRegion'  => $this->normalizeText($address->state),
            'billingPostcode'=> $this->normalizeText($address->postcode),
            'email'          => $address->email,
            'phone'          => $this->sanitizePhone($phone),
        ]);

        return $billing;
    }

    public function buildOrderBundle(): array
    {
        $orderItems = $this->order->order_items()->get();

        $items = [];
        $positionId = 1;

        foreach ($orderItems as $item) {
            if (!$item instanceof OrderItem) {
                continue;
            }

            $quantity = max(1, (int) $item->quantity);
            $unitPrice = (int) $item->unit_price;
            $itemAmount = $unitPrice * $quantity;

            $items[] = [
                'positionId' => $positionId++,
                'name'       => $this->truncate($item->title ?: $item->post_title ?: __('Item', 'fluentcart-jcc')),
                'itemAmount' => $itemAmount,
                'itemCode'   => $this->resolveItemCode($item, $positionId),
                'itemPrice'  => $unitPrice,
                'quantity'   => [
                    'value'   => $quantity,
                    'measure' => 'pcs',
                ],
                'tax'        => [
                    'taxType' => $this->resolveTaxType($item),
                ],
                'itemAttributes' => [
                    'attributes' => [
                        [
                            'name'  => 'paymentMethod',
                            'value' => $this->settings->get('item_payment_method_type') ?: '1',
                        ],
                        [
                            'name'  => 'paymentObject',
                            'value' => $this->settings->get('item_payment_object_type') ?: '1',
                        ],
                    ],
                ],
            ];
        }

        $shippingTotal = Helper::toCent((float) $this->order->shipping_total);
        if ($shippingTotal > 0) {
            $items[] = [
                'positionId' => $positionId,
                'name'       => __('Shipping', 'fluentcart-jcc'),
                'itemAmount' => $shippingTotal,
                'itemCode'   => 'shipping',
                'itemPrice'  => $shippingTotal,
                'quantity'   => [
                    'value'   => 1,
                    'measure' => 'pcs',
                ],
                'tax'        => [
                    'taxType' => $this->settings->get('paymentObjectType_delivery') ?: 4,
                ],
                'itemAttributes' => [
                    'attributes' => [
                        [
                            'name'  => 'paymentMethod',
                            'value' => $this->settings->get('shipping_payment_method_type') ?: 4,
                        ],
                        [
                            'name'  => 'paymentObject',
                            'value' => $this->settings->get('shipping_payment_object_type') ?: 4,
                        ],
                    ],
                ],
            ];
        }

        return [
            'orderCreationDate' => $this->order->created_at ? strtotime($this->order->created_at) : time(),
            'cartItems'         => [
                'items' => $items,
            ],
        ];
    }

    private function resolveGatewayOrderNumber(): string
    {
        $invoice = $this->order->invoice_no ?: $this->order->id;
        return $invoice . '_' . time();
    }

    private function resolveClientId(): ?string
    {
        if ($this->order->customer_id && $this->order->customer && $this->order->customer->email) {
            return md5($this->order->customer_id . $this->order->customer->email . get_option('siteurl'));
        }

        return null;
    }

    private function buildJsonParams(array $context): array
    {
        $params = [
            'CMS'             => sprintf('WordPress %s + FluentCart %s', get_bloginfo('version'), defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : 'unknown'),
            'Module-Version'  => FLUENTCART_JCC_GATEWAY_VERSION,
            'Transaction-UUID'=> $this->transaction->uuid,
            'Store-Name'      => $this->storeSettings->get('store_name'),
        ];

        if (!empty($context['back_to_shop_url'])) {
            $params['backToShopUrl'] = esc_url_raw($context['back_to_shop_url']);
        }

        if ($this->settings->googlePayEnabled()) {
            $params['CMS_googlePayEnabled'] = true;
        }

        return $params;
    }

    private function buildDescription(array $context, string $currency): string
    {
        $storeName = $this->storeSettings->get('store_name', get_bloginfo('name'));
        $invoice = $this->order->invoice_no ?: $this->transaction->uuid;

        $decimals = Helper::shopConfig('is_zero_decimal') ? 0 : 2;
        $multiplier = $decimals === 0 ? 1 : 100;
        $amount = $decimals === 0
            ? (string) $this->transaction->total
            : number_format($this->transaction->total / $multiplier, $decimals, '.', '');

        return sprintf(
            '%s Order #%s (%s %s)',
            $storeName,
            $invoice,
            $currency,
            $amount
        );
    }

    private function currencyToNumeric(string $code): ?string
    {
        $map = [
            'AED' => '784',
            'AMD' => '051',
            'AUD' => '036',
            'BGN' => '975',
            'BHD' => '048',
            'BYN' => '933',
            'BYR' => '974',
            'CAD' => '124',
            'COP' => '170',
            'CNY' => '156',
            'EUR' => '978',
            'GBP' => '826',
            'GHS' => '936',
            'GNF' => '324',
            'HKD' => '344',
            'HUF' => '348',
            'IDR' => '360',
            'ILS' => '376',
            'INR' => '356',
            'JOD' => '400',
            'JPY' => '392',
            'KGS' => '417',
            'KHR' => '116',
            'KRW' => '410',
            'KWD' => '414',
            'KZT' => '398',
            'LSL' => '426',
            'MDL' => '498',
            'MYR' => '458',
            'MZN' => '943',
            'NGN' => '566',
            'NOK' => '578',
            'NZD' => '554',
            'OMR' => '512',
            'PHP' => '608',
            'PLN' => '985',
            'RON' => '946',
            'RUB' => '643',
            'RUR' => '810',
            'SAR' => '682',
            'SGD' => '702',
            'TRY' => '949',
            'UAH' => '980',
            'USD' => '840',
            'ZAR' => '710',
        ];

        return $map[$code] ?? null;
    }

    private function normalizeText(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        $filtered = preg_replace('/[^\p{L}\p{N}\s\'"!#$%&@^~*+=\-_.,:;<>|\/?{}()\[\]]+/u', '', $text);

        return $filtered ? mb_substr($filtered, 0, 255) : null;
    }

    private function sanitizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $clean = preg_replace('/\D+/', '', $phone);
        return $clean ? substr($clean, 0, 15) : null;
    }

    private function truncate(string $value, int $length = 255): string
    {
        return mb_substr($value, 0, $length);
    }

    private function resolveTaxType(OrderItem $item): int
    {
        $taxAmount = (int) $item->tax_amount;
        if ($taxAmount <= 0 || $item->subtotal <= 0) {
            return (int) ($this->settings->get('fiscal_tax_type') ?: 0);
        }

        $rate = ($taxAmount / max(1, $item->subtotal)) * 100;

        if ($rate >= 20) {
            return 6;
        }

        if ($rate >= 18) {
            return 3;
        }

        if ($rate >= 10) {
            return 2;
        }

        if ($rate >= 7) {
            return 12;
        }

        if ($rate >= 5) {
            return 10;
        }

        if ($rate > 0) {
            return 1;
        }

        return (int) ($this->settings->get('fiscal_tax_type') ?: 0);
    }

    private function resolveItemCode(OrderItem $item, int $fallback): string
    {
        if ($item->object_id) {
            return (string) $item->object_id;
        }

        if ($item->post_id) {
            return (string) $item->post_id;
        }

        return (string) $fallback;
    }
}
