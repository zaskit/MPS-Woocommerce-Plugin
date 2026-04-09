<?php
defined('ABSPATH') || exit;

/**
 * MPS K-Processor Gateway (Payvelonix).
 *
 * Redirect-based payment — customer enters card details on the Payvelonix hosted page.
 * Works for both K-Processor 2D and 3D (same flow, separate credentials per assignment).
 *
 * Flow: session → redirect → customer pays on Payvelonix → callback POST → order completed.
 */
class MPS_KProcessor extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->has_fields = false; // No card fields — redirect to Payvelonix
        $this->supports[] = 'refunds';
    }

    /**
     * Show description only (no card form).
     */
    public function payment_fields(): void {
        if ($this->description) {
            echo '<div class="mps-hosted-notice" style="padding:12px 0;">' . wpautop(wp_kses_post($this->description)) . '</div>';
        }
    }

    /**
     * No card fields to validate.
     */
    public function validate_fields(): bool {
        return true;
    }

    /**
     * Process payment: create session on Payvelonix, redirect customer.
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        $checkout_url = rtrim($this->credentials['checkout_url'] ?? '', '/');
        $merchant_key = $this->credentials['merchant_key'] ?? '';
        $password     = $this->credentials['merchant_password'] ?? '';

        if (!$checkout_url || !$merchant_key || !$password) {
            wc_add_notice('Payment configuration error. Please contact the store.', 'error');
            return ['result' => 'fail'];
        }

        $amount   = number_format((float) $order->get_total(), 2, '.', '');
        $currency = $order->get_currency() ?: 'USD';
        $description = 'Payment Order # ' . $order_id . ' in the store ' . home_url('/');

        // Hash: SHA1(MD5(UPPER(order_id + amount + currency + description + password)))
        $hash_string = $order_id . $amount . $currency . $description . $password;
        $hash = sha1(md5(strtoupper($hash_string)));

        // Build customer data
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (empty($customer_name)) $customer_name = 'Customer';

        // State name resolution
        $state = 'NA';
        if ($order->get_billing_state()) {
            $states = WC()->countries->get_states($order->get_billing_country());
            $state = $states[$order->get_billing_state()] ?? $order->get_billing_state();
        }

        // Callback URL: REST API endpoint on this site
        $callback_url = rest_url('mps-kprocessor/v1/callback');

        // Success URL with polling params (callback may not arrive before customer returns)
        $success_url = add_query_arg([
            'mps_kp_poll' => '1',
            'order_id'    => $order_id,
            'key'         => $order->get_order_key(),
        ], $this->get_return_url($order));
        $cancel_url = $order->get_cancel_order_url_raw();

        $body = [
            'merchant_key' => $merchant_key,
            'operation'    => 'purchase',
            'order'        => [
                'number'      => (string) $order_id,
                'description' => $description,
                'amount'      => $amount,
                'currency'    => $currency,
            ],
            'customer' => [
                'name'  => $customer_name,
                'email' => $order->get_billing_email(),
            ],
            'billing_address' => [
                'country' => $order->get_billing_country() ?: 'US',
                'state'   => $state,
                'city'    => $order->get_billing_city() ?: 'NA',
                'address' => $order->get_billing_address_1() ?: 'NA',
                'zip'     => $order->get_billing_postcode() ?: 'NA',
                'phone'   => $order->get_billing_phone() ?: 'NA',
            ],
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
            'hash'        => $hash,
            'methods'     => ['card'],
        ];

        $this->log("=== K-Processor ({$this->processor_type}) PAYMENT START === Order #{$order_id}");
        $this->log("Amount: {$amount} {$currency} | Checkout: {$checkout_url}");

        $endpoint = $checkout_url . '/api/v1/session';

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->log("API Error: " . $response->get_error_message());
            wc_add_notice('Payment service unavailable. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $result    = json_decode(wp_remote_retrieve_body($response), true);

        $this->log("Response HTTP {$http_code}: " . wp_json_encode($result));

        if ($http_code !== 200 || empty($result['redirect_url'])) {
            $error_msg = $result['error_message'] ?? 'Unable to create payment session';
            $this->log("=== K-Processor SESSION FAILED === {$error_msg}");
            wc_add_notice('Payment could not be initiated. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        // Store order meta
        $this->store_order_meta($order);
        $order->update_status('pending', sprintf('K-Processor %s: Redirecting to payment page.', strtoupper($this->processor_type)));
        $order->update_meta_data('_mps_kp_awaiting_callback', 'yes');
        $order->update_meta_data('_mps_kp_callback_status', 'waiting');
        $order->save();

        WC()->cart->empty_cart();

        $this->log("=== K-Processor REDIRECT === " . $result['redirect_url']);

        return [
            'result'   => 'success',
            'redirect' => $result['redirect_url'],
        ];
    }

    /**
     * Handle Payvelonix callback (async POST).
     */
    public function process_callback(array $params): void {
        $order_id = (int) ($params['order_number'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            MPS_Logger::error("KP Callback: Order not found for ID: {$order_id}", 'mps-kp');
            return;
        }

        // Verify hash
        $password = $this->credentials['merchant_password'] ?? '';
        $hash_string = ($params['id'] ?? '') . ($params['order_number'] ?? '') . ($params['order_amount'] ?? '')
                     . ($params['order_currency'] ?? '') . ($params['order_description'] ?? '') . $password;
        $expected_hash = sha1(md5(strtoupper($hash_string)));

        if (!hash_equals($expected_hash, $params['hash'] ?? '')) {
            MPS_Logger::error("KP Callback: Hash mismatch for order #{$order_id}", 'mps-kp');
            return;
        }

        $type   = $params['type'] ?? '';
        $status = $params['status'] ?? '';
        $tx_id  = $params['id'] ?? '';

        MPS_Logger::info("KP Callback: Order #{$order_id} | Type: {$type} | Status: {$status} | TX: {$tx_id}", 'mps-kp');

        // Handle sale callbacks
        if ($type === 'sale') {
            $this->process_sale_callback($params, $order);
        } elseif ($type === 'refund') {
            $this->process_refund_callback($params, $order);
        }
    }

    /**
     * Process sale callback from Payvelonix.
     */
    private function process_sale_callback(array $params, WC_Order $order): void {
        $status = $params['status'] ?? '';
        $tx_id  = $params['id'] ?? '';

        // Skip if already finalized
        if ($order->has_status(['processing', 'completed'])) {
            MPS_Logger::info("KP Callback: Order #{$order->get_id()} already finalized, skipping", 'mps-kp');
            return;
        }

        $order->set_transaction_id($tx_id);

        // Extract card info from callback
        $card_masked = $params['card'] ?? '';
        $last_four   = $card_masked ? substr($card_masked, -4) : '';
        $card_brand  = $this->detect_card_brand($card_masked);

        $order->update_meta_data('_mps_processor_tx_id', $tx_id);
        $order->update_meta_data('_mps_kp_transaction_id', $tx_id);

        if ($card_brand) $order->update_meta_data('_mps_card_brand', $card_brand);
        if ($last_four)  $order->update_meta_data('_mps_last_four', $last_four);

        $descriptor = $this->portal_descriptor;
        if ($descriptor) {
            $order->update_meta_data('_mps_descriptor', $descriptor);
        }

        if ($status === 'success') {
            $order->update_meta_data('_mps_kp_callback_status', 'approved');
            $order->save();

            $order->payment_complete($tx_id);
            $order->add_order_note(sprintf(
                'K-Processor %s: Payment approved. TX: %s | Card: %s ***%s',
                strtoupper($this->processor_type), $tx_id, ucfirst($card_brand), $last_four
            ));

            MPS_Logger::info("=== K-Processor PAYMENT SUCCESS === Order #{$order->get_id()} TX: {$tx_id}", 'mps-kp');

            MPS_Transaction_Reporter::report($order, [
                'status'          => 'approved',
                'processor_tx_id' => $tx_id,
                'card_brand'      => $card_brand,
                'last_four'       => $last_four,
                'is_3ds'          => ($this->processor_type === '3d'),
            ]);

        } elseif ($status === 'waiting') {
            $order->update_meta_data('_mps_kp_callback_status', 'waiting');
            $order->update_status('on-hold', 'K-Processor: Payment waiting for confirmation.');
            $order->save();

        } elseif ($status === 'fail') {
            $reason = $params['reason'] ?? 'Unknown';
            $order->update_meta_data('_mps_kp_callback_status', 'declined');
            $order->update_status('failed', sprintf('K-Processor: Payment failed — %s', $reason));
            $order->save();

            MPS_Logger::info("=== K-Processor PAYMENT FAILED === Order #{$order->get_id()} Reason: {$reason}", 'mps-kp');

            MPS_Transaction_Reporter::report($order, [
                'status'          => 'declined',
                'processor_tx_id' => $tx_id,
                'status_code'     => $status,
                'status_message'  => $reason,
                'card_brand'      => $card_brand,
                'last_four'       => $last_four,
            ]);
        }
    }

    /**
     * Process refund callback from Payvelonix.
     */
    private function process_refund_callback(array $params, WC_Order $order): void {
        $status = $params['status'] ?? '';
        $amount = $params['order_amount'] ?? '';

        if ($status === 'success') {
            $order->add_order_note(sprintf('K-Processor: Refund confirmed by processor. Amount: %s', $amount));
        } elseif ($status === 'fail') {
            $reason = $params['reason'] ?? 'Unknown';
            $order->add_order_note(sprintf('K-Processor: Refund failed — %s', $reason));
        } elseif ($status === 'waiting') {
            $order->add_order_note('K-Processor: Refund is waiting for confirmation.');
        }

        $order->save();
    }

    /**
     * Process refund via Payvelonix API.
     */
    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order = wc_get_order($order_id);
        $tx_id = $order->get_transaction_id();

        if (!$tx_id) {
            $tx_id = $order->get_meta('_mps_kp_transaction_id');
        }

        if (!$tx_id) {
            return new \WP_Error('no_tx', 'No transaction ID found for refund.');
        }

        $checkout_url = rtrim($this->credentials['checkout_url'] ?? '', '/');
        $merchant_key = $this->credentials['merchant_key'] ?? '';
        $password     = $this->credentials['merchant_password'] ?? '';

        $formatted_amount = number_format((float) $amount, 2, '.', '');

        // Refund hash: SHA1(MD5(UPPER(payment_id + amount + password)))
        $hash = sha1(md5(strtoupper($tx_id . $formatted_amount . $password)));

        $body = [
            'merchant_key' => $merchant_key,
            'payment_id'   => $tx_id,
            'amount'       => $formatted_amount,
            'hash'         => $hash,
        ];

        $endpoint = $checkout_url . '/api/v1/payment/refund';

        $this->log("=== K-Processor REFUND === Order #{$order_id} TX: {$tx_id} Amount: {$formatted_amount}");

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        $this->log("Refund response: " . wp_json_encode($result));

        if (($result['result'] ?? '') === 'accepted') {
            $order->add_order_note(sprintf(
                'K-Processor: Refund request accepted. Amount: %s %s. Awaiting confirmation callback.',
                $formatted_amount, $order->get_currency()
            ));
            return true;
        }

        $error = $result['error_message'] ?? 'Refund request failed';
        return new \WP_Error('refund_failed', $error);
    }

    /**
     * AJAX polling for pending K-Processor transactions.
     */
    public static function ajax_poll_status(): void {
        $order_id = (int) ($_GET['order_id'] ?? 0);
        $key      = sanitize_text_field($_GET['key'] ?? '');

        if (!$order_id) wp_send_json_error(['status' => 'error']);

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_send_json_error(['status' => 'error']);
        }

        // Clear cache to get fresh order data
        clean_post_cache($order_id);
        wp_cache_delete('order-' . $order_id, 'orders');
        wp_cache_delete($order_id, 'posts');
        $order = wc_get_order($order_id);

        $callback_status = $order->get_meta('_mps_kp_callback_status');

        if ($callback_status === 'approved' || $order->has_status(['processing', 'completed'])) {
            // Build a clean thank-you URL without polling params
            $gateway = MPS_Gateway_Factory::find($order->get_payment_method());
            $redirect = $gateway ? $gateway->get_return_url($order) : $order->get_checkout_order_received_url();
            wp_send_json_success(['status' => 'approved', 'redirect_url' => $redirect]);
        }

        if (in_array($callback_status, ['declined', 'error'], true) || $order->has_status('failed')) {
            wp_send_json_success(['status' => 'failed', 'redirect_url' => wc_get_checkout_url()]);
        }

        wp_send_json_success(['status' => 'waiting']);
    }

    /**
     * Detect card brand from masked card number (e.g. "411111****1111").
     * Overrides base class to handle masked format with asterisks.
     */
    protected function detect_card_brand(string $card_digits): string {
        if (empty($card_digits)) return '';

        $first_digit = $card_digits[0] ?? '';
        $first_two   = substr($card_digits, 0, 2);

        if ($first_digit === '4') return 'visa';
        if (in_array($first_two, ['51','52','53','54','55'])) return 'mastercard';
        if (in_array($first_two, ['34','37'])) return 'amex';
        if ($first_digit === '6') return 'discover';

        return parent::detect_card_brand($card_digits);
    }

    /**
     * Log helper.
     */
    protected function log(string $message): void {
        MPS_Logger::debug($message, 'mps-kp');
    }
}
