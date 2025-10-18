<?php
/**
 * Plugin Name: FluentCart JCC Payment Gateway
 * Description: Adds the JCC hosted payment gateway (including callbacks, refunds, Google Pay, and fiscal payloads) to FluentCart.
 * Author: FluentCart JCC Integration Team
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package FluentCartJcc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('FLUENTCART_JCC_GATEWAY_VERSION')) {
    return;
}

define('FLUENTCART_JCC_GATEWAY_VERSION', '0.1.0');
define('FLUENTCART_JCC_GATEWAY_FILE', __FILE__);
define('FLUENTCART_JCC_GATEWAY_PATH', plugin_dir_path(__FILE__));
define('FLUENTCART_JCC_GATEWAY_URL', plugin_dir_url(__FILE__));

/**
 * Simple PSR-4 autoloader for the plugin classes.
 */
spl_autoload_register(static function ($class) {
    $prefix = 'FluentCartJcc\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = FLUENTCART_JCC_GATEWAY_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

/**
 * Display admin notice when FluentCart is missing.
 */
function fluentcart_jcc_gateway_missing_dependency_notice(): void
{
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('FluentCart JCC Payment Gateway requires FluentCart to be installed and active.', 'fluentcart-jcc'); ?></p>
    </div>
    <?php
}

/**
 * Verify FluentCart dependency during activation.
 */
function fluentcart_jcc_gateway_activate(): void
{
    if (!function_exists('fluent_cart_api')) {
        deactivate_plugins(plugin_basename(FLUENTCART_JCC_GATEWAY_FILE));
        wp_die(
            esc_html__('FluentCart JCC Payment Gateway requires FluentCart to be installed and active.', 'fluentcart-jcc'),
            esc_html__('Plugin dependency check failed', 'fluentcart-jcc'),
            ['back_link' => true]
        );
    }
}
register_activation_hook(FLUENTCART_JCC_GATEWAY_FILE, 'fluentcart_jcc_gateway_activate');

add_action('plugins_loaded', static function () {
    if (!function_exists('fluent_cart_api')) {
        add_action('admin_notices', 'fluentcart_jcc_gateway_missing_dependency_notice');
        return;
    }

    (FluentCartJcc\Plugin::instance())->boot();
});
