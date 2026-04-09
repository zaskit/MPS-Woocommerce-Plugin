<?php
defined('ABSPATH') || exit;

class MPS_Portal_Client {

    private const PORTAL_URL_LIVE    = 'https://mpsgateway.com';
    private const PORTAL_URL_STAGING = 'https://staging.mpsgateway.com';
    private const TRANSIENT_KEY = 'mps_gateway_config';
    private const OPTION_KEY = 'mps_gateway_config_fallback';

    private static function fallback_key(): string {
        return self::OPTION_KEY . '_' . (self::get_settings()['portal_mode'] ?? 'live');
    }
    private const RETRY_QUEUE_KEY = 'mps_transaction_retry_queue';
    private const CACHE_TTL = 150; // 2.5 minutes

    public static function get_settings(): array {
        return get_option('woocommerce_mps_settings_settings', []);
    }

    /**
     * Resolve the portal base URL based on the configured mode.
     * Defaults to LIVE if unset.
     */
    public static function portal_url(): string {
        $settings = self::get_settings();
        $mode = $settings['portal_mode'] ?? 'live';
        return $mode === 'staging' ? self::PORTAL_URL_STAGING : self::PORTAL_URL_LIVE;
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

        $response = wp_remote_get(self::portal_url() . '/api/v1/gateways', [
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

        set_transient(self::TRANSIENT_KEY, $gateways, self::CACHE_TTL);
        update_option(self::fallback_key(), $gateways, false);

        return $gateways;
    }

    /**
     * Report a transaction to the portal with retry on failure.
     * First attempt is blocking. On failure, queued for WP Cron retry.
     */
    public static function report_transaction(array $data): bool {
        $settings = self::get_settings();
        if (empty($settings['api_key'])) return false;

        $response = wp_remote_post(self::portal_url() . '/api/v1/transactions/report', [
            'headers'  => self::headers(),
            'body'     => wp_json_encode($data),
            'timeout'  => 8,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            MPS_Logger::error('Portal report failed (queued for retry): ' . $response->get_error_message(), 'mps-reporter');
            self::queue_for_retry($data);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            MPS_Logger::error("Portal report HTTP {$code} (queued for retry)", 'mps-reporter');
            self::queue_for_retry($data);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) {
            MPS_Logger::error('Portal report rejected: ' . wp_json_encode($body), 'mps-reporter');
            self::queue_for_retry($data);
            return false;
        }

        return true;
    }

    /**
     * Add failed report to retry queue.
     */
    private static function queue_for_retry(array $data): void {
        $queue = get_option(self::RETRY_QUEUE_KEY, []);

        // Prevent queue from growing indefinitely (max 200 items)
        if (count($queue) >= 200) {
            array_shift($queue); // Drop oldest
        }

        $data['_retry_added'] = time();
        $data['_retry_count'] = ($data['_retry_count'] ?? 0);
        $queue[] = $data;

        update_option(self::RETRY_QUEUE_KEY, $queue, false);
    }

    /**
     * Process retry queue. Called by WP Cron.
     */
    public static function process_retry_queue(): void {
        $queue = get_option(self::RETRY_QUEUE_KEY, []);
        if (empty($queue)) return;

        $settings = self::get_settings();
        if (empty($settings['api_key'])) return;

        $remaining = [];
        $processed = 0;
        $max_per_run = 20; // Process up to 20 per cron run

        foreach ($queue as $data) {
            if ($processed >= $max_per_run) {
                $remaining[] = $data;
                continue;
            }

            // Drop items older than 7 days or retried more than 10 times
            $age = time() - ($data['_retry_added'] ?? 0);
            $retries = ($data['_retry_count'] ?? 0);
            if ($age > 604800 || $retries >= 10) {
                MPS_Logger::error("Portal report dropped after {$retries} retries (age: {$age}s): order_ref=" . ($data['order_ref'] ?? '?'), 'mps-reporter');
                continue;
            }

            // Remove internal retry fields before sending
            $send_data = $data;
            unset($send_data['_retry_added'], $send_data['_retry_count']);

            $response = wp_remote_post(self::portal_url() . '/api/v1/transactions/report', [
                'headers'  => self::headers(),
                'body'     => wp_json_encode($send_data),
                'timeout'  => 10,
                'blocking' => true,
            ]);

            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

            if (!is_wp_error($response) && $code >= 200 && $code < 300 && !empty($body['success'])) {
                $processed++;
                MPS_Logger::debug("Retry success: order_ref=" . ($data['order_ref'] ?? '?'), 'mps-reporter');
            } else {
                $data['_retry_count'] = $retries + 1;
                $remaining[] = $data;
                $processed++;
            }
        }

        update_option(self::RETRY_QUEUE_KEY, $remaining, false);

        if (!empty($remaining)) {
            MPS_Logger::debug("Retry queue: " . count($remaining) . " items remaining", 'mps-reporter');
        }
    }

    /**
     * Reconcile recent MPS orders with portal.
     * Sends list of recent order refs, portal returns which ones are missing.
     * Plugin then re-reports the missing ones.
     */
    public static function reconcile(): void {
        $settings = self::get_settings();
        if (empty($settings['api_key'])) return;

        // Get recent MPS orders from last 7 days
        $orders = wc_get_orders([
            'limit'        => 200,
            'date_created' => '>' . gmdate('Y-m-d', strtotime('-7 days')),
            'payment_method' => '', // Can't filter by prefix, so we filter below
            'return'       => 'ids',
        ]);

        if (empty($orders)) return;

        // Build list of MPS orders with their status
        $order_list = [];
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            $method = $order->get_payment_method();
            if (strpos($method, 'mps_') !== 0) continue;

            $status = $order->get_status();
            // Only report finalized orders (processing, completed, failed)
            if (!in_array($status, ['processing', 'completed', 'failed', 'refunded'])) continue;

            $order_list[] = [
                'order_ref'       => (string) $order_id,
                'gateway_id'      => $order->get_meta('_mps_portal_gateway_id'),
                'status'          => in_array($status, ['processing', 'completed', 'refunded']) ? 'approved' : 'declined',
                'amount'          => (float) $order->get_total(),
                'currency'        => $order->get_currency(),
                'processor_tx_id' => $order->get_meta('_mps_processor_tx_id') ?: $order->get_transaction_id(),
                'card_brand'      => $order->get_meta('_mps_card_brand') ?: null,
                'last_four'       => $order->get_meta('_mps_last_four') ?: null,
                'customer_email'  => $order->get_billing_email(),
                'is_3ds'          => in_array($order->get_meta('_mps_processor_type'), ['3d', 'hosted']),
            ];
        }

        if (empty($order_list)) return;

        // Send to portal reconciliation endpoint
        $response = wp_remote_post(self::portal_url() . '/api/v1/transactions/reconcile', [
            'headers'  => self::headers(),
            'body'     => wp_json_encode(['orders' => $order_list]),
            'timeout'  => 30,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            MPS_Logger::error('Reconciliation failed: ' . $response->get_error_message(), 'mps-reporter');
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $synced = $body['synced'] ?? 0;
        $total  = count($order_list);

        MPS_Logger::info("Reconciliation: {$synced} synced out of {$total} orders checked", 'mps-reporter');
    }

    /**
     * Test connection to the portal.
     */
    public static function ping(): array {
        $response = wp_remote_get(self::portal_url() . '/api/v1/ping', [
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
        $fallback = get_option(self::fallback_key(), []);
        if (!empty($fallback)) {
            MPS_Logger::debug('Using fallback gateway cache');
        }
        return $fallback;
    }
}
