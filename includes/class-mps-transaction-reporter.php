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

        MPS_Portal_Client::report_transaction($data);
    }
}
