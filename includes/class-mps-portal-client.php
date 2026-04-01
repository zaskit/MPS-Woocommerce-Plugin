<?php
defined('ABSPATH') || exit;

class MPS_Portal_Client {

    private const PORTAL_URL = 'https://mpsgateway.com';
    private const TRANSIENT_KEY = 'mps_gateway_config';
    private const OPTION_KEY = 'mps_gateway_config_fallback';
    private const CACHE_TTL = 300; // 5 minutes

    public static function get_settings(): array {
        return get_option('woocommerce_mps_settings_settings', []);
    }

    private static function headers(): array {
        $settings = self::get_settings();
        return [
            'X-Api-Key'    => $settings['api_key'] ?? '',
            'X-Api-Secret' => $settings['api_secret'] ?? '',
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * Fetch active gateways with credentials from portal.
     * Returns cached data if available, falls back to stale cache if portal unreachable.
     */
    public static function get_gateways(bool $force_refresh = false): array {
        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $settings = self::get_settings();
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return [];
        }

        $response = wp_remote_get(self::PORTAL_URL . '/api/v1/gateways', [
            'headers' => self::headers(),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            MPS_Logger::error('Portal unreachable: ' . $response->get_error_message());
            return self::get_fallback_cache();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success']) || !isset($body['gateways'])) {
            MPS_Logger::error('Portal returned invalid response: ' . wp_json_encode($body));
            return self::get_fallback_cache();
        }

        $gateways = $body['gateways'];

        // Cache fresh data
        set_transient(self::TRANSIENT_KEY, $gateways, self::CACHE_TTL);
        update_option(self::OPTION_KEY, $gateways, false);

        return $gateways;
    }

    /**
     * Report a completed transaction to the portal for dashboard/analytics.
     * Fire-and-forget: failures are logged but don't affect the merchant site.
     */
    public static function report_transaction(array $data): void {
        $settings = self::get_settings();
        if (empty($settings['api_key'])) return;

        wp_remote_post(self::PORTAL_URL . '/api/v1/transactions/report', [
            'headers'  => self::headers(),
            'body'     => wp_json_encode($data),
            'timeout'  => 5,
            'blocking' => false,
        ]);
    }

    /**
     * Test connection to the portal.
     */
    public static function ping(): array {
        $response = wp_remote_get(self::PORTAL_URL . '/api/v1/ping', [
            'headers' => self::headers(),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        return json_decode(wp_remote_retrieve_body($response), true) ?: ['success' => false, 'error' => 'Invalid response'];
    }

    /**
     * Clear gateway cache and re-fetch.
     */
    public static function refresh(): array {
        delete_transient(self::TRANSIENT_KEY);
        return self::get_gateways(true);
    }

    private static function get_fallback_cache(): array {
        $fallback = get_option(self::OPTION_KEY, []);
        if (!empty($fallback)) {
            MPS_Logger::debug('Using fallback gateway cache');
        }
        return $fallback;
    }
}
