<?php

namespace FluentCartJcc;

use FluentCartJcc\Payment\JccGateway;

class Plugin
{
    public static function boot(): void
    {
        add_action('plugins_loaded', [__CLASS__, 'maybeRegister']);
    }

    public static function maybeRegister(): void
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        add_action('fluent_cart/register_payment_methods', function () {
            $gateway = new JccGateway();
            fluent_cart_api()->registerCustomPaymentMethod('jcc_gateway', $gateway);
        });
    }
}
