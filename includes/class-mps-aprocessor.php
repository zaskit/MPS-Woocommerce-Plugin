<?php
defined('ABSPATH') || exit;

/**
 * MPS A-Processor Gateway (Authorize.Net, cloak-website model).
 *
 * Redirect-based. The customer never enters card details on this store — the
 * plugin asks the MPS portal to open a checkout session, then redirects the
 * customer to the merchant's cloak page (pay.html) where Authorize.Net Accept.js
 * tokenizes the card. MPS charges server-side and redirects the customer back to
 * this store's thank-you URL. On return the plugin CONFIRMS the outcome
 * authoritatively with the portal (never trusts the query string) before
 * completing the order.
 *
 * No card fields, no callback hash — the charge is synchronous inside MPS, so the
 * return-confirmation is immediate. See memory mpsgateway-a-processor-authnet-cloak.
 */
class MPS_AProcessor extends MPS_Base_Gateway {

    public function __construct(array $gateway_config) {
        parent::__construct($gateway_config);
        $this->has_fields = false; // redirect to cloak — no card form here
    }

    /** A-Processor accepts all major cards via the cloak page — keep the label generic. */
    public function build_default_title(): string {
        return 'Pay securely with Credit/Debit Card';
    }

    public function build_default_description(): string {
        return 'You will be redirected to a secure page to complete your card payment.';
    }

    /** Description only (no card form). */
    public function payment_fields(): void {
        if ($this->description) {
            echo '<div class="mps-hosted-notice" style="padding:12px 0;">' . wpautop(wp_kses_post($this->description)) . '</div>';
        }
    }

    /** Nothing to validate — card is entered on the cloak page. */
    public function validate_fields(): bool {
        return true;
    }

    /**
     * Create the checkout session on the portal, then redirect to the cloak pay page.
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        // Where MPS sends the customer after the charge. We confirm the result here.
        $return_url = add_query_arg([
            'mps_a_return' => '1',
            'order_id'     => $order_id,
            'key'          => $order->get_order_key(),
        ], $this->get_return_url($order));

        $payload = [
            'amount'     => number_format((float) $order->get_total(), 2, '.', ''),
            'currency'   => $order->get_currency() ?: 'USD',
            'order_ref'  => (string) $order_id,
            'return_url' => $return_url,
            'cancel_url' => $order->get_cancel_order_url_raw(),
            'email'      => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
        ];

        $this->log("=== A-Processor PAYMENT START === Order #{$order_id} amount {$payload['amount']} {$payload['currency']}");

        $result = MPS_Portal_Client::create_a_session($payload);

        if (empty($result['success']) || empty($result['pay_url'])) {
            $err = $result['error'] ?? 'Unable to start the secure payment session.';
            $this->log("=== A-Processor SESSION FAILED === {$err}");
            wc_add_notice('Payment could not be initiated. Please try again.', 'error');
            return ['result' => 'fail'];
        }

        $this->store_order_meta($order, [
            '_mps_a_awaiting_return' => 'yes',
            '_mps_a_session_token'   => $result['token'] ?? '',
            '_mps_descriptor'        => $this->portal_descriptor,
        ]);
        $order->update_status('pending', 'A-Processor: redirecting customer to secure payment page.');
        $order->save();

        // NOTE: cart is intentionally NOT emptied here — the customer may cancel or
        // fail on the cloak page and must return to a populated cart. The cart is
        // emptied only on a confirmed-approved return (see process_return()).

        $this->log("=== A-Processor REDIRECT === " . $result['pay_url']);

        return [
            'result'   => 'success',
            'redirect' => $result['pay_url'],
        ];
    }

    /**
     * Customer returned from the cloak. Confirm the real outcome with the portal
     * (authoritative — the ?status= query param is NOT trusted) and finalize.
     */
    public function process_return(array $params): void {
        $order_id = (int) ($params['order_id'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            MPS_Logger::error("A-Processor return: order #{$order_id} not found", 'mps-a');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Already finalized — just let the thank-you page render.
        if ($order->has_status(['processing', 'completed'])) {
            return;
        }

        $tx_id = (int) ($params['transaction_id'] ?? 0);
        if (!$tx_id) {
            // No id to confirm against — leave pending; reconcile/webhook will resolve it.
            MPS_Logger::info("A-Processor return: no transaction_id for order #{$order_id}, leaving pending", 'mps-a');
            $order->update_status('on-hold', 'A-Processor: awaiting payment confirmation.');
            return;
        }

        $resp = MPS_Portal_Client::get_transaction($tx_id);
        $tx   = $resp['transaction'] ?? null;

        if (empty($resp['success']) || !$tx) {
            MPS_Logger::error("A-Processor return: could not confirm tx #{$tx_id} for order #{$order_id}", 'mps-a');
            $order->update_status('on-hold', 'A-Processor: payment confirmation pending.');
            return;
        }

        $status = $tx['status'] ?? 'pending';
        $proc_tx = $tx['processor_tx_id'] ?? '';
        $brand   = $tx['card_brand'] ?? '';
        $last4   = $tx['last_four'] ?? '';

        MPS_Logger::info("A-Processor return: order #{$order_id} tx #{$tx_id} status={$status}", 'mps-a');

        if ($proc_tx) $order->update_meta_data('_mps_processor_tx_id', $proc_tx);
        if ($brand)   $order->update_meta_data('_mps_card_brand', $brand);
        if ($last4)   $order->update_meta_data('_mps_last_four', $last4);
        $order->update_meta_data('_mps_a_transaction_id', $tx_id);

        if ($status === 'approved') {
            $order->update_meta_data('_mps_a_awaiting_return', 'no');
            $order->save();
            $order->payment_complete((string) $proc_tx);
            if (function_exists('WC') && WC()->cart) WC()->cart->empty_cart(); // clear only on success
            $order->add_order_note(sprintf(
                'A-Processor: payment approved. Portal TX #%d%s%s',
                $tx_id,
                $proc_tx ? ' | Processor TX: ' . $proc_tx : '',
                $last4 ? ' | Card: ' . ucfirst($brand) . ' ***' . $last4 : ''
            ));
            return; // thank-you page renders

        } elseif ($status === 'pending') {
            $order->update_status('on-hold', 'A-Processor: payment is pending confirmation.');
            $order->save();
            return;
        }

        // declined / error / voided / refunded → treat as not paid
        $msg = $tx['status_message'] ?? 'Payment was not approved.';
        $order->update_meta_data('_mps_a_awaiting_return', 'no');
        $order->update_status('failed', 'A-Processor: payment ' . $status . ' — ' . $msg);
        $order->save();

        wc_add_notice('Your payment was not completed. Please try again or use another method.', 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    protected function log(string $message): void {
        MPS_Logger::debug($message, 'mps-a');
    }
}
