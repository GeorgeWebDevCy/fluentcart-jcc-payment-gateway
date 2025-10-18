<?php

namespace FluentCartJcc;

use FluentCartJcc\Payment\JccGateway;

class Plugin
{
    private static ?Plugin $instance = null;
    private bool $booted = false;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('fluent_cart/init', function () {
            $this->registerGateway();
        }, 20);
    }

    private function registerGateway(): void
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->registerCustomPaymentMethod('jcc', new JccGateway());
    }
}
