<?php
defined('ABSPATH') || exit;

class MPS_VProcessor_3D extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->supports[] = 'refunds';

        // Register AJAX polling endpoint
        add_action('wp_ajax_mps_vp3d_poll_status', [$this, 'ajax_poll_status']);
        add_action('wp_ajax_nopriv_mps_vp3d_poll_status', [$this, 'ajax_poll_status']);
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        $card  = $this->get_card_data();

        $merchant_id = (int) ($this->credentials['merchant_id'] ?? 0);
        $api_key     = $this->credentials['api_key'] ?? $this->credentials['api_token'] ?? '';

        if (!$merchant_id || !$api_key) {
            wc_add_notice('Payment configuration error. Please contact the store.', 'error');
            return ['result' => 'fail'];
        }

        $ext_ref = $order_id . '-' . substr(md5(wp_generate_password(12, false)), 0, 6);
        $phone   = preg_replace('/[^\d+]/', '', $order->get_billing_phone()) ?: '0000000000';

        // Cardholder billing address from the card form
        $billing = $this->get_cardholder_billing();

        $body = [
            'serviceSecurity' => [
                'merchantId' => $merchant_id,
            ],
            'transactionDetails' => [
                'amount'            => number_format((float) $order->get_total(), 2, '.', ''),
                'currency'          => $order->get_currency(),
                'externalReference' => $ext_ref,
            ],
            'cardDetails' => [
                'cardHolderName'  => $card['name'],
                'cardNumber'      => $card['number'],
                'cvv'             => $card['cvv'],
                'expirationMonth' => sprintf('%02d', $card['exp_month']),
                'expirationYear'  => (int) $card['exp_year'],
            ],
            'payerDetails' => [
                'username'  => sanitize_user($order->get_billing_email(), true),
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'email'     => $order->get_billing_email(),
                'phone'     => $phone,
                'address'   => $billing,
            ],
        ];

        $url = MPS_VProcessor_API::endpoint($this->environment, 'charges', '2');

        $this->log("=== VP3D PAYMENT START === Order #{$order_id}");
        $this->log("Endpoint: {$url}");
        $this->log("Amount: {$body['transactionDetails']['amount']} {$body['transactionDetails']['currency']}");

        $response = MPS_VProcessor_API::post($url, $api_key, $body);

        if (is_wp_error($response)) {
            $this->log("API Error: " . $response->get_error_message());
            wc_add_notice('Payment service unavailable. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        $this->log("Response: " . wp_json_encode($result));

        $status         = strtolower($result['result']['status'] ?? 'error');
        $transaction_id = $result['transactionId'] ?? '';
        $card_brand     = $result['cardBrand'] ?? $this->detect_card_brand($card['number']);
        $last_four      = $result['lastFour'] ?? substr($card['number'], -4);
        $redirect_url   = $result['result']['redirectUrl'] ?? $result['redirectUrl'] ?? '';
        $descriptor     = $this->portal_descriptor;

        // Store order meta
        $this->store_order_meta($order, [
            '_mps_vp3d_transaction_id' => $transaction_id,
            '_mps_vp3d_external_ref'   => $ext_ref,
            '_mps_processor_tx_id'     => $transaction_id,
            '_mps_card_brand'          => strtolower($card_brand),
            '_mps_last_four'           => $last_four,
            '_mps_descriptor'          => $descriptor,
        ]);

        if ($status === 'approved') {
            $this->log("=== VP3D PAYMENT APPROVED (no 3DS) === TX: {$transaction_id}");
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf('VP3D Payment approved. TX: %s', $transaction_id));
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            $this->report_to_portal($order, 'approved', [
                'processor_tx_id' => $transaction_id,
                'card_brand' => strtolower($card_brand),
                'last_four' => $last_four,
            ]);

            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        if ($status === 'pending') {
            // Check for 3DS redirect URL
            if (!empty($redirect_url)) {
                $this->log("VP3D: 3DS redirect required → {$redirect_url}");
                $order->update_status('pending', 'Awaiting 3DS authentication.');
                $order->update_meta_data('_mps_vp3d_awaiting_webhook', 'yes');
                $order->update_meta_data('_mps_vp3d_webhook_status', 'waiting');
                $order->save();
                WC()->cart->empty_cart();
                return ['result' => 'success', 'redirect' => $redirect_url];
            }

            // Pending without redirect — webhook may have arrived during the HTTP call
            clean_post_cache($order_id);
            wp_cache_delete('order-' . $order_id, 'orders');
            wp_cache_delete($order_id, 'posts');
            $fresh = wc_get_order($order_id);

            $existing_redirect = $fresh ? $fresh->get_meta('_mps_vp3d_3ds_redirect_url') : '';
            if (!empty($existing_redirect)) {
                $this->log("VP3D: Webhook already delivered redirect URL during charge call");
                WC()->cart->empty_cart();
                return ['result' => 'success', 'redirect' => $existing_redirect];
            }

            $existing_status = $fresh ? $fresh->get_meta('_mps_vp3d_webhook_status') : '';
            if ($existing_status === 'approved' || ($fresh && $fresh->has_status(['processing', 'completed']))) {
                $this->log("VP3D: Webhook already approved during charge call");
                WC()->cart->empty_cart();
                return ['result' => 'success', 'redirect' => $this->get_return_url($fresh ?: $order)];
            }

            $this->log("VP3D: Pending without redirect, setting up polling");
            $order->update_status('pending', 'VP3D: Awaiting processor response.');
            $order->update_meta_data('_mps_vp3d_awaiting_webhook', 'yes');
            $order->update_meta_data('_mps_vp3d_webhook_status', 'waiting');
            $order->save();
            WC()->cart->empty_cart();

            $polling_url = add_query_arg([
                'mps_vp3d_poll' => '1',
                'order_id'      => $order_id,
                'key'           => $order->get_order_key(),
            ], $this->get_return_url($order));

            return ['result' => 'success', 'redirect' => $polling_url];
        }

        // Failed
        $error_code   = $result['result']['errorCode'] ?? '';
        $error_detail = $result['result']['errorDetail'] ?? '';
        $this->log("=== VP3D PAYMENT FAILED === Code: {$error_code} Detail: {$error_detail}");

        $friendly = MPS_VProcessor_API::friendly_error($error_code);
        $order->update_status('failed', sprintf('VP3D declined: [%s] %s', $error_code, $error_detail));

        $this->report_to_portal($order, 'declined', [
            'processor_tx_id' => $transaction_id,
            'status_code' => $error_code,
            'status_message' => $error_detail,
        ]);

        wc_add_notice($friendly, 'error');
        return ['result' => 'fail'];
    }

    /**
     * AJAX polling endpoint for 3DS pending status.
     */
    public function ajax_poll_status(): void {
        $order_id = (int) ($_GET['order_id'] ?? 0);
        $key      = sanitize_text_field($_GET['key'] ?? '');

        if (!$order_id) {
            wp_send_json_error(['status' => 'error']);
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $key) {
            wp_send_json_error(['status' => 'error']);
        }

        // Clear cache for fresh data
        clean_post_cache($order_id);
        wp_cache_delete('order-' . $order_id, 'orders');
        wp_cache_delete($order_id, 'posts');
        $order = wc_get_order($order_id);

        if ($order->has_status(['processing', 'completed'])) {
            wp_send_json_success([
                'status'       => 'approved',
                'redirect_url' => $this->get_return_url($order),
            ]);
        }

        $webhook_status = $order->get_meta('_mps_vp3d_webhook_status');

        if ($webhook_status === 'approved') {
            wp_send_json_success([
                'status'       => 'approved',
                'redirect_url' => $this->get_return_url($order),
            ]);
        }

        if ($webhook_status === 'redirect_3ds') {
            $redirect = $order->get_meta('_mps_vp3d_3ds_redirect_url');
            wp_send_json_success([
                'status'       => 'redirect_3ds',
                'redirect_url' => $redirect,
            ]);
        }

        if (in_array($webhook_status, ['declined', 'error'], true) || $order->has_status('failed')) {
            wp_send_json_success([
                'status'       => 'failed',
                'redirect_url' => wc_get_checkout_url(),
            ]);
        }

        wp_send_json_success(['status' => 'waiting']);
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order = wc_get_order($order_id);
        $tx_id = $order->get_meta('_mps_vp3d_transaction_id');

        if (!$tx_id) {
            return new \WP_Error('no_tx', 'No transaction ID found.');
        }

        $merchant_id = (int) ($this->credentials['merchant_id'] ?? 0);
        $api_key     = $this->credentials['api_key'] ?? $this->credentials['api_token'] ?? '';

        $body = [
            'serviceSecurity' => ['merchantId' => $merchant_id],
            'transactionDetails' => [
                'amount'        => (float) $amount,
                'currency'      => $order->get_currency(),
                'transactionId' => $tx_id,
                'commentaries'  => $reason ?: 'Refund from WooCommerce',
            ],
        ];

        $url = MPS_VProcessor_API::endpoint($this->environment, 'refunds', '1');
        $response = MPS_VProcessor_API::post($url, $api_key, $body);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        $status = strtolower($result['result']['status'] ?? 'error');

        if ($status === 'approved') {
            $order->add_order_note(sprintf('VP3D Refund approved: %s %s', $amount, $order->get_currency()));
            return true;
        }

        return new \WP_Error('refund_failed', $result['result']['errorDetail'] ?? 'Refund failed');
    }
}
