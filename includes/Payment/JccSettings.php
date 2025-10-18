<?php

namespace FluentCartJcc\Payment;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\Framework\Support\Arr;

class JccSettings extends BaseGatewaySettings
{
    protected StoreSettings $storeSettings;

    public function __construct()
    {
        parent::__construct();

        $this->methodHandler = 'fluent_cart_payment_settings_jcc';

        $stored = fluent_cart_get_option($this->methodHandler, []);
        $defaults = static::getDefaults();

        if (!is_array($stored) || empty($stored)) {
            $stored = $defaults;
        } else {
            $stored = wp_parse_args($stored, $defaults);
        }

        $this->settings = Arr::mergeMissingValues($stored, $defaults);
        $this->storeSettings = new StoreSettings();
    }

    public static function getDefaults(): array
    {
        return [
            'is_active' => 'no',
            'payment_mode' => 'test',

            // Credentials
            'test_merchant_id' => '',
            'test_password' => '',
            'test_secret_key' => '',
            'live_merchant_id' => '',
            'live_password' => '',
            'live_secret_key' => '',

            // Gateway behaviour
            'stage_mode' => 'single', // single (one-stage) or dual (two-stage/preauth)
            'send_order' => 'no',
            'send_billing_data' => 'yes',
            'callback_type' => 'static', // static or dynamic
            'dynamic_callback_nonce_lifetime' => 30, // minutes
            'static_callback_url' => '',
            'callback_username' => '',
            'callback_password' => '',
            'success_url' => '',
            'fail_url' => '',
            'order_status_paid' => '',

            // Fiscalisation
            'enable_fiscalisation' => 'no',
            'fiscal_cashbox_id' => '',
            'fiscal_tax_system' => '',
            'fiscal_tax_type' => '',
            'item_payment_method_type' => '1',
            'item_payment_object_type' => '1',
            'shipping_payment_method_type' => '4',
            'shipping_payment_object_type' => '4',

            // Google Pay
            'enable_google_pay' => 'no',
            'google_pay_gateway_merchant_id_test' => '',
            'google_pay_gateway_merchant_id_live' => '',
            'google_pay_merchant_id' => '',
            'google_pay_merchant_name' => '',
            'google_pay_environment' => 'TEST',
            'google_pay_allowed_card_networks' => ['VISA', 'MASTERCARD'],
            'google_pay_allowed_auth_methods' => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],

            // Logging
            'enable_logging' => 'no',
        ];
    }

    public function isActive(): bool
    {
        return $this->get('is_active') === 'yes';
    }

    public function get($key = '')
    {
        if ($key === '') {
            return $this->settings;
        }

        return Arr::get($this->settings, $key);
    }

    public function getMode(): string
    {
        $configured = Arr::get($this->settings, 'payment_mode');

        if (in_array($configured, ['test', 'live'], true)) {
            return $configured;
        }

        return $this->storeSettings->get('order_mode');
    }

    public function getMerchantId(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        return trim((string) Arr::get($this->settings, "{$mode}_merchant_id", ''));
    }

    public function getPassword(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        $value = (string) Arr::get($this->settings, "{$mode}_password", '');
        return $value ? Helper::decryptKey($value) : '';
    }

    public function getSecretKey(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        $value = (string) Arr::get($this->settings, "{$mode}_secret_key", '');
        return $value ? Helper::decryptKey($value) : '';
    }

    public function stageMode(): string
    {
        $value = $this->get('stage_mode');
        return $value === 'dual' ? 'dual' : 'single';
    }

    public function shouldSendOrderBundle(): bool
    {
        return $this->get('send_order') === 'yes';
    }

    public function shouldSendBilling(): bool
    {
        return $this->get('send_billing_data') !== 'no';
    }

    public function callbackType(): string
    {
        $type = $this->get('callback_type');
        return $type === 'dynamic' ? 'dynamic' : 'static';
    }

    public function dynamicCallbackTtl(): int
    {
        return max(5, (int) $this->get('dynamic_callback_nonce_lifetime'));
    }

    public function staticCallbackUrl(): string
    {
        return (string) $this->get('static_callback_url');
    }

    public function callbackUsername(): string
    {
        return (string) $this->get('callback_username');
    }

    public function callbackPassword(): string
    {
        return (string) $this->get('callback_password');
    }

    public function enableLogging(): bool
    {
        return $this->get('enable_logging') === 'yes';
    }

    public function googlePayEnabled(): bool
    {
        return $this->get('enable_google_pay') === 'yes';
    }

    public function googlePayMerchantId(): string
    {
        return trim((string) $this->get('google_pay_merchant_id'));
    }

    public function googlePayMerchantName(): string
    {
        return trim((string) $this->get('google_pay_merchant_name'));
    }

    public function googlePayEnvironment(): string
    {
        $env = strtoupper((string) $this->get('google_pay_environment'));
        return $env === 'PRODUCTION' ? 'PRODUCTION' : 'TEST';
    }

    public function googlePayGatewayMerchantId(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        $key = $mode === 'live' ? 'google_pay_gateway_merchant_id_live' : 'google_pay_gateway_merchant_id_test';
        return trim((string) $this->get($key));
    }

    public function googlePayCardNetworks(): array
    {
        $networks = $this->get('google_pay_allowed_card_networks');
        if (!is_array($networks) || empty($networks)) {
            return ['VISA', 'MASTERCARD'];
        }
        return array_values(array_unique(array_map('strtoupper', $networks)));
    }

    public function googlePayAuthMethods(): array
    {
        $methods = $this->get('google_pay_allowed_auth_methods');
        if (!is_array($methods) || empty($methods)) {
            return ['PAN_ONLY', 'CRYPTOGRAM_3DS'];
        }
        return array_values(array_unique(array_map('strtoupper', $methods)));
    }
}
