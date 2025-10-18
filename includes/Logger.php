<?php

namespace FluentCartJcc;

class Logger
{
    private bool $enabled;

    public function __construct(bool $enabled = false)
    {
        $this->enabled = $enabled;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    private function write(string $level, string $event, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!function_exists('wp_upload_dir')) {
            return;
        }

        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            return;
        }

        $logDir = trailingslashit($uploadDir['basedir']) . 'fluentcart-jcc-logs/';

        if (!wp_mkdir_p($logDir)) {
            return;
        }

        $filename = $logDir . gmdate('Y-m') . '.log';
        $record = wp_json_encode([
            'timestamp' => gmdate('c'),
            'level'     => $level,
            'event'     => $event,
            'context'   => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!$record) {
            $record = sprintf(
                '{"timestamp":"%s","level":"%s","event":"%s"}',
                gmdate('c'),
                $level,
                sanitize_text_field($event)
            );
        }

        file_put_contents($filename, $record . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
