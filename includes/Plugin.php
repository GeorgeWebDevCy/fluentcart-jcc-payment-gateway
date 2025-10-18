<?php

namespace FluentCartJcc;

class Plugin
{
    protected static $registered = false;

    public static function boot(): void
    {
        add_action('fluent_cart/register_payment_methods', [__CLASS__, 'registerGateway']);
        add_action('init', [__CLASS__, 'maybeRegisterOnInit'], 20);
    }

    public static function registerGateway(): void
    {
        if (self::$registered || !function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->registerCustomPaymentMethod('jcc_gateway', new Payment\JccGateway());
        self::$registered = true;
    }

    public static function maybeRegisterOnInit(): void
    {
        if (self::$registered) {
            return;
        }

        self::registerGateway();
    }
}
