<?php
defined('ABSPATH') || exit;

class MPS_Transaction_Reporter {

    /**
     * Report a transaction result to the portal.
     * Called after payment_complete() or update_status('failed').
     */
    public static function report(WC_Order $order, array $extra = []): void {
        $data = array_merge([
            'gateway_id'      => $order->get_meta('_mps_portal_gateway_id'),
            'order_ref'       => (string) $order->get_id(),
            'processor_tx_id' => $order->get_meta('_mps_processor_tx_id') ?: null,
            'amount'          => (float) $order->get_total(),
            'currency'        => $order->get_currency(),
            'status'          => 'pending',
            'status_code'     => null,
            'status_message'  => null,
            'card_brand'      => $order->get_meta('_mps_card_brand') ?: null,
            'last_four'       => $order->get_meta('_mps_last_four') ?: null,
            'customer_email'  => $order->get_billing_email(),
            'is_3ds'          => false,
        ], $extra);

        $ack = self::charge_acknowledgment($order, $data);
        if ($ack) {
            $data['charge_acknowledgment'] = $ack;
        }

        MPS_Portal_Client::report_transaction($data);
    }

    /**
     * The Cardholder Charge Acknowledgment to send with this transaction, or null when the customer
     * did not tick the box. Composed here (not in each gateway) so every processor reports it the
     * same way — see mps_capture_charge_acknowledgment() for where consent is recorded.
     *
     * Carries the last four only. A full card number must never leave the store.
     */
    private static function charge_acknowledgment(WC_Order $order, array $data): ?array {
        if ($order->get_meta('_mps_charge_ack_accepted') !== 'yes') return null;

        // Only meaningful on a charge that actually went through — there is nothing to acknowledge
        // on a decline, and sending one would put an unsigned form against a failed transaction.
        if (($data['status'] ?? '') !== 'approved') return null;

        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name === '') $name = trim((string) $order->get_formatted_billing_full_name());

        $accepted_at = $order->get_meta('_mps_charge_ack_at') ?: gmdate('Y-m-d H:i:s');
        $created     = $order->get_date_created();

        return [
            'accepted'         => true,
            'full_name'        => $name,
            // The typed billing name IS the signature; the portal renders it in italic script.
            'signature'        => $name,
            'email'            => $order->get_billing_email(),
            'phone'            => $order->get_billing_phone(),
            'amount'           => (string) $order->get_total(),
            'currency'         => $order->get_currency(),
            'last_four'        => (string) ($data['last_four'] ?? $order->get_meta('_mps_last_four')),
            'descriptor'       => (string) $order->get_meta('_mps_descriptor'),
            'order_ref'        => (string) $order->get_id(),
            'transaction_date' => $created ? $created->date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
            'accepted_at'      => $accepted_at,
            'accepted_ip'      => (string) $order->get_meta('_mps_charge_ack_ip'),
            'store_url'        => home_url(),
            'plugin_version'   => defined('MPS_PLUGIN_VERSION') ? MPS_PLUGIN_VERSION : '',
        ];
    }
}
