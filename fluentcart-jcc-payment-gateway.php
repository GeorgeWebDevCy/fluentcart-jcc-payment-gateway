<?php
/**
 * Plugin Name: FluentCart JCC Payment Gateway
 * Description: Integrates the JCC payment gateway with FluentCart.
 * Version: 0.1.0
 * Author: George
 * Requires Plugins: fluent-cart
*/

if (!defined('ABSPATH')) {
    exit;
}

define('FC_JCC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FC_JCC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FC_JCC_PLUGIN_VERSION', '0.1.0');

require_once FC_JCC_PLUGIN_PATH . 'includes/Autoloader.php';

FluentCartJcc\Autoloader::boot();
FluentCartJcc\Plugin::boot();
