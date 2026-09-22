<?php
defined('ABSPATH') || exit;

/**
 * MPS D-Processor Gateway — the merchant's OWN NMI account (v2.9.0, 2026-09-22).
 *
 * The card fields are NMI Collect.js iframes: the number, expiry and CVV are typed into NMI's own
 * frames and never touch this store's server. Collect.js hands back a one-time payment_token, which
 * this class sends to the MPS portal (/api/v1/d/charge). The portal holds the merchant's PRIVATE
 * key and runs the sale — this store only ever has the PUBLIC tokenization key.
 *
 * The portal records the transaction itself, so nothing is reported separately from here. A second
 * submit of an order the portal already approved is answered from its record and never re-charged.
 */
class MPS_DProcessor extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->has_fields = true;
        $this->supports   = ['products', 'refunds'];
    }

    public function tokenization_key(): string {
        return (string) ($this->credentials['tokenization_key'] ?? '');
    }

    public function collect_js_url(): string {
        return (string) ($this->credentials['collect_js_url'] ?? 'https://secure.nmi.com/token/Collect.js');
    }

    /** Frontend config for the Collect.js glue (classic + Block). Public key only. */
    public function frontend_config(): array {
        return [
            'id'               => $this->id,
            'tokenization_key' => $this->tokenization_key(),
            'collect_js_url'   => $this->collect_js_url(),
            'allowed_cards'    => array_values($this->get_allowed_cards()),
            'blocked_bins'     => $this->blocked_bins,
            'blocked_message'  => class_exists('MPS_BIN_Blocker') ? MPS_BIN_Blocker::default_message() : '',
        ];
    }

    public function is_available() {
        // Without the public key there is no card form to show.
        if ($this->tokenization_key() === '') return false;
        return parent::is_available();
    }

    public function payment_fields(): void {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        if ($this->render_ticket_limit_notice()) {
            return;
        }
        $p = esc_attr($this->id);
        $decline = MPS_Decline_Codes::consume();
        ?>
        <div class="mps-card-form mps-d-form" id="<?php echo $p; ?>-form" data-mps-d="<?php echo $p; ?>">
            <div class="mps-field">
                <label for="<?php echo $p; ?>-ccnumber">Card Number</label>
                <div class="mps-d-slot" id="<?php echo $p; ?>-ccnumber"></div>
            </div>
            <div class="mps-row">
                <div class="mps-field">
                    <label for="<?php echo $p; ?>-ccexp">Expiry</label>
                    <div class="mps-d-slot" id="<?php echo $p; ?>-ccexp"></div>
                </div>
                <div class="mps-field">
                    <label for="<?php echo $p; ?>-cvv">CVC</label>
                    <div class="mps-d-slot" id="<?php echo $p; ?>-cvv"></div>
                </div>
            </div>
            <div class="mps-d-error mps-bin-blocked" role="alert" style="display:none"></div>
            <input type="hidden" name="<?php echo $p; ?>_payment_token" value="">
            <input type="hidden" name="<?php echo $p; ?>_card_last_four" value="">
            <input type="hidden" name="<?php echo $p; ?>_card_brand" value="">
            <input type="hidden" name="<?php echo $p; ?>_card_bin" value="">

            <?php if ($decline) : ?>
                <div class="mps-decline-notice <?php echo $decline['final'] ? 'mps-decline-final' : 'mps-decline-retry'; ?>" role="alert">
                    <?php echo esc_html($decline['message']); ?>
                </div>
            <?php endif; ?>

            <?php $this->render_charge_acknowledgment_field(); ?>

            <div class="mps-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </div>
        <?php
    }

    /** Card details are checked by Collect.js; here only what the token tells us about the card. */
    public function validate_fields(): bool {
        $total = $this->checkout_total();
        $limit = $total === null ? null : $this->ticket_limit_message($total);
        if ($limit) {
            wc_add_notice($limit, 'error');
            return false;
        }
        if ($this->post_field('payment_token') === '') {
            wc_add_notice(__('Please enter your card details.', 'mps-gateway'), 'error');
            return false;
        }
        $bin = preg_replace('/\D/', '', $this->post_field('card_bin'));
        if ($bin !== '') {
            // Collect.js gives 6–8 BIN digits; a longer rule simply does not match (never a false block).
            $blocked = MPS_BIN_Blocker::match($this->blocked_bins, $bin);
            if ($blocked) {
                MPS_BIN_Blocker::log($this->id, $blocked['bin']);
                wc_add_notice($blocked['message'], 'error');
                return false;
            }
        }
        $brand = strtolower($this->post_field('card_brand'));
        $allowed = $this->get_allowed_cards();
        if ($brand !== '' && $allowed && !in_array(self::brand_key($brand), $allowed, true)) {
            wc_add_notice(sprintf(__('This store does not accept %s cards. Please use a different card.', 'mps-gateway'), ucfirst($brand)), 'error');
            return false;
        }
        return true;
    }

    /** Collect.js card.type → our allowed_cards keys. */
    private static function brand_key(string $type): string {
        $t = strtolower($type);
        if ($t === 'mc' || str_contains($t, 'master')) return 'mastercard';
        if (str_contains($t, 'amex') || str_contains($t, 'american')) return 'amex';
        if (str_contains($t, 'disc')) return 'discover';
        if (str_contains($t, 'visa')) return 'visa';
        return $t;
    }

    protected function process_payment_inner($order_id): array {
        $order = wc_get_order($order_id);
        $token = $this->post_field('payment_token');
        if ($token === '') {
            throw new Exception(__('Please enter your card details.', 'mps-gateway'));
        }
        $last4 = substr(preg_replace('/\D/', '', $this->post_field('card_last_four')), -4);
        $brand = self::brand_key($this->post_field('card_brand'));
        $bin   = substr(preg_replace('/\D/', '', $this->post_field('card_bin')), 0, 8);

        // Stored first so the acknowledgment carries the right last four.
        $this->store_order_meta($order, [
            '_mps_card_brand' => $brand,
            '_mps_last_four'  => $last4,
            '_mps_descriptor' => $this->portal_descriptor,
        ]);

        $payload = [
            'gateway_id'    => $this->portal_gateway_id,
            'payment_token' => $token,
            'amount'        => number_format((float) $order->get_total(), 2, '.', ''),
            'currency'      => $order->get_currency() ?: 'USD',
            'order_ref'     => (string) $order_id,
            'email'         => $order->get_billing_email(),
            'first_name'    => $order->get_billing_first_name(),
            'last_name'     => $order->get_billing_last_name(),
            'phone'         => $order->get_billing_phone(),
            'address'       => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            'city'          => $order->get_billing_city(),
            'state'         => $order->get_billing_state(),
            'zip'           => $order->get_billing_postcode(),
            'country'       => $order->get_billing_country(),
            'card_brand'    => $brand,
            'last_four'     => $last4,
            'card_bin'      => $bin,
            'customer_ip'   => $order->get_customer_ip_address(),
            // Consent the customer gave at checkout; the portal keeps it only if the charge approves.
            'charge_acknowledgment' => MPS_Transaction_Reporter::charge_acknowledgment($order, ['status' => 'approved', 'last_four' => $last4]),
        ];

        $this->log("=== D PAYMENT START === Order #{$order_id} amount {$payload['amount']} {$payload['currency']} card {$brand} ****{$last4}");
        $result = MPS_Portal_Client::d_charge($payload);
        $this->log('D response: ' . wp_json_encode(array_diff_key($result, ['charge_acknowledgment' => 1])));

        $status = $result['status'] ?? 'error';
        $tx     = (string) ($result['processor_tx_id'] ?? '');

        if ($status === 'approved') {
            $this->store_order_meta($order, [
                '_mps_processor_tx_id'   => $tx,
                '_mps_portal_tx_id'      => (int) ($result['transaction_id'] ?? 0),
            ]);
            $order->payment_complete($tx);
            $order->add_order_note(sprintf('D-Processor payment approved%s. TX: %s | Card: %s ****%s',
                !empty($result['replay']) ? ' (already approved — not charged again)' : '', $tx, ucfirst($brand), $last4));
            if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        if ($status === 'declined') {
            $code = (string) ($result['status_code'] ?? '');
            $msg  = (string) ($result['status_message'] ?? '');
            MPS_Decline_Codes::remember($code);
            MPS_Decline_Codes::remember_for_order($order->get_id(), $code);
            $order->update_status('failed', sprintf('D-Processor declined: [%s] %s', $code, $msg));
            // NMI's codes are not on the V decline sheet, so this is the shared generic wording.
            throw new Exception(MPS_Decline_Codes::message($code));
        }

        // Error or no answer. Safe to retry: the portal never charges an order it already approved.
        $order->add_order_note('D-Processor: payment not completed — ' . ($result['status_message'] ?? $result['error'] ?? 'no response from the payment service'));
        throw new Exception(__('We could not complete your payment. Please try again in a moment.', 'mps-gateway'));
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        $order = wc_get_order($order_id);
        if (!$order) return new \WP_Error('no_order', 'Order not found.');
        $amount = $amount !== null ? (float) $amount : (float) $order->get_total();
        if ($amount <= 0) return new \WP_Error('bad_amount', 'Refund amount must be greater than zero.');

        $result = MPS_Portal_Client::d_refund([
            'gateway_id' => (int) ($order->get_meta('_mps_portal_gateway_id') ?: $this->portal_gateway_id),
            'order_ref'  => (string) $order_id,
            'amount'     => number_format($amount, 2, '.', ''),
            'reason'     => $reason,
        ]);
        $this->log("D refund order #{$order_id} {$amount}: " . wp_json_encode($result));

        if (empty($result['success'])) {
            return new \WP_Error('refund_failed', 'Refund not processed: ' . ($result['message'] ?? $result['error'] ?? 'no response from the payment service'));
        }
        $order->add_order_note(sprintf('D-Processor %s %s %s. %s',
            ($result['method'] ?? '') === 'void' ? 'voided' : 'refunded', number_format($amount, 2), $order->get_currency(), $result['message'] ?? ''));
        return true;
    }

    /** Block checkout: map the token fields into $_POST as well as the shared ones. */
    public function bridge_block_payment_data($context, &$result): void {
        parent::bridge_block_payment_data($context, $result);
        if ($context->payment_method !== $this->id) return;
        $pd = $context->payment_data ?? [];
        foreach (['payment_token', 'card_last_four', 'card_brand', 'card_bin'] as $k) {
            if (isset($pd[$k])) $_POST[$this->id . '_' . $k] = sanitize_text_field($pd[$k]);
        }
    }
}
