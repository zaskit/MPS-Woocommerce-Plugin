<?php
defined('ABSPATH') || exit;

class MPS_EProcessor_2D extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->supports[] = 'refunds';

        // AJAX polling
        add_action('wp_ajax_mps_ep2d_poll_status', [$this, 'ajax_poll_status']);
        add_action('wp_ajax_nopriv_mps_ep2d_poll_status', [$this, 'ajax_poll_status']);
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        $card  = $this->get_card_data();

        $account_id  = $this->credentials['account_id'] ?? '';
        $password    = $this->credentials['account_password'] ?? '';
        $passphrase  = $this->credentials['account_passphrase'] ?? '';
        $gateway_num = $this->credentials['account_gateway'] ?? '1';

        if (!$account_id || !$password || !$passphrase) {
            wc_add_notice('Payment configuration error. Please contact the store.', 'error');
            return ['result' => 'fail'];
        }

        // Idempotent payment ID
        $attempt    = (int) $order->get_meta('_mps_ep_attempt') + 1;
        $payment_id = 'MPS-' . $order_id . '-' . $attempt;
        $order->update_meta_data('_mps_ep_attempt', $attempt);
        $order->update_meta_data('_mps_ep_merchant_payment_id', $payment_id);
        $order->save();

        $amount = number_format((float) $order->get_total(), 2, '.', '');
        $email  = $order->get_billing_email();
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $phone = preg_replace('/[^\d+]/', '', $order->get_billing_phone()) ?: '0000000000';
        $state = strtoupper(substr($order->get_billing_state() ?: 'NA', 0, 2));
        if (strlen($state) < 2) $state = 'NA';

        // SHA with card
        $sha = MPS_EProcessor_API::sha_with_card($passphrase, $amount, $account_id, $email, $card['number'], $ip);

        // Build product description
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $product_name = implode(', ', $items) ?: 'Order #' . $order->get_order_number();

        // Callback and return URLs (on merchant site)
        $callback_url = home_url('mps-eupaymentz-callback');
        $return_url   = home_url('mps-eupaymentz-return') . '?order_id=' . $order_id;

        $exp_year = $card['exp_year'];
        if (strlen($exp_year) === 2) $exp_year = '20' . $exp_year;

        $data = [
            'account_id'                => $account_id,
            'account_password'          => $password,
            'action_type'               => 'payment',
            'account_gateway'           => $gateway_num,
            'merchant_payment_id'       => $payment_id,
            'account_sha'               => $sha,
            'cust_email'                => $email,
            'cust_billing_last_name'    => $order->get_billing_last_name(),
            'cust_billing_first_name'   => $order->get_billing_first_name(),
            'cust_billing_address'      => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
            'cust_billing_city'         => $order->get_billing_city(),
            'cust_billing_zipcode'      => $order->get_billing_postcode(),
            'cust_billing_state'        => $state,
            'cust_billing_country'      => $order->get_billing_country() ?: 'US',
            'cust_billing_phone'        => $phone,
            'transac_products_name'     => substr($product_name, 0, 200),
            'transac_amount'            => $amount,
            'transac_currency_code'     => $order->get_currency() ?: 'USD',
            'customer_ip'               => $ip,
            'merchant_url_return'       => $return_url,
            'merchant_url_callback'     => $callback_url,
            'merchant_data1'            => (string) $order_id,
            'merchant_data2'            => substr($order->get_order_key(), 0, 20),
            'option'                    => '',
            'transac_cc_number'         => $card['number'],
            'transac_cc_month'          => str_pad($card['exp_month'], 2, '0', STR_PAD_LEFT),
            'transac_cc_year'           => $exp_year,
            'transac_cc_cvc'            => $card['cvv'],
        ];

        $this->log("=== EP2D PAYMENT START === Order #{$order_id}");
        $this->log("Amount: {$amount} {$data['transac_currency_code']}");
        $this->log("Card: ****" . substr($card['number'], -4));

        $response = MPS_EProcessor_API::post(MPS_EProcessor_API::PROCESS_URL, $data);

        if (is_wp_error($response)) {
            $this->log("API Error: " . $response->get_error_message());
            wc_add_notice('Payment service unavailable. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $result = MPS_EProcessor_API::parse_response($response);
        $this->log("Response: " . wp_json_encode($result));

        if (!$result) {
            $this->log("Invalid response from processor");
            wc_add_notice('Payment could not be processed. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $card_brand = $this->detect_card_brand($card['number']);
        $last_four  = substr($card['number'], -4);

        $this->store_order_meta($order, [
            '_mps_card_brand' => $card_brand,
            '_mps_last_four'  => $last_four,
        ]);

        // Check for 3DS redirect (shouldn't happen for 2D but handle gracefully)
        if (isset($result['isDirectResult']) && $result['isDirectResult'] === false) {
            $redirect_url = MPS_EProcessor_API::build_redirect_url($result);
            if ($redirect_url) {
                $this->log("EP2D: Redirect required → {$redirect_url}");
                $order->update_status('pending', 'EP2D: Redirecting for additional verification.');
                $order->update_meta_data('_mps_ep_transaction_id', $result['resp_trans_id'] ?? '');
                $order->update_meta_data('_mps_processor_tx_id', $result['resp_trans_id'] ?? '');
                $order->update_meta_data('_mps_ep2d_awaiting_callback', 'yes');
                $order->update_meta_data('_mps_ep2d_callback_status', 'waiting');
                $order->save();
                WC()->cart->empty_cart();
                return ['result' => 'success', 'redirect' => $redirect_url];
            }
        }

        // Direct result
        if (isset($result['resp_trans_status'])) {
            // Verify SHA
            if (!MPS_EProcessor_API::verify_response_sha($passphrase, $result)) {
                $this->log("EP2D: SHA verification failed!");
                wc_add_notice('Payment verification failed. Please try again.', 'error');
                return ['result' => 'fail'];
            }

            $parsed = MPS_EProcessor_API::parse_transaction_status($result);
            $tx_id  = $parsed['transaction_id'];

            $order->update_meta_data('_mps_ep_transaction_id', $tx_id);
            $order->update_meta_data('_mps_processor_tx_id', $tx_id);
            $order->update_meta_data('_mps_ep_status', $parsed['status']);
            $descriptor = $this->portal_descriptor;
            if ($descriptor) {
                $order->update_meta_data('_mps_descriptor', $descriptor);
            }
            $order->save();

            if ($parsed['is_success']) {
                $this->log("=== EP2D PAYMENT SUCCESS === TX: {$tx_id}");
                $order->payment_complete($tx_id);
                $order->add_order_note(sprintf('EP2D Payment approved. TX: %s', $tx_id));
                WC()->cart->empty_cart();

                $this->report_to_portal($order, 'approved', [
                    'processor_tx_id' => $tx_id,
                    'card_brand' => $card_brand,
                    'last_four' => $last_four,
                ]);

                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }

            if ($parsed['is_pending']) {
                $this->log("EP2D: Payment pending, awaiting callback");
                $order->update_status('on-hold', 'EP2D: Payment pending. Awaiting confirmation.');
                $order->update_meta_data('_mps_ep2d_awaiting_callback', 'yes');
                $order->update_meta_data('_mps_ep2d_callback_status', 'waiting');
                $order->save();
                WC()->cart->empty_cart();

                $polling_url = add_query_arg([
                    'mps_ep2d_poll' => '1',
                    'order_id'      => $order_id,
                    'key'           => $order->get_order_key(),
                ], $this->get_return_url($order));

                return ['result' => 'success', 'redirect' => $polling_url];
            }

            // Failed
            $this->log("=== EP2D PAYMENT FAILED === Status: {$parsed['status']} — {$parsed['description']}");
            $order->update_status('failed', sprintf('EP2D declined: [%s] %s', $parsed['status'], $parsed['description']));

            $this->report_to_portal($order, 'declined', [
                'processor_tx_id' => $tx_id,
                'status_code' => $parsed['status'],
                'status_message' => $parsed['description'],
            ]);

            wc_add_notice('Payment declined: ' . $parsed['description'], 'error');
            return ['result' => 'fail'];
        }

        // Unexpected response
        $this->log("EP2D: Unexpected response format");
        wc_add_notice('Payment could not be processed. Please try again.', 'error');
        return ['result' => 'fail'];
    }

    /**
     * Handle EuPaymentz callback (async POST from processor).
     */
    public function process_callback(array $data): void {
        $order_id = (int) ($data['resp_merchant_data1'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            MPS_Logger::error("EP2D Callback: Order not found for ID: {$order_id}", 'mps-ep2d');
            return;
        }

        // Verify order key
        $expected_key = substr($order->get_order_key(), 0, 20);
        $received_key = substr($data['resp_merchant_data2'] ?? '', 0, 20);
        if ($expected_key !== $received_key) {
            MPS_Logger::error("EP2D Callback: Order key mismatch for #{$order_id}", 'mps-ep2d');
            return;
        }

        // Skip if already completed
        if ($order->has_status(['processing', 'completed'])) return;

        // Get passphrase from gateway credentials
        $passphrase = $this->credentials['account_passphrase'] ?? '';

        // Verify SHA
        if (!MPS_EProcessor_API::verify_response_sha($passphrase, $data)) {
            MPS_Logger::error("EP2D Callback: SHA verification failed for #{$order_id}", 'mps-ep2d');
            return;
        }

        $parsed = MPS_EProcessor_API::parse_transaction_status($data);

        if ($parsed['is_success']) {
            // Race condition: re-fetch order
            clean_post_cache($order_id);
            wp_cache_delete('order-' . $order_id, 'orders');
            wp_cache_delete($order_id, 'posts');
            $fresh = wc_get_order($order_id);
            if ($fresh && $fresh->has_status(['processing', 'completed'])) return;

            $order->payment_complete($parsed['transaction_id']);
            $order->add_order_note('EP2D Callback: approved. TX: ' . $parsed['transaction_id']);
            $order->update_meta_data('_mps_ep2d_callback_status', 'approved');
            $descriptor = $this->portal_descriptor;
            if ($descriptor) {
                $order->update_meta_data('_mps_descriptor', $descriptor);
            }
            $order->save();

            MPS_Transaction_Reporter::report($order, [
                'status' => 'approved',
                'processor_tx_id' => $parsed['transaction_id'],
                'is_3ds' => false,
            ]);
        } elseif ($parsed['is_pending']) {
            $order->update_status('on-hold', 'EP2D Callback: pending.');
            $order->update_meta_data('_mps_ep2d_callback_status', 'waiting');
            $order->save();
        } else {
            $order->update_status('failed', 'EP2D Callback: ' . $parsed['description']);
            $order->update_meta_data('_mps_ep2d_callback_status', 'declined');
            $order->save();

            MPS_Transaction_Reporter::report($order, [
                'status' => 'declined',
                'processor_tx_id' => $parsed['transaction_id'] ?? '',
                'status_code' => $parsed['status'] ?? '',
                'status_message' => $parsed['description'] ?? '',
                'is_3ds' => false,
            ]);
        }
    }

    /**
     * Handle EuPaymentz return (customer redirected back).
     */
    public function process_return(array $data): void {
        $order_id = (int) ($data['order_id'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Already completed
        if ($order->has_status(['processing', 'completed'])) {
            wp_redirect($this->get_return_url($order));
            exit;
        }

        // Process response data if present
        if (isset($data['resp_trans_status']) && !$order->has_status(['processing', 'completed'])) {
            $passphrase = $this->credentials['account_passphrase'] ?? '';
            if (MPS_EProcessor_API::verify_response_sha($passphrase, $data)) {
                $parsed = MPS_EProcessor_API::parse_transaction_status($data);
                if ($parsed['is_success']) {
                    $order->payment_complete($parsed['transaction_id']);
                    $order->add_order_note('EP2D Return: approved. TX: ' . $parsed['transaction_id']);
            $descriptor = $this->portal_descriptor;
                    if ($descriptor) {
                        $order->update_meta_data('_mps_descriptor', $descriptor);
                        $order->save();
                    }
                    MPS_Transaction_Reporter::report($order, [
                        'status' => 'approved',
                        'processor_tx_id' => $parsed['transaction_id'],
                        'is_3ds' => false,
                    ]);
                } elseif ($parsed['is_pending']) {
                    $order->update_status('on-hold', 'EP2D: Pending confirmation.');
                } else {
                    $order->update_status('failed', 'EP2D Return: ' . $parsed['description']);
                    MPS_Transaction_Reporter::report($order, [
                        'status' => 'declined',
                        'processor_tx_id' => $parsed['transaction_id'] ?? '',
                        'status_code' => $parsed['status'] ?? '',
                        'status_message' => $parsed['description'] ?? '',
                        'is_3ds' => false,
                    ]);
                }
            }
        }

        // Redirect based on final status
        if ($order->has_status(['processing', 'completed', 'on-hold'])) {
            wp_redirect($this->get_return_url($order));
        } else {
            wc_add_notice('Payment was not completed. Please try again.', 'error');
            wp_redirect(wc_get_checkout_url());
        }
        exit;
    }

    /**
     * AJAX polling for pending EP2D transactions.
     */
    public function ajax_poll_status(): void {
        $order_id = (int) ($_GET['order_id'] ?? 0);
        $key      = sanitize_text_field($_GET['key'] ?? '');

        if (!$order_id) wp_send_json_error(['status' => 'error']);

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_send_json_error(['status' => 'error']);
        }

        clean_post_cache($order_id);
        wp_cache_delete('order-' . $order_id, 'orders');
        $order = wc_get_order($order_id);

        $callback_status = $order->get_meta('_mps_ep2d_callback_status');

        if ($callback_status === 'approved' || $order->has_status(['processing', 'completed'])) {
            wp_send_json_success(['status' => 'approved', 'redirect_url' => $this->get_return_url($order)]);
        }

        if (in_array($callback_status, ['declined', 'error'], true) || $order->has_status('failed')) {
            wp_send_json_success(['status' => 'failed', 'redirect_url' => wc_get_checkout_url()]);
        }

        wp_send_json_success(['status' => 'waiting']);
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order = wc_get_order($order_id);
        $tx_id = $order->get_meta('_mps_ep_transaction_id');

        if (!$tx_id) {
            return new \WP_Error('no_tx', 'No transaction ID found.');
        }

        $account_id = $this->credentials['account_id'] ?? '';
        $password   = $this->credentials['account_password'] ?? '';
        $passphrase = $this->credentials['account_passphrase'] ?? '';

        $sha = MPS_EProcessor_API::sha_refund($passphrase, $account_id, $tx_id);

        $data = [
            'account_id'       => $account_id,
            'account_password' => $password,
            'account_sha'      => $sha,
            'trans_id'         => $tx_id,
            'option'           => '',
        ];

        $response = MPS_EProcessor_API::post(MPS_EProcessor_API::REFUND_URL, $data);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $result = MPS_EProcessor_API::parse_response($response);
        if ($result && ($result['resp_trans_status'] ?? '') === '00000') {
            $order->add_order_note(sprintf('EP2D Refund approved: %s %s', $amount, $order->get_currency()));
            return true;
        }

        return new \WP_Error('refund_failed', $result['resp_trans_description_status'] ?? 'Refund failed');
    }
}
