<?php

namespace FluentCartJcc;

class Updater
{
    private const RELEASE_TRANSIENT = 'fluentcart_jcc_latest_release';
    private const API_URL = 'https://api.github.com/repos/GeorgeWebDevCy/fluentcart-jcc-payment-gateway/releases/latest';

    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
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

        add_filter('pre_set_site_transient_update_plugins', [$this, 'maybeInjectUpdate']);
        add_filter('plugins_api', [$this, 'overridePluginInformation'], 10, 3);
    }

    public function maybeInjectUpdate($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->getLatestRelease();

        if (!$release || empty($release['version']) || empty($release['package'])) {
            return $transient;
        }

        if (version_compare($release['version'], FLUENTCART_JCC_GATEWAY_VERSION, '<=')) {
            return $transient;
        }

        $pluginFile = plugin_basename(FLUENTCART_JCC_GATEWAY_FILE);

        $transient->response[$pluginFile] = (object) [
            'slug'        => 'fluentcart-jcc-payment-gateway',
            'plugin'      => $pluginFile,
            'new_version' => $release['version'],
            'url'         => $release['homepage'] ?? '',
            'package'     => $release['package'],
        ];

        return $transient;
    }

    public function overridePluginInformation($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== 'fluentcart-jcc-payment-gateway') {
            return $result;
        }

        $release = $this->getLatestRelease();

        if (!$release) {
            return $result;
        }

        $description = $release['notes'] ?? '';

        return (object) [
            'name'          => 'FluentCart JCC Payment Gateway',
            'slug'          => 'fluentcart-jcc-payment-gateway',
            'version'       => $release['version'] ?? FLUENTCART_JCC_GATEWAY_VERSION,
            'author'        => '<a href="https://github.com/GeorgeWebDevCy">GeorgeWebDevCy</a>',
            'download_link' => $release['package'] ?? '',
            'tested'        => '',
            'requires'      => '6.0',
            'sections'      => [
                'description' => wpautop(!empty($description) ? $description : __('No release notes available.', 'fluentcart-jcc')),
            ],
            'homepage'      => $release['homepage'] ?? 'https://github.com/GeorgeWebDevCy/fluentcart-jcc-payment-gateway',
        ];
    }

    private function getLatestRelease(): ?array
    {
        $cached = get_site_transient(self::RELEASE_TRANSIENT);

        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'fluentcart-jcc-updater',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return null;
        }

        $version = isset($body['tag_name']) ? ltrim((string) $body['tag_name'], 'v') : null;

        if (!$version) {
            return null;
        }

        $package = $this->resolvePackageUrl($body);

        $release = [
            'version'  => $version,
            'package'  => $package,
            'homepage' => $body['html_url'] ?? '',
            'notes'    => $body['body'] ?? '',
        ];

        set_site_transient(self::RELEASE_TRANSIENT, $release, HOUR_IN_SECONDS * 6);

        return $release;
    }

    private function resolvePackageUrl(array $release): string
    {
        $assets = $release['assets'] ?? [];

        if (is_array($assets)) {
            foreach ($assets as $asset) {
                $url = $asset['browser_download_url'] ?? '';

                if ($url && str_ends_with($url, '.zip')) {
                    return $url;
                }
            }
        }

        return $release['zipball_url'] ?? '';
    }
}

