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
        $phone = preg_replace('/[^\d+]/', '', $order->get_billing_phone()) ?: '0000000000';

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

        $descriptor = $this->portal_descriptor;

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

        $site_url    = home_url();
        $site_name   = get_bloginfo('name');
        $card_brand  = ucfirst($order->get_meta('_mps_card_brand') ?: 'N/A');
        $last_four   = $order->get_meta('_mps_last_four') ?: 'N/A';
        $descriptor  = $order->get_meta('_mps_descriptor') ?: 'N/A';
        $merchant_id = $this->credentials['merchant_id'] ?? 'N/A';
        $order_date  = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s T') : 'N/A';

        $subject = sprintf('Refund Request — Order #%s — TX: %s', $order_id, $tx_id);

        $body = "REFUND REQUEST\n";
        $body .= str_repeat('─', 50) . "\n\n";
        $body .= "Transaction ID:    {$tx_id}\n";
        $body .= "vSafe Merchant ID: {$merchant_id}\n";
        $body .= "Descriptor:        {$descriptor}\n\n";
        $body .= "Order Number:      #{$order_id}\n";
        $body .= "Order Date:        {$order_date}\n";
        $body .= "Order Total:       {$order->get_total()} {$order->get_currency()}\n";
        $body .= "Refund Amount:     {$amount} {$order->get_currency()}\n";
        $body .= "Refund Reason:     " . ($reason ?: 'Not specified') . "\n\n";
        $body .= "Card:              {$card_brand} ****{$last_four}\n";
        $body .= "Customer Name:     " . trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . "\n";
        $body .= "Customer Email:    {$order->get_billing_email()}\n";
        $body .= "Customer Phone:    {$order->get_billing_phone()}\n";
        $body .= "Billing Address:   " . implode(', ', array_filter([
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_postcode(),
            $order->get_billing_country(),
        ])) . "\n\n";
        $body .= str_repeat('─', 50) . "\n";
        $body .= "Store:  {$site_name}\n";
        $body .= "URL:    {$site_url}\n";

        $to = 'ops@vsafe.tech';
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Cc: roberto@vsafe.tech',
        ];

        $sent = wp_mail($to, $subject, $body, $headers);

        if (!$sent) {
            $this->log("VP2D refund email FAILED for Order #{$order_id}, TX: {$tx_id}");
            return new \WP_Error('email_failed', 'Failed to send refund request email to processor. Please contact support.');
        }

        $this->log("VP2D refund email sent for Order #{$order_id}, TX: {$tx_id}, Amount: {$amount} {$order->get_currency()}");
        $order->add_order_note(sprintf(
            'VP2D Refund request emailed to vSafe ops team. TX: %s | Amount: %s %s | Awaiting processor confirmation.',
            $tx_id, $amount, $order->get_currency()
        ));

        return true;
    }
}
