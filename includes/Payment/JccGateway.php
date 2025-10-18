<?php

namespace FluentCartJcc\Payment;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Status;
use FluentCart\App\Services\Payments\StatusHelper;
use FluentCart\Framework\Support\Arr;
use FluentCartJcc\Logger;

class JccGateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'webhook',
        'refund',
        'subscriptions',
        'google_pay',
    ];

    /**
     * @var JccSettings
     */
    public $settings;

    /**
     * @var JccApi
     */
    public $api;

    public function __construct()
    {
        $this->settings = new JccSettings();
        parent::__construct($this->settings);
        $this->api = new JccApi($this->settings);
    }

    public function boot()
    {
        parent::boot();

        add_action('fluent_cart/payments/jcc_gateway/webhook_payment_completed', [$this, 'handlePaymentCompleted']);
        add_action('fluent_cart/payments/jcc_gateway/webhook_refunded', [$this, 'handleRefundWebhook']);
        add_action('fluent_cart/payments/jcc_gateway/webhook_reversed', [$this, 'handleReverseWebhook']);

        add_action('init', [$this, 'maybeHandleGatewayRequest']);

        if ($this->settings->isCallbackEnabled() && $this->settings->getCallbackMode() === 'static' && is_admin()) {
            add_action('admin_init', [$this, 'maybeSyncStaticCallback']);
        }

        $googlePay = $this->settings->getGooglePayConfig();
        if (($googlePay['enabled'] ?? 'no') === 'yes') {
            add_action('wp_enqueue_scripts', [$this, 'enqueueGooglePayAssets']);
            add_action('wp_ajax_fluent_cart_jcc_google_pay', [$this, 'handleGooglePayAjax']);
            add_action('wp_ajax_nopriv_fluent_cart_jcc_google_pay', [$this, 'handleGooglePayAjax']);
        }
    }

    public function meta(): array
    {
        $logoPath = FC_JCC_PLUGIN_PATH . 'assets/images/logo.svg';
        $logoUrl = file_exists($logoPath) ? FC_JCC_PLUGIN_URL . 'assets/images/logo.svg' : '';

        return [
            'title' => __('JCC Payment Gateway', 'fluentcart-jcc'),
            'route' => 'jcc_gateway',
            'slug' => 'jcc_gateway',
            'description' => __('Accept debit and credit card payments through the official JCC gateway.', 'fluentcart-jcc'),
            'logo' => $logoUrl,
            'icon' => $logoUrl,
            'status' => $this->settings->get('is_active') === 'yes',
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures, true);
    }

    public function fields(): array
    {
        return apply_filters('fluentcart_jcc/settings_fields', [
            'sections' => [
                [
                    'title' => __('Gateway Status', 'fluentcart-jcc'),
                    'description' => __('Control the visibility and mode of the JCC gateway.', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Enable JCC Gateway', 'fluentcart-jcc'),
                            'key' => 'is_active',
                            'component' => 'switch',
                            'help_text' => __('Toggle to make JCC available during checkout.', 'fluentcart-jcc'),
                        ],
                        [
                            'label' => __('Mode', 'fluentcart-jcc'),
                            'key' => 'payment_mode',
                            'component' => 'select',
                            'options' => [
                                'test' => __('Test', 'fluentcart-jcc'),
                                'live' => __('Live', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Capture Type', 'fluentcart-jcc'),
                            'key' => 'stage_mode',
                            'component' => 'select',
                            'options' => [
                                'one-stage' => __('One-stage (sale)', 'fluentcart-jcc'),
                                'two-stage' => __('Two-stage (pre-auth)', 'fluentcart-jcc'),
                            ],
                        ],
                    ],
                ],
                [
                    'title' => __('Test Credentials', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Test Merchant ID', 'fluentcart-jcc'),
                            'key' => 'test_credentials.merchant_id',
                            'component' => 'input-text',
                        ],
                        [
                            'label' => __('Test Password', 'fluentcart-jcc'),
                            'key' => 'test_credentials.password',
                            'component' => 'input-password',
                        ],
                        [
                            'label' => __('Test Token (optional)', 'fluentcart-jcc'),
                            'key' => 'test_credentials.token',
                            'component' => 'input-password',
                            'help_text' => __('Base64 encoded combination of MerchantID:Password.', 'fluentcart-jcc'),
                        ],
                    ],
                ],
                [
                    'title' => __('Live Credentials', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Live Merchant ID', 'fluentcart-jcc'),
                            'key' => 'live_credentials.merchant_id',
                            'component' => 'input-text',
                        ],
                        [
                            'label' => __('Live Password', 'fluentcart-jcc'),
                            'key' => 'live_credentials.password',
                            'component' => 'input-password',
                        ],
                        [
                            'label' => __('Live Token (optional)', 'fluentcart-jcc'),
                            'key' => 'live_credentials.token',
                            'component' => 'input-password',
                            'help_text' => __('Base64 encoded combination of MerchantID:Password.', 'fluentcart-jcc'),
                        ],
                    ],
                ],
                [
                    'title' => __('Checkout Experience', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Success URL', 'fluentcart-jcc'),
                            'key' => 'success_url',
                            'component' => 'input-text',
                            'placeholder' => home_url('/'),
                        ],
                        [
                            'label' => __('Fail URL', 'fluentcart-jcc'),
                            'key' => 'fail_url',
                            'component' => 'input-text',
                            'placeholder' => home_url('/'),
                        ],
                        [
                            'label' => __('Order Status After Payment', 'fluentcart-jcc'),
                            'key' => 'order_status_paid',
                            'component' => 'select',
                            'options_callback' => 'fluent_cart_get_order_statuses',
                        ],
                        [
                            'label' => __('Logging', 'fluentcart-jcc'),
                            'key' => 'logging_enabled',
                            'component' => 'switch',
                        ],
                    ],
                ],
                [
                    'title' => __('Callbacks & Notifications', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Enable Callbacks', 'fluentcart-jcc'),
                            'key' => 'callbacks_enabled',
                            'component' => 'switch',
                        ],
                        [
                            'label' => __('Callback Mode', 'fluentcart-jcc'),
                            'key' => 'callback_mode',
                            'component' => 'select',
                            'options' => [
                                'static' => __('Static', 'fluentcart-jcc'),
                                'dynamic' => __('Dynamic', 'fluentcart-jcc'),
                            ],
                        ],
                    ],
                ],
                [
                    'title' => __('Cart Data & Fiscalisation', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Send Cart Details', 'fluentcart-jcc'),
                            'key' => 'send_order',
                            'component' => 'switch',
                        ],
                        [
                            'label' => __('Tax System', 'fluentcart-jcc'),
                            'key' => 'tax_system',
                            'component' => 'select',
                            'options' => [
                                '0' => __('General', 'fluentcart-jcc'),
                                '1' => __('Simplified – income', 'fluentcart-jcc'),
                                '2' => __('Simplified – income minus expenses', 'fluentcart-jcc'),
                                '3' => __('Unified tax on imputed income', 'fluentcart-jcc'),
                                '4' => __('Unified agricultural tax', 'fluentcart-jcc'),
                                '5' => __('Patent taxation system', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Default VAT', 'fluentcart-jcc'),
                            'key' => 'tax_type',
                            'component' => 'select',
                            'options' => [
                                '0' => __('No VAT', 'fluentcart-jcc'),
                                '1' => __('VAT 0%', 'fluentcart-jcc'),
                                '2' => __('VAT 10%', 'fluentcart-jcc'),
                                '3' => __('VAT 18%', 'fluentcart-jcc'),
                                '6' => __('VAT 20%', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Payment Method Type', 'fluentcart-jcc'),
                            'key' => 'payment_method_type',
                            'component' => 'select',
                            'options' => [
                                '1' => __('Full prepayment', 'fluentcart-jcc'),
                                '2' => __('Partial prepayment', 'fluentcart-jcc'),
                                '3' => __('Advance payment', 'fluentcart-jcc'),
                                '4' => __('Full payment', 'fluentcart-jcc'),
                                '5' => __('Partial payment with credit', 'fluentcart-jcc'),
                                '6' => __('No payment with credit', 'fluentcart-jcc'),
                                '7' => __('Payment on credit', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Payment Object Type', 'fluentcart-jcc'),
                            'key' => 'payment_object_type',
                            'component' => 'select',
                            'options' => [
                                '1' => __('Goods', 'fluentcart-jcc'),
                                '4' => __('Service', 'fluentcart-jcc'),
                                '10' => __('Payment', 'fluentcart-jcc'),
                                '13' => __('Other', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Payment Object (Delivery)', 'fluentcart-jcc'),
                            'key' => 'payment_object_type_delivery',
                            'component' => 'select',
                            'options' => [
                                '1' => __('Goods', 'fluentcart-jcc'),
                                '4' => __('Service', 'fluentcart-jcc'),
                                '13' => __('Other', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('FES Cashbox ID', 'fluentcart-jcc'),
                            'key' => 'fes_cashbox_id',
                            'component' => 'input-text',
                        ],
                    ],
                ],
                [
                    'title' => __('Google Pay', 'fluentcart-jcc'),
                    'fields' => [
                        [
                            'label' => __('Enable Google Pay', 'fluentcart-jcc'),
                            'key' => 'google_pay.enabled',
                            'component' => 'switch',
                        ],
                        [
                            'label' => __('Google Merchant ID', 'fluentcart-jcc'),
                            'key' => 'google_pay.merchant_id',
                            'component' => 'input-text',
                        ],
                        [
                            'label' => __('Merchant Name', 'fluentcart-jcc'),
                            'key' => 'google_pay.merchant_name',
                            'component' => 'input-text',
                        ],
                        [
                            'label' => __('Mode', 'fluentcart-jcc'),
                            'key' => 'google_pay.mode',
                            'component' => 'select',
                            'options' => [
                                'TEST' => __('Test', 'fluentcart-jcc'),
                                'PRODUCTION' => __('Production', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Checkout Sections', 'fluentcart-jcc'),
                            'key' => 'google_pay.sections',
                            'component' => 'input-checkbox-group',
                            'options' => [
                                ['id' => 'product', 'label' => __('Product Page', 'fluentcart-jcc')],
                                ['id' => 'checkout', 'label' => __('Checkout Page', 'fluentcart-jcc')],
                            ],
                        ],
                        [
                            'label' => __('Button Type', 'fluentcart-jcc'),
                            'key' => 'google_pay.button_type',
                            'component' => 'select',
                            'options' => [
                                'buy' => __('Buy', 'fluentcart-jcc'),
                                'donate' => __('Donate', 'fluentcart-jcc'),
                                'checkout' => __('Checkout', 'fluentcart-jcc'),
                                'book' => __('Book', 'fluentcart-jcc'),
                                'order' => __('Order', 'fluentcart-jcc'),
                            ],
                        ],
                        [
                            'label' => __('Button Color', 'fluentcart-jcc'),
                            'key' => 'google_pay.button_color',
                            'component' => 'select',
                            'options' => [
                                'default' => __('Default', 'fluentcart-jcc'),
                                'black' => __('Black', 'fluentcart-jcc'),
                                'white' => __('White', 'fluentcart-jcc'),
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            return $this->handleSubscriptionPayment($paymentInstance);
        }

        return $this->handleOneTimePayment($paymentInstance);
    }

    public function handleIPN(): void
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $this->processWebhookEvent($payload ?: []);
        wp_send_json_success();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        $scripts = [];
        $googlePay = $this->settings->getGooglePayConfig();

        if (($googlePay['enabled'] ?? 'no') === 'yes') {
            $scripts[] = [
                'handle' => 'google-pay',
                'src' => 'https://pay.google.com/gp/p/js/pay.js',
            ];

            $scripts[] = [
                'handle' => 'fluent-cart-jcc-google-pay',
                'src' => FC_JCC_PLUGIN_URL . 'assets/js/google-pay.js',
                'deps' => ['google-pay'],
                'version' => FC_JCC_PLUGIN_VERSION,
            ];
        }

        return $scripts;
    }

    public function getOrderInfo(array $data)
    {
        $paymentInstance = Arr::get($data, 'payment_instance');
        if (!$paymentInstance instanceof PaymentInstance) {
            wp_send_json([
                'status' => 'failed',
                'message' => __('Unable to load JCC payment information.', 'fluentcart-jcc'),
            ], 400);
        }

        $transaction = $paymentInstance->transaction;
        $currency = $this->resolveCurrency($paymentInstance);

        $response = [
            'status' => 'success',
            'payment_args' => [
                'transaction_uuid' => $transaction->uuid ?? $transaction->id,
                'amount_minor' => $this->resolveAmountMinor($paymentInstance),
                'currency' => $currency,
                'currency_numeric' => $this->currencyToNumeric($currency),
            ],
            'message' => __('JCC payment data prepared.', 'fluentcart-jcc'),
        ];

        wp_send_json($response, 200);
    }

    public function processWebhookEvent($data)
    {
        $eventType = Arr::get($data, 'event', '');
        $formatted = str_replace('.', '_', $eventType);

        if (has_action('fluent_cart/payments/jcc_gateway/webhook_' . $formatted)) {
            do_action('fluent_cart/payments/jcc_gateway/webhook_' . $formatted, [
                'data' => $data,
                'raw' => $data,
            ]);
        }
    }

    public function handlePaymentCompleted($payload): void
    {
        $orderId = Arr::get($payload, 'data.orderId');
        if (!$orderId) {
            return;
        }

        $status = $this->api->getOrderStatus($orderId);
        if (!$status['success']) {
            Logger::log('Payment completion check failed', $status);
            return;
        }

        $data = $status['data'];
        $orderNumber = Arr::get($data, 'orderNumber');
        $transaction = $this->findTransactionByOrderNumber($orderNumber);
        if (!$transaction) {
            return;
        }

        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $transaction->fill([
            'status' => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id' => $orderId,
        ]);
        $transaction->save();

        $order = Order::query()->find($transaction->order_id);
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
    }

    public function handleRefundWebhook($payload): void
    {
        $this->recordAdjustmentFromWebhook($payload, Status::TRANSACTION_REFUNDED);
    }

    public function handleReverseWebhook($payload): void
    {
        $this->recordAdjustmentFromWebhook($payload, Status::TRANSACTION_REVERSED);
    }

    public function processWebhookReturn(array $query): void
    {
        $orderId = Arr::get($query, 'orderId');
        $transactionUuid = Arr::get($query, 'transaction');

        if (!$transactionUuid) {
            return;
        }

        $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
        if (!$transaction) {
            return;
        }

        if ($orderId) {
            $this->handlePaymentCompleted(['data' => ['orderId' => $orderId]]);
            $this->redirectAfterResult($transaction, true);
            return;
        }

        $this->redirectAfterResult($transaction, false);
    }

    public function processRefund($transactionId, $amount = null, $reason = '')
    {
        $transaction = OrderTransaction::query()->find($transactionId);
        if (!$transaction) {
            return new \WP_Error('fc_jcc_refund_missing', __('Transaction not found for refund.', 'fluentcart-jcc'));
        }

        $orderId = $transaction->vendor_charge_id;
        if (!$orderId) {
            return new \WP_Error('fc_jcc_refund_missing_order', __('JCC order reference missing from transaction.', 'fluentcart-jcc'));
        }

        $amountMinor = $amount !== null ? (int) round($amount * 100) : 0;
        $result = $this->api->refund($orderId, $amountMinor);

        if (!$result['success']) {
            return new \WP_Error('fc_jcc_refund_failed', $result['message'], $result);
        }

        return true;
    }

    private function handleOneTimePayment(PaymentInstance $paymentInstance)
    {
        $credentials = $this->settings->getApiCredentials();
        if (empty($credentials['merchant_id']) || empty($credentials['password'])) {
            return [
                'status' => 'failed',
                'message' => __('JCC credentials are missing. Please configure the gateway.', 'fluentcart-jcc'),
            ];
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;

        $amount = $this->resolveAmountMinor($paymentInstance);
        $currency = $this->resolveCurrency($paymentInstance);

        $jsonParams = OrderPayloadBuilder::buildJsonParams($this->settings);
        $orderBundle = OrderPayloadBuilder::buildOrderBundle($order, $this->settings);
        $billing = OrderPayloadBuilder::buildBillingData($order);

        $payload = [
            'amount' => $amount,
            'currency' => $this->currencyToNumeric($currency),
            'orderNumber' => OrderPayloadBuilder::buildGatewayOrderNumber($order),
            'returnUrl' => $this->buildReturnUrl($transaction),
            'jsonParams' => wp_json_encode($jsonParams),
        ];

        if ($billing) {
            $payload['billingPayerData'] = wp_json_encode($billing);
        }

        if ($orderBundle) {
            $payload['orderBundle'] = wp_json_encode($orderBundle);
            $payload['taxSystem'] = $this->settings->get('tax_system', '0');
        }

        if ($this->settings->isCallbackEnabled()) {
            if ($this->settings->getCallbackMode() === 'dynamic') {
                $payload['dynamicCallbackUrl'] = $this->buildCallbackUrl($transaction, true);
            }
        }

        $result = $this->api->registerOrder($payload);
        if (!$result['success']) {
            return [
                'status' => 'failed',
                'message' => $result['message'] ?: __('Failed to initialize payment with JCC.', 'fluentcart-jcc'),
            ];
        }

        $data = $result['data'];
        $this->attachTransactionMeta($transaction, [
            'orderId' => Arr::get($data, 'orderId'),
            'orderNumber' => Arr::get($data, 'orderNumber'),
            'formUrl' => Arr::get($data, 'formUrl'),
        ]);

        $redirect = Arr::get($data, 'formUrl');
        if (!$redirect) {
            return [
                'status' => 'failed',
                'message' => __('JCC did not return a form URL.', 'fluentcart-jcc'),
            ];
        }

        return [
            'redirect_to' => $redirect,
            'status' => 'success',
            'message' => __('Redirecting to JCC secure payment page.', 'fluentcart-jcc'),
        ];
    }

    private function handleSubscriptionPayment(PaymentInstance $paymentInstance)
    {
        // For now reuse regular flow; tokenisation can be added later.
        return $this->handleOneTimePayment($paymentInstance);
    }

    private function resolveAmountMinor(PaymentInstance $paymentInstance): int
    {
        $amount = 0;

        if (property_exists($paymentInstance, 'amount')) {
            $amount = (float) $paymentInstance->amount;
        } elseif (method_exists($paymentInstance, 'getAmount')) {
            $amount = (float) $paymentInstance->getAmount();
        } elseif ($paymentInstance->order && property_exists($paymentInstance->order, 'total')) {
            $amount = (float) $paymentInstance->order->total;
        }

        return (int) round($amount * 100);
    }

    private function resolveCurrency(PaymentInstance $paymentInstance): string
    {
        if (property_exists($paymentInstance, 'currency') && $paymentInstance->currency) {
            return $paymentInstance->currency;
        }

        if ($paymentInstance->order && property_exists($paymentInstance->order, 'currency') && $paymentInstance->order->currency) {
            return $paymentInstance->order->currency;
        }

        return strtoupper(apply_filters('fluentcart_jcc/default_currency', 'EUR', $paymentInstance));
    }

    private function currencyToNumeric(string $currency): string
    {
        $map = [
            'EUR' => '978',
            'USD' => '840',
            'GBP' => '826',
            'RUB' => '643',
            'RUR' => '810',
            'ILS' => '376',
            'JPY' => '392',
            'UAH' => '980',
            'NGN' => '566',
            'AUD' => '036',
            'CAD' => '124',
            'KWD' => '414',
            'AED' => '784',
            'TRY' => '949',
            'INR' => '356',
        ];

        $currency = strtoupper($currency);
        return $map[$currency] ?? '978';
    }

    private function defaultCurrency(): string
    {
        return strtoupper(apply_filters('fluentcart_jcc/default_currency', 'EUR', null));
    }

    private function merchantPrefix(): string
    {
        $credentials = $this->settings->getApiCredentials();
        $merchant = $credentials['merchant_id'] ?? '';
        if (strlen($merchant) > 4) {
            return substr($merchant, 0, -4);
        }

        return $merchant;
    }

    private function buildReturnUrl($transaction): string
    {
        return add_query_arg([
            'fc_jcc_action' => 'result',
            'transaction' => $transaction->uuid ?? $transaction->id,
        ], home_url('/'));
    }

    private function buildCallbackUrl($transaction = null, bool $includeToken = false): string
    {
        $args = [
            'fc_jcc_action' => 'callback',
        ];

        if ($transaction) {
            $identifier = $transaction->uuid ?? $transaction->id;
            $args['transaction'] = $identifier;

            if ($includeToken) {
                $args['token'] = wp_create_nonce('fc-jcc-' . $identifier);
            }
        }

        return add_query_arg($args, home_url('/'));
    }

    public function maybeHandleGatewayRequest(): void
    {
        $action = sanitize_text_field($_GET['fc_jcc_action'] ?? '');
        if (!$action) {
            return;
        }

        if ($action === 'result') {
            $this->processWebhookReturn($_GET);
        } elseif ($action === 'callback') {
            $this->handleCallback($_GET);
        }
    }

    private function handleCallback(array $query): void
    {
        $orderId = Arr::get($query, 'orderId');
        if (!$orderId) {
            return;
        }

        $transactionUuid = Arr::get($query, 'transaction');
        $token = Arr::get($query, 'token');
        if ($transactionUuid && $token && !wp_verify_nonce($token, 'fc-jcc-' . $transactionUuid)) {
            Logger::log('Invalid callback nonce', ['transaction' => $transactionUuid]);
            status_header(403);
            exit;
        }

        $payload = $this->api->getOrderStatus($orderId);
        if (!$payload['success']) {
            Logger::log('Callback status fetch failed', $payload);
            status_header(400);
            exit;
        }

        $data = $payload['data'];
        $orderStatus = Arr::get($data, 'orderStatus');

        if (in_array($orderStatus, ['1', '2'], true)) {
            $this->handlePaymentCompleted(['data' => ['orderId' => $orderId]]);
        } elseif ($orderStatus === '4') {
            $this->handleRefundWebhook(['data' => $data]);
        } elseif ($orderStatus === '3') {
            $this->handleReverseWebhook(['data' => $data]);
        }

        if ($transactionUuid) {
            $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
            if ($transaction) {
                $this->redirectAfterResult($transaction, in_array($orderStatus, ['1', '2'], true));
            }
        }

        exit;
    }

    private function redirectAfterResult($transaction, bool $success): void
    {
        $url = $success ? $this->settings->get('success_url') : $this->settings->get('fail_url');
        if ($url) {
            wp_safe_redirect(add_query_arg('order', $transaction->uuid, $url));
        } else {
            wp_safe_redirect($transaction->getReceiptPageUrl());
        }
        exit;
    }

    private function attachTransactionMeta($transaction, array $data): void
    {
        if (method_exists($transaction, 'setMeta')) {
            $transaction->setMeta('jcc_gateway', $data);
        }

        if (method_exists($transaction, 'fill')) {
            $transaction->fill([
                'vendor_charge_id' => $data['orderId'] ?? '',
            ]);
            $transaction->save();
        } elseif (method_exists($transaction, 'update')) {
            $transaction->update([
                'vendor_charge_id' => $data['orderId'] ?? '',
            ]);
        }
    }

    private function handleGooglePayAjax(): void
    {
        $token = sanitize_text_field($_POST['paymentToken'] ?? '');
        $amount = (int) ($_POST['amount'] ?? 0);
        $currency = sanitize_text_field($_POST['currency'] ?? '978');
        $transactionUuid = sanitize_text_field($_POST['transaction'] ?? '');

        if (!$token || !$amount || !$transactionUuid) {
            wp_send_json_error(['message' => __('Invalid Google Pay payload.', 'fluentcart-jcc')], 400);
        }

        $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
        if (!$transaction) {
            wp_send_json_error(['message' => __('Transaction not found.', 'fluentcart-jcc')], 404);
        }

        $payload = [
            'merchant' => $this->merchantPrefix(),
            'orderNumber' => $transactionUuid . '_' . time(),
            'amount' => $amount,
            'currencyCode' => $currency,
            'paymentToken' => $token,
            'returnUrl' => $this->buildReturnUrl($transaction),
            'additionalParameters' => [
                'CMS' => 'WordPress ' . get_bloginfo('version') . ' + FluentCart',
                'Module-version' => FC_JCC_PLUGIN_VERSION,
                'CMS_paymentType' => 'google_pay',
            ],
        ];

        $result = $this->api->googlePay($payload);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }

        $this->attachTransactionMeta($transaction, [
            'orderId' => Arr::get($result, 'data.orderId'),
            'googlePay' => true,
        ]);

        wp_send_json_success([
            'orderId' => Arr::get($result, 'data.orderId'),
            'redirect' => $this->buildReturnUrl($transaction),
        ]);
    }

    private function enqueueGooglePayAssets(): void
    {
        $config = $this->settings->getGooglePayConfig();
        if (($config['enabled'] ?? 'no') !== 'yes') {
            return;
        }

        wp_enqueue_script(
            'google-pay',
            'https://pay.google.com/gp/p/js/pay.js',
            [],
            null,
            true
        );
        wp_enqueue_script(
            'fluent-cart-jcc-google-pay',
            FC_JCC_PLUGIN_URL . 'assets/js/google-pay.js',
            ['google-pay'],
            FC_JCC_PLUGIN_VERSION,
            true
        );

        wp_localize_script('fluent-cart-jcc-google-pay', 'fcJccGooglePay', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'merchantId' => $config['merchant_id'] ?? '',
            'merchantName' => $config['merchant_name'] ?? '',
            'gatewayMerchantId' => $this->merchantPrefix(),
            'gateway' => 'bpcpay',
            'environment' => $config['mode'] ?? 'TEST',
            'buttonType' => $config['button_type'] ?? 'buy',
            'buttonColor' => $config['button_color'] ?? 'default',
            'currency' => $this->defaultCurrency(),
            'currencyNumericDefault' => $this->currencyToNumeric($this->defaultCurrency()),
        ]);
    }

    public function maybeSyncStaticCallback(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $transientKey = 'fc_jcc_callback_synced_' . ($this->settings->isLiveMode() ? 'live' : 'test');
        if (get_transient($transientKey)) {
            return;
        }

        $credentials = $this->settings->getApiCredentials();
        if (empty($credentials['merchant_id']) || empty($credentials['password'])) {
            return;
        }

        $result = $this->api->updateCallback($credentials['merchant_id'], $this->buildCallbackUrl(), 'STATIC');
        if ($result['success']) {
            set_transient($transientKey, 1, DAY_IN_SECONDS);
        } else {
            Logger::log('Callback sync failed', $result);
        }
    }

    private function recordAdjustmentFromWebhook($payload, string $status): void
    {
        $orderNumber = Arr::get($payload, 'data.orderNumber');
        if (!$orderNumber) {
            return;
        }

        $transaction = $this->findTransactionByOrderNumber($orderNumber);
        if (!$transaction) {
            return;
        }

        $transaction->fill([
            'status' => $status,
        ]);
        $transaction->save();

        $order = Order::query()->find($transaction->order_id);
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
    }

    private function findTransactionByOrderNumber(string $orderNumber)
    {
        if (strpos($orderNumber, '_') !== false) {
            $orderNumber = explode('_', $orderNumber)[0];
        }

        return OrderTransaction::query()
            ->where('vendor_charge_id', $orderNumber)
            ->orWhere('uuid', $orderNumber)
            ->first();
    }
}
