<?php

namespace FluentCartJcc;

class Logger
{
    public static function log(string $message, array $context = []): void
    {
        $enabled = apply_filters('fluentcart_jcc/logging_enabled', true, $message, $context);
        if (!$enabled) {
            return;
        }

        $uploadDir = wp_upload_dir();
        $dir = trailingslashit($uploadDir['basedir']) . 'fluentcart-jcc-logs';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $file = $dir . '/jcc-' . gmdate('Y-m') . '.log';
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;

        if ($context) {
            $line .= ' ' . wp_json_encode($context);
        }

        $line .= PHP_EOL;
        file_put_contents($file, $line, FILE_APPEND);
    }
}
