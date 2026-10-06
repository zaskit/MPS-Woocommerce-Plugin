<?php
defined('ABSPATH') || exit;

/**
 * MPS D-Processor Gateway — the merchant's OWN NMI account (v2.9.0, 2026-09-22).
 *
 * The card fields are NMI's Payment Component (Collect.js until 2026-09-30; bundled in
 * assets/vendor/nmi-pay): the number, expiry and CVV are typed into NMI's own iframes and never touch
 * this store's server. The component hands back a one-time payment_token, which this class sends to
 * the MPS portal (/api/v1/d/charge). The portal holds the merchant's PRIVATE key and runs the sale
 * through NMI's REST API — this store only ever has the PUBLIC tokenization key.
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

    /** 'sale' (charge now) or 'auth' (authorize, capture later) — set per merchant in the portal. */
    public function capture_mode(): string {
        return ($this->credentials['capture_mode'] ?? 'sale') === 'auth' ? 'auth' : 'sale';
    }

    public function capture_on_status(): bool {
        return !empty($this->credentials['capture_on_status']);
    }

    public function sends_line_items(): bool {
        return !empty($this->credentials['line_items']);
    }

    /** "Item x qty" per line, like the LifeSci NMI plugin. */
    private static function line_items(WC_Order $order): array {
        $out = [];
        foreach ($order->get_items() as $item) {
            $out[] = mb_substr($item->get_name(), 0, 150) . ' x ' . $item->get_quantity();
        }
        return $out;
    }

    /** NMI Payment Component, pinned + bundled (version and SHA-256 in assets/vendor/nmi-pay/README.txt). */
    const NMI_PAY_VERSION = '1.0.2';

    /** Transport marker in a checkout error: the token went to a charge attempt and is spent. */
    const RETYPE_MARKER = '<span class="mps-d-retype"></span>';

    /**
     * Registers the component bundle and our glue once (classic checkout, pay-for-order and the Block
     * checkout all use the same handles). Served from this plugin — no third-party script URL.
     */
    public static function register_scripts(): void {
        if (wp_script_is('mps-dprocessor', 'registered')) return;
        wp_register_script('mps-nmi-pay', plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/vendor/nmi-pay/nmi-payments-' . self::NMI_PAY_VERSION . '.iife.js',
            [], self::NMI_PAY_VERSION, true);
        wp_register_script('mps-dprocessor', plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/js/mps-dprocessor.js',
            ['mps-nmi-pay', 'jquery'], MPS_PLUGIN_VERSION, true);
    }

    /** Frontend config for the Payment Component glue (classic + Block). Public key only. */
    public function frontend_config(): array {
        return [
            'id'               => $this->id,
            'tokenization_key' => $this->tokenization_key(),
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
            <?php // NMI's Payment Component draws its own labelled card number / expiry / CVC fields here. ?>
            <div class="mps-d-component" id="<?php echo $p; ?>-card"></div>
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

    /** Card details are checked by NMI's component; here only what its lookup told us about the card. */
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
            // NMI's lookup gives a 6-digit BIN; a longer rule simply does not match (never a false block).
            // If NMI's lookup call failed there is no BIN: the rule is skipped (fails open, never a false block).
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

    /** NMI card type (component lookupData.card.type, e.g. "visa", "mastercard") → our allowed_cards keys. */
    private static function brand_key(string $type): string {
        $t = strtolower($type);
        if ($t === 'mc' || str_contains($t, 'master')) return 'mastercard';
        if (str_contains($t, 'amex') || str_contains($t, 'american')) return 'amex';
        if (str_contains($t, 'disc')) return 'discover';
        if (str_contains($t, 'visa')) return 'visa';
        if (str_contains($t, 'diner')) return 'diners';
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
            'address'       => $order->get_billing_address_1(),
            'address2'      => $order->get_billing_address_2(),
            'company'       => $order->get_billing_company(),
            'description'   => sprintf('%s - Order %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $order->get_order_number()),
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
        if ($this->sends_line_items()) {
            $payload['line_items'] = self::line_items($order);
        }

        $this->log("=== D PAYMENT START === Order #{$order_id} amount {$payload['amount']} {$payload['currency']} card {$brand} ****{$last4}");
        $result = MPS_Portal_Client::d_charge($payload);
        $this->log('D response: ' . wp_json_encode(array_diff_key($result, ['charge_acknowledgment' => 1])));

        $status = $result['status'] ?? 'error';
        $tx     = (string) ($result['processor_tx_id'] ?? '');

        // Authorize-only: the card is held, not charged. On hold until the order is processed
        // (capture) or cancelled (void). WooCommerce reduces stock when an order goes on hold.
        if (!empty($result['success']) && !empty($result['authorized_only'])) {
            $this->store_order_meta($order, [
                '_mps_processor_tx_id' => $tx,
                '_mps_portal_tx_id'    => (int) ($result['transaction_id'] ?? 0),
                '_mps_d_authorized'    => 'yes',
            ]);
            $order->set_transaction_id($tx);
            $order->update_status('on-hold', sprintf('D-Processor: card AUTHORIZED, not charged yet%s. TX: %s | Card: %s ****%s. %s',
                !empty($result['replay']) ? ' (already authorized — not authorized again)' : '', $tx, ucfirst($brand), $last4,
                $this->capture_on_status() ? 'Move the order to Processing or Completed to capture, or Cancel to release the hold.' : 'Capture it in the payment portal.'));
            if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

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
            throw new Exception(MPS_Decline_Codes::message($code) . self::retype_marker());
        }

        // Error or no answer. Safe to retry: the portal never charges an order it already approved.
        $order->add_order_note('D-Processor: payment not completed — ' . ($result['status_message'] ?? $result['error'] ?? 'no response from the payment service'));
        throw new Exception(__('We could not complete your payment. Please try again in a moment.', 'mps-gateway') . self::retype_marker());
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

    /**
     * Order status hooks (registered once in mps-gateway.php): On hold → Processing/Completed captures an
     * authorization, Cancelled voids it. Only for this gateway's authorize-only orders, and only when the
     * merchant's portal setting says so.
     */
    public static function on_status_change($order_id, $from, $to, $order): void {
        if (!$order instanceof WC_Order || $order->get_meta('_mps_d_authorized') !== 'yes') return;
        $gateway = MPS_Gateway_Factory::find($order->get_payment_method());
        if (!$gateway instanceof self || !$gateway->capture_on_status()) return;

        $payload = [
            'gateway_id' => (int) ($order->get_meta('_mps_portal_gateway_id') ?: $gateway->portal_gateway_id),
            'order_ref'  => (string) $order->get_id(),
        ];
        if ($from === 'on-hold' && in_array($to, ['processing', 'completed'], true)) {
            $payload['amount'] = number_format((float) $order->get_total(), 2, '.', '');
            $r = MPS_Portal_Client::d_capture($payload);
            $gateway->log("D capture order #{$order_id}: " . wp_json_encode($r));
            if (!empty($r['success'])) {
                $order->update_meta_data('_mps_d_authorized', 'captured');
                $order->save_meta_data();
                $order->add_order_note(sprintf('D-Processor: captured %s %s.', number_format((float) ($r['amount'] ?? $payload['amount']), 2), $order->get_currency()));
                if (!$order->get_date_paid()) { $order->set_date_paid(time()); $order->save(); }
                if (function_exists('mps_send_billing_notice')) mps_send_billing_notice($order_id);
            } else {
                // Put it back so nobody ships an order whose money was not taken.
                $order->update_status('on-hold', 'D-Processor: CAPTURE FAILED — ' . ($r['message'] ?? $r['error'] ?? 'no response from the payment service') . '. Order put back on hold.');
            }
        } elseif ($to === 'cancelled' && $from === 'on-hold') {
            $r = MPS_Portal_Client::d_void($payload);
            $gateway->log("D void order #{$order_id}: " . wp_json_encode($r));
            if (!empty($r['success'])) {
                $order->update_meta_data('_mps_d_authorized', 'voided');
                $order->save_meta_data();
                $order->add_order_note('D-Processor: authorization voided — the hold on the card is released.');
            } else {
                $order->add_order_note('D-Processor: VOID FAILED — ' . ($r['message'] ?? $r['error'] ?? 'no response from the payment service') . '. The card hold may still be in place; void it in the payment portal.');
            }
        }
    }

    /**
     * The token went to the portal, so it is spent: tells mps-dprocessor.js (classic checkout) to clear
     * the card fields for a new token. Empty span, invisible. Not on the Store API (Block checkout):
     * its notices are plain text there, and mps-d-blocks.js resets on any failed checkout instead.
     */
    private static function retype_marker(): string {
        return (defined('REST_REQUEST') && REST_REQUEST) ? '' : self::RETYPE_MARKER;
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
