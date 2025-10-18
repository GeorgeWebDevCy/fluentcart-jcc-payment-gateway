<?php

namespace FluentCartJcc;

class Autoloader
{
    public static function boot(): void
    {
        spl_autoload_register([__CLASS__, 'load']);
    }

    /**
     * @param string $class
     * @return void
     */
    private static function load(string $class): void
    {
        $prefix = __NAMESPACE__ . '\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $relative = str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $relative);
        $path = FC_JCC_PLUGIN_PATH . 'includes/' . $relative . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
