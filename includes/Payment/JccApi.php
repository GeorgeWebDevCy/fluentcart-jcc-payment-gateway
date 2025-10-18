<?php

namespace FluentCartJcc\Payment;

use FluentCartJcc\Logger;

class JccApi
{
    private const TEST_BASE = 'https://gateway-test.jcc.com.cy/payment/rest/';
    private const LIVE_BASE = 'https://gateway.jcc.com.cy/payment/rest/';

    private JccSettings $settings;
    private Logger $logger;

    public function __construct(JccSettings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function register(array $payload, bool $preAuth = false)
    {
        $path = $preAuth ? 'registerPreAuth.do' : 'register.do';

        return $this->request($path, $payload, 'POST', [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);
    }

    public function getOrderStatus(string $orderId)
    {
        return $this->request('getOrderStatusExtended.do', [
            'orderId'  => $orderId,
            'userName' => $this->settings->getMerchantId(),
            'password' => $this->settings->getPassword(),
        ]);
    }

    public function refund(string $orderId, int $amount)
    {
        return $this->request('refund.do', [
            'orderId'  => $orderId,
            'amount'   => $amount,
            'userName' => $this->settings->getMerchantId(),
            'password' => $this->settings->getPassword(),
        ]);
    }

    public function reverse(string $orderId, int $amount)
    {
        return $this->request('reverse.do', [
            'orderId'  => $orderId,
            'amount'   => $amount,
            'userName' => $this->settings->getMerchantId(),
            'password' => $this->settings->getPassword(),
        ]);
    }

    public function googlePayCharge(array $payload)
    {
        return $this->request('payment/google/payment.do', $payload);
    }

    public function updateCallbacks(array $payload)
    {
        $payload = wp_parse_args($payload, [
            'callbacks_enabled'     => true,
            'callback_http_method'  => 'POST',
            'callback_operations'   => 'deposited,approved,declinedByTimeout,reversed,refunded',
            'callback_type'         => strtoupper($this->settings->callbackType()),
        ]);

        $url = $this->baseUrl() . 'merchant/config';
        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->settings->getMerchantId() . ':' . $this->settings->getPassword()),
        ];

        return $this->requestRaw($url, wp_json_encode($payload), 'POST', $headers);
    }

    private function request(string $path, array $payload = [], string $method = 'POST', array $headers = [])
    {
        $url = $this->baseUrl() . ltrim($path, '/');

        return $this->requestRaw($url, $payload, $method, $headers);
    }

    private function requestRaw(string $url, $payload, string $method = 'POST', array $headers = [])
    {
        $args = [
            'timeout' => 45,
            'headers' => $headers,
            'method'  => strtoupper($method),
        ];

        if (is_array($payload)) {
            $args['body'] = $payload;
        } else {
            $args['body'] = $payload;
        }

        $this->logger->info('jcc_request', [
            'url'     => $url,
            'method'  => $args['method'],
            'payload' => is_array($payload) ? $this->redactSensitive($payload) : $payload,
        ]);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error('jcc_request_failed', [
                'url'       => $url,
                'method'    => $args['method'],
                'payload'   => is_array($payload) ? $this->redactSensitive($payload) : $payload,
                'errors'    => $response->get_error_messages(),
            ]);
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            parse_str($body, $parsed);
            if (!empty($parsed)) {
                $decoded = $parsed;
            }
        }

        if ($status >= 400 || (!$decoded && $body)) {
            $this->logger->error('jcc_request_http_error', [
                'url'      => $url,
                'method'   => $args['method'],
                'status'   => $status,
                'response' => $body,
            ]);

            return new \WP_Error(
                'jcc_http_error',
                sprintf(__('JCC API responded with HTTP %d', 'fluentcart-jcc'), $status),
                [
                    'status'   => $status,
                    'response' => $body,
                ]
            );
        }

        $this->logger->info('jcc_response', [
            'url'      => $url,
            'status'   => $status,
            'response' => $decoded,
        ]);

        return $decoded ?: [];
    }

    private function baseUrl(): string
    {
        return $this->settings->getMode() === 'live' ? self::LIVE_BASE : self::TEST_BASE;
    }

    private function redactSensitive(array $payload): array
    {
        $sensitive = ['password', 'pan', 'cardNumber', 'paymentToken', 'gatewayMerchantId'];
        foreach ($payload as $key => &$value) {
            if (in_array($key, $sensitive, true)) {
                $value = '***';
            } elseif (is_array($value)) {
                $value = $this->redactSensitive($value);
            }
        }
        unset($value);

        return $payload;
    }
}
