<?php

namespace FluentCartJcc\Payment;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCartJcc\Logger;

class JccGateway extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'custom_payment'
    ];

    protected Logger $logger;
    protected JccApi $api;
    public StoreSettings $storeSettings;

    public function __construct()
    {
        $settings = new JccSettings();
        $this->logger = new Logger($settings->enableLogging());
        $this->api = new JccApi($settings, $this->logger);
        $this->storeSettings = new StoreSettings();

        parent::__construct($settings);
    }

    public function meta(): array
    {
        return [
            'title'       => __('JCC Payments', 'fluentcart-jcc'),
            'route'       => 'jcc',
            'slug'        => 'jcc',
            'label'       => __('JCC', 'fluentcart-jcc'),
            'description' => __('Accept card payments through the official JCC gateway, including hosted checkout, callbacks, refunds, and Google Pay.', 'fluentcart-jcc'),
            'logo'        => FLUENTCART_JCC_GATEWAY_URL . 'assets/images/jcc-logo.png',
            'icon'        => FLUENTCART_JCC_GATEWAY_URL . 'assets/images/jcc-logo.png',
            'brand_color' => '#005aa3',
            'status'      => $this->settings->isActive(),
            'upcoming'    => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            if ($this->settings->googlePayEnabled()) {
                $methods[] = 'jcc';
            }
            return $methods;
        });

        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'maybeHandleFrontendActions']);

        add_action('rest_api_init', function () {
            register_rest_route('fluentcart/jcc/v1', '/webhook', [
                'methods'             => ['POST', 'GET'],
                'callback'            => [$this, 'handleRestWebhook'],
                'permission_callback' => '__return_true',
            ]);
        });

        add_action('wp_ajax_fluent_cart_jcc_google_pay', [$this, 'handleGooglePayAjax']);
        add_action('wp_ajax_nopriv_fluent_cart_jcc_google_pay', [$this, 'handleGooglePayAjax']);
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'fc_jcc_action';
        $vars[] = 'fc_jcc_token';
        $vars[] = 'fc_jcc_trx';
        return $vars;
    }

    public function maybeHandleFrontendActions(): void
    {
        $action = get_query_var('fc_jcc_action');

        if (!$action) {
            return;
        }

        switch ($action) {
            case 'result':
                $this->handleHostedReturn();
                break;
            case 'fail':
                $this->handleHostedFailure();
                break;
            case 'callback':
                $this->handleCallbackRequest();
                break;
        }
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $transaction = $paymentInstance->transaction;
        $order = $paymentInstance->order;

        $items = $order->order_items()->get()->toArray();
        $this->validateSubscriptions($items);

        $listener = $this->getListenerUrl();
        $listenerUrl = Arr::get($listener, 'listener_url');

        $transactionHash = $transaction->uuid;

        $returnUrl = add_query_arg([
            'fc_jcc_action' => 'result',
            'orderId'       => '',
            'fc_jcc_trx'    => $transactionHash,
        ], $this->getSuccessUrl($transaction));

        $failUrl = add_query_arg([
            'fc_jcc_action' => 'fail',
            'fc_jcc_trx'    => $transactionHash,
        ], $this->getSuccessUrl($transaction));

        $callbackUrl = '';
        $dynamicCallbackUrl = '';
        $callbackMeta = [];

        if ($this->settings->callbackType() === 'dynamic') {
            $token = wp_generate_password(40, false);
            $dynamicCallbackUrl = add_query_arg([
                'fc_jcc_action' => 'callback',
                'fc_jcc_token'  => $token,
                'fc_jcc_trx'    => $transactionHash,
            ], $listenerUrl);
            $callbackMeta = [
                'token'     => $token,
                'expires'   => time() + ($this->settings->dynamicCallbackTtl() * MINUTE_IN_SECONDS),
                'type'      => 'dynamic',
            ];
        } else {
            $callbackUrl = add_query_arg([
                'fc_jcc_action' => 'callback',
                'fc_jcc_trx'    => $transactionHash,
            ], $listenerUrl);
            $callbackMeta = [
                'type' => 'static',
            ];
        }

        $payloadBuilder = new OrderPayloadBuilder($order, $transaction, $this->settings);

        $context = [
            'return_url'           => $returnUrl,
            'fail_url'             => $failUrl,
            'callback_url'         => $callbackUrl,
            'dynamic_callback_url' => $dynamicCallbackUrl,
            'back_to_shop_url'     => $this->settings->get('success_url'),
        ];

        $payload = $payloadBuilder->buildRegistrationPayload($context);

        $response = $this->api->register($payload, $this->settings->stageMode() === 'dual');

        if (is_wp_error($response)) {
            return [
                'status'  => 'failed',
                'message' => $response->get_error_message(),
            ];
        }

        if (!empty($response['errorCode'])) {
            $message = sprintf(
                __('JCC registration failed (code %1$s): %2$s', 'fluentcart-jcc'),
                $response['errorCode'],
                Arr::get($response, 'errorMessage', __('Unknown error', 'fluentcart-jcc'))
            );

            $this->logger->error('jcc_registration_error', [
                'payload'  => $this->redactSensitive($payload),
                'response' => $response,
            ]);

            return [
                'status'  => 'failed',
                'message' => $message,
            ];
        }

        $meta = $transaction->meta ?? [];
        $meta['jcc'] = array_merge([
            'mode'        => $this->settings->getMode(),
            'stage_mode'  => $this->settings->stageMode(),
            'returned_at' => null,
        ], $callbackMeta, [
            'orderId'     => Arr::get($response, 'orderId'),
            'formUrl'     => Arr::get($response, 'formUrl'),
            'payload'     => $this->redactSensitive($payload),
            'transaction' => $transactionHash,
        ]);

        $transaction->meta = $meta;
        $transaction->payment_method = 'jcc';
        $transaction->payment_mode = $this->settings->getMode();
        $transaction->save();

        $this->logger->info('jcc_registration_success', [
            'order_id'     => $order->id,
            'transaction'  => $transactionHash,
            'gateway_order'=> Arr::get($response, 'orderId'),
        ]);

        return [
            'status'     => 'success',
            'nextAction' => 'redirect',
            'actionName' => 'external',
            'redirect_to'=> Arr::get($response, 'formUrl'),
            'message'    => __('Redirecting to JCC secure checkout…', 'fluentcart-jcc'),
            'data'       => [
                'order' => ['uuid' => $order->uuid],
                'transaction' => ['uuid' => $transactionHash],
            ],
        ];
    }

    public function handleIPN()
    {
        $this->handleCallbackRequest(true);
    }

    public function getOrderInfo(array $data)
    {
        return [
            'status'          => 'success',
            'payment_args'    => [],
            'has_subscription'=> false,
        ];
    }

    public function fields(): array
    {
        $webhookUrl = add_query_arg([
            'fc_jcc_action' => 'callback',
        ], Arr::get($this->getListenerUrl(), 'listener_url'));

        $googlePayVisible = $this->settings->googlePayEnabled() ? 'yes' : 'yes';

        return [
            'overview' => [
                'value' => wp_kses(
                    sprintf(
                        '<div><p>%s</p></div>',
                        __('Connect your JCC merchant account to FluentCart. Provide the merchant credentials, choose one-stage or two-stage capture, configure callbacks, and optionally enable Google Pay.', 'fluentcart-jcc')
                    ),
                    ['div' => [], 'p' => []]
                ),
                'label' => __('Overview', 'fluentcart-jcc'),
                'type'  => 'html_attr',
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Test Credentials', 'fluentcart-jcc'),
                        'value'  => 'test',
                        'schema' => [
                            'test_merchant_id' => [
                                'value'       => $this->settings->get('test_merchant_id'),
                                'label'       => __('Test Merchant ID', 'fluentcart-jcc'),
                                'type'        => 'text',
                                'placeholder' => __('e.g. 1234567890', 'fluentcart-jcc'),
                            ],
                            'test_password' => [
                                'value'       => '',
                                'label'       => __('Test Password', 'fluentcart-jcc'),
                                'type'        => 'password',
                                'placeholder' => __('Gateway password', 'fluentcart-jcc'),
                            ],
                            'test_secret_key' => [
                                'value'       => '',
                                'label'       => __('Test Secret Key', 'fluentcart-jcc'),
                                'type'        => 'password',
                                'placeholder' => __('Optional hash key', 'fluentcart-jcc'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Live Credentials', 'fluentcart-jcc'),
                        'value'  => 'live',
                        'schema' => [
                            'live_merchant_id' => [
                                'value'       => $this->settings->get('live_merchant_id'),
                                'label'       => __('Live Merchant ID', 'fluentcart-jcc'),
                                'type'        => 'text',
                                'placeholder' => __('e.g. 1234567890', 'fluentcart-jcc'),
                            ],
                            'live_password' => [
                                'value'       => '',
                                'label'       => __('Live Password', 'fluentcart-jcc'),
                                'type'        => 'password',
                                'placeholder' => __('Gateway password', 'fluentcart-jcc'),
                            ],
                            'live_secret_key' => [
                                'value'       => '',
                                'label'       => __('Live Secret Key', 'fluentcart-jcc'),
                                'type'        => 'password',
                                'placeholder' => __('Optional hash key', 'fluentcart-jcc'),
                            ],
                        ],
                    ],
                ],
            ],
            'stage_mode' => [
                'value'   => $this->settings->stageMode(),
                'label'   => __('Capture Mode', 'fluentcart-jcc'),
                'type'    => 'radio',
                'options' => [
                    'single' => __('Single-stage (charge immediately)', 'fluentcart-jcc'),
                    'dual'   => __('Two-stage (authorize then capture)', 'fluentcart-jcc'),
                ],
            ],
            'callback_type' => [
                'value'   => $this->settings->callbackType(),
                'label'   => __('Callback Mode', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'static'  => __('Static (single shared URL)', 'fluentcart-jcc'),
                    'dynamic' => __('Dynamic (per-transaction nonce)', 'fluentcart-jcc'),
                ],
                'help'    => __('Dynamic callbacks include a unique token per transaction. Static callbacks reuse the same endpoint.', 'fluentcart-jcc'),
            ],
            'send_order' => [
                'value'   => $this->settings->shouldSendOrderBundle() ? 'yes' : 'no',
                'label'   => __('Send Order Bundle', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'yes' => __('Send line items to JCC', 'fluentcart-jcc'),
                    'no'  => __('Only send totals', 'fluentcart-jcc'),
                ],
            ],
            'send_billing_data' => [
                'value'   => $this->settings->shouldSendBilling() ? 'yes' : 'no',
                'label'   => __('Send Billing Details', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'yes' => __('Include billing details when available', 'fluentcart-jcc'),
                    'no'  => __('Skip billing details', 'fluentcart-jcc'),
                ],
            ],
            'enable_fiscalisation' => [
                'value'   => $this->settings->get('enable_fiscalisation'),
                'label'   => __('FES Fiscalisation', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'yes' => __('Enable fiscalisation payload', 'fluentcart-jcc'),
                    'no'  => __('Disabled', 'fluentcart-jcc'),
                ],
            ],
            'fiscal_cashbox_id' => [
                'value'       => $this->settings->get('fiscal_cashbox_id'),
                'label'       => __('FES Cashbox ID', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('Optional cashbox identifier', 'fluentcart-jcc'),
            ],
            'success_url' => [
                'value'       => $this->settings->get('success_url'),
                'label'       => __('Success Redirect URL (optional)', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('https://example.com/thank-you', 'fluentcart-jcc'),
            ],
            'fail_url' => [
                'value'       => $this->settings->get('fail_url'),
                'label'       => __('Failure Redirect URL (optional)', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('https://example.com/payment-failed', 'fluentcart-jcc'),
            ],
            'enable_logging' => [
                'value'   => $this->settings->enableLogging() ? 'yes' : 'no',
                'label'   => __('Enable Gateway Logging', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'yes' => __('Write debug logs to uploads/fluentcart-jcc-logs/', 'fluentcart-jcc'),
                    'no'  => __('Disable logging', 'fluentcart-jcc'),
                ],
            ],
            'webhook_desc' => [
                'value' => wp_kses(sprintf(
                    '<div><p><strong>%s</strong> <code class="copyable-content">%s</code></p></div>',
                    __('Callback URL:', 'fluentcart-jcc'),
                    esc_url($webhookUrl)
                ), [
                    'div' => [],
                    'p'   => [],
                    'strong' => [],
                    'code' => ['class' => true],
                ]),
                'label' => __('Callback Endpoint', 'fluentcart-jcc'),
                'type'  => 'html_attr',
            ],
            'enable_google_pay' => [
                'value'   => $this->settings->googlePayEnabled() ? 'yes' : 'no',
                'label'   => __('Enable Google Pay', 'fluentcart-jcc'),
                'type'    => 'select',
                'options' => [
                    'yes' => __('Enable Google Pay button and tokenisation', 'fluentcart-jcc'),
                    'no'  => __('Disable Google Pay', 'fluentcart-jcc'),
                ],
            ],
            'google_pay_merchant_id' => [
                'value'       => $this->settings->googlePayMerchantId(),
                'label'       => __('Google Pay Merchant ID', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('e.g. 01234567890123456789', 'fluentcart-jcc'),
                'visible'     => $googlePayVisible,
            ],
            'google_pay_merchant_name' => [
                'value'       => $this->settings->googlePayMerchantName(),
                'label'       => __('Google Pay Merchant Name', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('Your store name', 'fluentcart-jcc'),
                'visible'     => $googlePayVisible,
            ],
            'google_pay_gateway_merchant_id_test' => [
                'value'       => $this->settings->get('google_pay_gateway_merchant_id_test'),
                'label'       => __('Gateway Merchant ID (Test)', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('Provided by JCC', 'fluentcart-jcc'),
                'visible'     => $googlePayVisible,
            ],
            'google_pay_gateway_merchant_id_live' => [
                'value'       => $this->settings->get('google_pay_gateway_merchant_id_live'),
                'label'       => __('Gateway Merchant ID (Live)', 'fluentcart-jcc'),
                'type'        => 'text',
                'placeholder' => __('Provided by JCC', 'fluentcart-jcc'),
                'visible'     => $googlePayVisible,
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        if (!$this->settings->googlePayEnabled()) {
            return [];
        }

        return [
            [
                'handle' => 'fluent-cart-jcc-google-pay-sdk',
                'src'    => 'https://pay.google.com/gp/p/js/pay.js',
                'deps'   => [],
                'in_footer' => true,
            ],
            [
                'handle' => 'fluent-cart-jcc-google-pay',
                'src'    => FLUENTCART_JCC_GATEWAY_URL . 'assets/js/google-pay.js',
                'deps'   => ['fluent-cart-jcc-google-pay-sdk'],
                'in_footer' => true,
            ],
        ];
    }

    public function getLocalizeData(): array
    {
        if (!$this->settings->googlePayEnabled()) {
            return [];
        }

        $listenerUrl = Arr::get($this->getListenerUrl(), 'listener_url');

        return [
            'FluentCartJccGooglePay' => [
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'listenerUrl'     => $listenerUrl,
                'environment'     => $this->settings->googlePayEnvironment(),
                'merchantId'      => $this->settings->googlePayMerchantId(),
                'merchantName'    => $this->settings->googlePayMerchantName(),
                'gatewayMerchant' => $this->settings->googlePayGatewayMerchantId(),
                'cardNetworks'    => $this->settings->googlePayCardNetworks(),
                'authMethods'     => $this->settings->googlePayAuthMethods(),
                'translations'    => [
                    'processing' => __('Processing Google Pay...', 'fluentcart-jcc'),
                    'failed'     => __('Google Pay authorisation failed. Please try another payment method.', 'fluentcart-jcc'),
                ],
            ],
        ];
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$transaction instanceof OrderTransaction) {
            return new \WP_Error('invalid_transaction', __('Invalid transaction record.', 'fluentcart-jcc'));
        }

        $gatewayOrderId = Arr::get($transaction->meta, 'jcc.orderId');

        if (!$gatewayOrderId) {
            return new \WP_Error('order_not_found', __('Missing JCC reference on the transaction.', 'fluentcart-jcc'));
        }

        $amountInCents = Helper::toCent($amount);

        $status = $this->api->refund($gatewayOrderId, $amountInCents);

        if (is_wp_error($status)) {
            return $status;
        }

        if (!empty($status['errorCode'])) {
            return new \WP_Error(
                'jcc_refund_failed',
                sprintf(__('Refund failed: %s', 'fluentcart-jcc'), Arr::get($status, 'errorMessage', __('Unknown error', 'fluentcart-jcc'))),
                $status
            );
        }

        PaymentHelper::updateTransactionRefundedTotal($transaction, $amountInCents);

        return [
            'status'  => 'success',
            'message' => __('Refund request sent to JCC.', 'fluentcart-jcc'),
            'data'    => $status,
        ];
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $merchant = Arr::get($data, "{$mode}_merchant_id");
        $password = Arr::get($data, "{$mode}_password");

        if (empty($merchant) || empty($password)) {
            return [
                'status'  => 'failed',
                'message' => __('Merchant ID and password are required.', 'fluentcart-jcc'),
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Credentials look good.', 'fluentcart-jcc'),
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        foreach (['test', 'live'] as $context) {
            foreach (['password', 'secret_key'] as $field) {
                $key = "{$context}_{$field}";
                $value = Arr::get($data, $key);

                if (!empty($value)) {
                    $data[$key] = Helper::encryptKey($value);
                } elseif (!empty($oldSettings[$key])) {
                    $data[$key] = $oldSettings[$key];
                }
            }
        }

        return $data;
    }

    public function handleGooglePayAjax(): void
    {
        if (!$this->settings->googlePayEnabled()) {
            wp_send_json_error(['message' => __('Google Pay is disabled.', 'fluentcart-jcc')], 403);
        }

        $token = sanitize_text_field(Arr::get($_POST, 'paymentToken'));
        $transactionUuid = sanitize_text_field(Arr::get($_POST, 'transactionUuid'));

        if (!$token || !$transactionUuid) {
            wp_send_json_error(['message' => __('Missing Google Pay payload.', 'fluentcart-jcc')], 400);
        }

        $transaction = OrderTransaction::query()->where('uuid', $transactionUuid)->first();
        if (!$transaction) {
            wp_send_json_error(['message' => __('Transaction not found.', 'fluentcart-jcc')], 404);
        }

        $gatewayOrderId = Arr::get($transaction->meta, 'jcc.orderId');
        if (!$gatewayOrderId) {
            wp_send_json_error(['message' => __('JCC order reference missing.', 'fluentcart-jcc')], 400);
        }

        $payload = [
            'userName'       => $this->settings->getMerchantId(),
            'password'       => $this->settings->getPassword(),
            'orderId'        => $gatewayOrderId,
            'paymentToken'   => $token,
            'gatewayMerchantId' => $this->settings->googlePayGatewayMerchantId(),
            'amount'         => $transaction->total,
        ];

        $response = $this->api->googlePayCharge($payload);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        if (!empty($response['errorCode'])) {
            wp_send_json_error([
                'message' => sprintf(__('Google Pay capture failed: %s', 'fluentcart-jcc'), Arr::get($response, 'errorMessage', __('Unknown error', 'fluentcart-jcc'))),
                'data'    => $response,
            ], 422);
        }

        wp_send_json_success([
            'message' => __('Google Pay authorisation accepted.', 'fluentcart-jcc'),
            'data'    => $response,
        ]);
    }

    public function handleRestWebhook($request)
    {
        $headers = wp_unslash($request->get_headers());
        $authUser = Arr::get($headers, 'php-auth-user.0');
        $authPass = Arr::get($headers, 'php-auth-pw.0');

        if ($authUser !== $this->settings->getMerchantId() || $authPass !== $this->settings->getPassword()) {
            return new \WP_Error('unauthorized', __('Invalid webhook credentials.', 'fluentcart-jcc'), ['status' => 401]);
        }

        $orderId = sanitize_text_field($request->get_param('mdOrder') ?: $request->get_param('orderId'));

        if (!$orderId) {
            return new \WP_Error('invalid_payload', __('Missing JCC order reference.', 'fluentcart-jcc'), ['status' => 400]);
        }

        $this->processGatewayStatusUpdate($orderId);

        return rest_ensure_response(['status' => 'ok']);
    }

    protected function handleHostedReturn(): void
    {
        $transaction = $this->getTransactionFromRequest();
        if (!$transaction) {
            wp_safe_redirect(home_url());
            exit;
        }

        $gatewayOrderId = sanitize_text_field(Arr::get($_GET, 'orderId'));

        $status = $this->processGatewayStatusUpdate($gatewayOrderId ?: Arr::get($transaction->meta, 'jcc.orderId'));

        if ($status instanceof \WP_Error) {
            $this->markTransactionFailed($transaction, $status->get_error_message());
        }

        wp_safe_redirect($transaction->getReceiptPageUrl(true));
        exit;
    }

    protected function handleHostedFailure(): void
    {
        $transaction = $this->getTransactionFromRequest();

        if ($transaction) {
            $this->markTransactionFailed($transaction, __('Customer cancelled or payment failed at JCC.', 'fluentcart-jcc'));
            wp_safe_redirect($transaction->getReceiptPageUrl(true));
            exit;
        }

        wp_safe_redirect(home_url());
        exit;
    }

    protected function handleCallbackRequest(bool $isIpn = false): void
    {
        $transaction = $this->getTransactionFromRequest();
        if (!$transaction) {
            if ($isIpn) {
                wp_send_json_error(['message' => __('Transaction not located.', 'fluentcart-jcc')], 404);
            }
            return;
        }

        $meta = $transaction->meta ?? [];
        $token = sanitize_text_field(Arr::get($_GET, 'fc_jcc_token'));

        if (Arr::get($meta, 'jcc.type') === 'dynamic') {
            $savedToken = Arr::get($meta, 'jcc.token');
            $expires = Arr::get($meta, 'jcc.expires');

            if (!$token || $token !== $savedToken || ($expires && time() > $expires)) {
                if ($isIpn) {
                    wp_send_json_error(['message' => __('Invalid callback token.', 'fluentcart-jcc')], 403);
                }
                return;
            }
        }

        $orderId = sanitize_text_field(Arr::get($_GET, 'mdOrder') ?: Arr::get($_GET, 'orderId'));

        $result = $this->processGatewayStatusUpdate($orderId ?: Arr::get($meta, 'jcc.orderId'));

        if ($isIpn) {
            if ($result instanceof \WP_Error) {
                wp_send_json_error(['message' => $result->get_error_message()], 422);
            }
            wp_send_json_success(['status' => 'ok']);
        }
    }

    protected function processGatewayStatusUpdate(?string $gatewayOrderId)
    {
        if (!$gatewayOrderId) {
            return new \WP_Error('missing_order', __('Missing gateway order identifier.', 'fluentcart-jcc'));
        }

        $status = $this->api->getOrderStatus($gatewayOrderId);

        if (is_wp_error($status)) {
            return $status;
        }

        $this->logger->info('jcc_order_status', $status);

        $transaction = OrderTransaction::query()
            ->whereJsonContains('meta->jcc->orderId', $gatewayOrderId)
            ->orWhereJsonContains('meta->jcc->payload->orderNumber', $gatewayOrderId)
            ->first();

        if (!$transaction) {
            return new \WP_Error('transaction_not_found', __('Transaction not found for the provided JCC order.', 'fluentcart-jcc'));
        }

        $orderStatus = (string) Arr::get($status, 'orderStatus');

        if (in_array($orderStatus, ['1', '2'], true)) {
            $this->markTransactionSucceeded($transaction, $status);
        } elseif (in_array($orderStatus, ['3', '4'], true)) {
            $this->markTransactionRefunded($transaction, $status);
        } elseif ($orderStatus === '6') {
            $this->markTransactionFailed($transaction, __('Order cancelled at gateway.', 'fluentcart-jcc'));
        }

        return $status;
    }

    protected function markTransactionSucceeded(OrderTransaction $transaction, array $status): void
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $meta = $transaction->meta ?? [];
        $meta['jcc']['last_status'] = $status;
        $meta['jcc']['returned_at'] = time();

        $transaction->meta = $meta;
        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->vendor_charge_id = Arr::get($status, 'authRefNum', Arr::get($status, 'orderId'));
        $transaction->payment_method = 'jcc';
        $transaction->payment_mode = $this->settings->getMode();
        $transaction->save();

        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

        fluent_cart_add_log(__('JCC Payment', 'fluentcart-jcc'), __('Payment confirmed by JCC.', 'fluentcart-jcc'), 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    protected function markTransactionRefunded(OrderTransaction $transaction, array $status): void
    {
        $meta = $transaction->meta ?? [];
        $meta['jcc']['last_status'] = $status;
        $transaction->meta = $meta;
        $transaction->save();

        fluent_cart_add_log(__('JCC Refund', 'fluentcart-jcc'), __('JCC reported a refund/reversal.', 'fluentcart-jcc'), 'info', [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    protected function markTransactionFailed(OrderTransaction $transaction, string $reason): void
    {
        if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $transaction->status = Status::TRANSACTION_FAILED;
        $meta = $transaction->meta ?? [];
        $meta['jcc']['failure_reason'] = $reason;
        $transaction->meta = $meta;
        $transaction->save();

        fluent_cart_error_log(__('JCC Payment Failed', 'fluentcart-jcc'), $reason, [
            'module_name' => 'order',
            'module_id'   => $transaction->order_id,
        ]);
    }

    protected function getTransactionFromRequest(): ?OrderTransaction
    {
        $uuid = get_query_var('fc_jcc_trx') ?: sanitize_text_field(Arr::get($_GET, 'fc_jcc_trx'));

        if (!$uuid) {
            return null;
        }

        return OrderTransaction::query()->where('uuid', $uuid)->first();
    }

    protected function redactSensitive(array $payload): array
    {
        $payload = $payload;
        $keys = ['password', 'secret_key'];
        foreach ($keys as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = '***';
            }
        }
        return $payload;
    }
}
