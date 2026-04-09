<?php
defined('ABSPATH') || exit;

class MPS_VProcessor_2D extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->supports[] = 'refunds';
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        $card  = $this->get_card_data();

        $merchant_id = (int) ($this->credentials['merchant_id'] ?? 0);
        $api_key     = $this->credentials['api_key'] ?? '';

        if (!$merchant_id || !$api_key) {
            wc_add_notice('Payment configuration error. Please contact the store.', 'error');
            return ['result' => 'fail'];
        }

        // Unique external reference per attempt
        $ext_ref = $order_id . '-' . substr(md5(wp_generate_password(12, false)), 0, 6);

        // Phone: digits and + only
        $phone = preg_replace('/[^\d+]/', '', $order->get_billing_phone());

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
                'address'   => [
                    'street'  => $order->get_billing_address_1(),
                    'city'    => $order->get_billing_city(),
                    'state'   => $order->get_billing_state() ?: 'NA',
                    'country' => $order->get_billing_country(),
                    'zipCode' => $order->get_billing_postcode(),
                ],
            ],
        ];

        $url = MPS_VProcessor_API::endpoint($this->environment, 'charges', '1');

        $this->log("=== VP2D PAYMENT START === Order #{$order_id}");
        $this->log("Endpoint: {$url}");
        $this->log("Amount: {$body['transactionDetails']['amount']} {$body['transactionDetails']['currency']}");
        $this->log("Card: ****" . substr($card['number'], -4) . " ({$this->detect_card_brand($card['number'])})");

        $response = MPS_VProcessor_API::post($url, $api_key, $body);

        if (is_wp_error($response)) {
            $this->log("API Error: " . $response->get_error_message());
            wc_add_notice('Payment service unavailable. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $code   = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        $this->log("Response Code: {$code}");
        $this->log("Response: " . wp_json_encode($result));

        $status        = strtolower($result['result']['status'] ?? 'error');
        $error_code    = $result['result']['errorCode'] ?? '';
        $error_detail  = $result['result']['errorDetail'] ?? '';
        $transaction_id = $result['transactionId'] ?? '';
        $card_brand    = $result['cardBrand'] ?? $this->detect_card_brand($card['number']);
        $last_four     = $result['lastFour'] ?? substr($card['number'], -4);

        $descriptor = $this->portal_descriptor ?: ($result['descriptor'] ?? '');

        // Store order meta
        $this->store_order_meta($order, [
            '_mps_vp2d_transaction_id' => $transaction_id,
            '_mps_vp2d_external_ref'   => $ext_ref,
            '_mps_processor_tx_id'     => $transaction_id,
            '_mps_card_brand'          => strtolower($card_brand),
            '_mps_last_four'           => $last_four,
            '_mps_descriptor'          => $descriptor,
        ]);

        if ($status === 'approved') {
            $this->log("=== VP2D PAYMENT SUCCESS === TX: {$transaction_id}");
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(
                'VP2D Payment approved. TX: %s | Card: %s ****%s',
                $transaction_id, ucfirst($card_brand), $last_four
            ));
            WC()->cart->empty_cart();

            $this->report_to_portal($order, 'approved', [
                'processor_tx_id' => $transaction_id,
                'card_brand' => strtolower($card_brand),
                'last_four' => $last_four,
            ]);

            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        // Payment failed
        $this->log("=== VP2D PAYMENT FAILED === Code: {$error_code} Detail: {$error_detail}");
        $friendly = MPS_VProcessor_API::friendly_error($error_code);
        $order->update_status('failed', sprintf('VP2D declined: [%s] %s', $error_code, $error_detail));

        $this->report_to_portal($order, 'declined', [
            'processor_tx_id' => $transaction_id,
            'status_code' => $error_code,
            'status_message' => $error_detail,
            'card_brand' => strtolower($card_brand),
            'last_four' => $last_four,
        ]);

        wc_add_notice($friendly, 'error');
        return ['result' => 'fail'];
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order = wc_get_order($order_id);
        $tx_id = $order->get_meta('_mps_vp2d_transaction_id');

        if (!$tx_id) {
            return new \WP_Error('no_tx', 'No transaction ID found for this order.');
        }

        $merchant_id = (int) ($this->credentials['merchant_id'] ?? 0);
        $api_key     = $this->credentials['api_key'] ?? '';

        $body = [
            'serviceSecurity' => [
                'merchantId' => $merchant_id,
            ],
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
            $order->add_order_note(sprintf('VP2D Refund approved: %s %s', $amount, $order->get_currency()));
            return true;
        }

        return new \WP_Error('refund_failed', $result['result']['errorDetail'] ?? 'Refund failed');
    }
}
