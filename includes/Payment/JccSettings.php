<?php

namespace FluentCartJcc\Payment;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class JccSettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_jcc_gateway';

    /**
     * @var array
     */
    public $settings = [];

    public function __construct()
    {
        $stored = get_option($this->methodHandler, []);
        $this->settings = wp_parse_args(is_array($stored) ? $stored : [], static::getDefaults());
    }

    public static function getDefaults(): array
    {
        return [
            'is_active' => 'no',
            'payment_mode' => 'test', // test|live
            'test_credentials' => [
                'merchant_id' => '',
                'password' => '',
                'token' => '',
            ],
            'live_credentials' => [
                'merchant_id' => '',
                'password' => '',
                'token' => '',
            ],
            'stage_mode' => 'one-stage', // one-stage|two-stage
            'order_status_paid' => 'completed',
            'success_url' => '',
            'fail_url' => '',
            'callbacks_enabled' => 'yes',
            'callback_mode' => 'static', // static|dynamic
            'logging_enabled' => 'yes',
            'send_order' => 'no',
            'tax_system' => '0',
            'tax_type' => '0',
            'version_ffd' => 'v1_05',
            'payment_method_type' => '1',
            'payment_object_type' => '1',
            'payment_object_type_delivery' => '4',
            'min_order_total' => '',
            'max_order_total' => '',
            'success_status' => '',
            'back_to_shop_url' => '',
            'back_to_shop_label' => '',
            'fes_cashbox_id' => '',
            'google_pay' => [
                'enabled' => 'no',
                'merchant_id' => '',
                'merchant_name' => '',
                'mode' => 'TEST',
                'sections' => ['checkout'],
                'button_type' => 'buy',
                'button_color' => 'default',
            ],
        ];
    }

    public function getApiCredentials(): array
    {
        $mode = $this->get('payment_mode', 'test');
        $key = $mode === 'live' ? 'live_credentials' : 'test_credentials';
        $credentials = $this->get($key, []);

        $merchantId = $credentials['merchant_id'] ?? '';
        $password = $credentials['password'] ?? '';
        $token = $credentials['token'] ?? '';

        if ($token) {
            $decoded = base64_decode($token, true);
            if ($decoded && strpos($decoded, ':') !== false) {
                [$merchantId, $password] = array_pad(explode(':', $decoded, 2), 2, '');
            }
        }

        return [
            'merchant_id' => $merchantId,
            'password' => $password,
        ];
    }

    public function isLiveMode(): bool
    {
        return $this->get('payment_mode') === 'live';
    }

    public function isLoggingEnabled(): bool
    {
        return $this->get('logging_enabled') === 'yes';
    }

    public function isCallbackEnabled(): bool
    {
        return $this->get('callbacks_enabled') === 'yes';
    }

    public function getCallbackMode(): string
    {
        return $this->get('callback_mode', 'static');
    }

    public function getGooglePayConfig(): array
    {
        $config = $this->get('google_pay', []);
        $config['enabled'] = $config['enabled'] ?? 'no';
        $config['sections'] = $config['sections'] ?? [];

        return $config;
    }

    public function getSecretKey(): string
    {
        $mode = $this->get('payment_mode');
        $key = $mode === 'live' ? 'live_credentials' : 'test_credentials';
        $credentials = $this->get($key, []);

        $raw = $credentials['password'] ?? '';
        if (!$raw) {
            return '';
        }

        if (method_exists(Helper::class, 'decryptKey')) {
            return Helper::decryptKey($raw);
        }

        return $raw;
    }

    public function get($key = '', $default = null)
    {
        return Arr::get($this->settings, $key, $default);
    }

    public function getMode()
    {
        return $this->get('payment_mode', 'test');
    }

    public function isActive(): bool
    {
        return $this->get('is_active') === 'yes';
    }
}
