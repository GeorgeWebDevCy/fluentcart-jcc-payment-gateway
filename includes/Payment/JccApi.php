<?php

namespace FluentCartJcc\Payment;

use FluentCartJcc\Logger;

class JccApi
{
    public const PROD_URL = 'https://gateway.jcc.com.cy/payment/rest/';
    public const TEST_URL = 'https://gateway-test.jcc.com.cy/payment/rest/';

    private $settings;

    public function __construct(JccSettings $settings)
    {
        $this->settings = $settings;
    }

    public function registerOrder(array $payload): array
    {
        $stageMode = $this->settings->get('stage_mode', 'one-stage');
        $endpoint = $stageMode === 'two-stage' ? 'registerPreAuth.do' : 'register.do';

        return $this->request('POST', $endpoint, $payload);
    }

    public function getOrderStatus(string $orderId): array
    {
        $payload = [
            'orderId' => $orderId,
        ];

        return $this->request('POST', 'getOrderStatusExtended.do', $payload);
    }

    public function refund(string $orderId, int $amount = 0): array
    {
        $endpoint = $amount > 0 ? 'refund.do' : 'reverse.do';
        $payload = ['orderId' => $orderId];

        if ($amount > 0) {
            $payload['amount'] = $amount;
        }

        return $this->request('POST', $endpoint, $payload);
    }

    public function updateCallback(string $merchantId, string $callbackUrl, string $mode = 'STATIC'): array
    {
        $base = $this->baseUrl();
        $endpoint = str_replace('payment/rest/', 'mportal/mvc/public/merchant/update', $base);
        $endpoint .= substr($merchantId, 0, -4);

        $payload = [
            'callbacks_enabled' => true,
            'callback_type' => strtoupper($mode),
            'callback_http_method' => 'GET',
            'callback_operations' => 'deposited,approved,declinedByTimeout,reversed,refunded',
        ];

        if ($mode !== 'DYNAMIC') {
            $payload['callback_addresses'] = $callbackUrl;
        }

        return $this->request('POST', $endpoint, $payload, ['json' => true]);
    }

    public function googlePay(array $payload): array
    {
        $base = $this->baseUrl();
        $endpoint = str_replace('payment/rest', 'payment/google', $base) . 'payment.do';

        return $this->request('POST', $endpoint, $payload, ['json' => true]);
    }

    private function request(string $method, string $endpoint, array $payload, array $options = []): array
    {
        $credentials = $this->settings->getApiCredentials();
        $baseUrl = $this->baseUrl();
        $url = $this->normalizeEndpoint($baseUrl, $endpoint);

        $authPayload = [
            'userName' => $credentials['merchant_id'],
            'password' => $credentials['password'],
        ];

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($credentials['merchant_id'] . ':' . $credentials['password']),
        ];

        if (!empty($options['json'])) {
            $headers['Content-Type'] = 'application/json';
            $body = wp_json_encode($payload);
        } else {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query(array_merge($authPayload, $payload), '', '&');
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
        ];

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->maybeLog('API request failure', [
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ]);

            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        if ($code >= 400 || isset($parsed['errorCode']) && $parsed['errorCode'] !== '0') {
            $message = $parsed['errorMessage'] ?? ('Unexpected response code ' . $code);
            $this->maybeLog('API request error', [
                'endpoint' => $endpoint,
                'code' => $code,
                'body' => $parsed,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'data' => $parsed,
            ];
        }

        $this->maybeLog('API request success', [
            'endpoint' => $endpoint,
            'response' => $parsed,
        ]);

        return [
            'success' => true,
            'data' => $parsed,
        ];
    }

    private function baseUrl(): string
    {
        return $this->settings->isLiveMode() ? self::PROD_URL : self::TEST_URL;
    }

    private function normalizeEndpoint(string $base, string $endpoint): string
    {
        if (strpos($endpoint, 'http') === 0) {
            return $endpoint;
        }

        return trailingslashit($base) . ltrim($endpoint, '/');
    }

    private function maybeLog(string $message, array $context = []): void
    {
        if ($this->settings->isLoggingEnabled()) {
            Logger::log($message, $context);
        }
    }
}
