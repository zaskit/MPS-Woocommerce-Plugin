<?php
defined('ABSPATH') || exit;

class MPS_VProcessor_3D_Webhook {

    /**
     * Handle vSafe webhook POST (async notification from processor).
     * Registered on: woocommerce_api_mps_vsafe_webhook
     */
    public static function handle(): void {
        $raw_post = file_get_contents('php://input');
        $data     = json_decode($raw_post, true);

        MPS_Logger::debug('VP3D Webhook received (sanitized in logger)', 'mps-vp3d-webhook');

        if (empty($data)) {
            self::send_response('ERROR', 'Empty payload', '', '', 400);
            return;
        }

        // Resolve order from externalReference
        $ext_ref  = $data['externalReference'] ?? '';
        $order_id = (int) explode('-', $ext_ref)[0];
        $order    = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            MPS_Logger::error("VP3D Webhook: Order not found for ref: {$ext_ref}", 'mps-vp3d-webhook');
            self::send_response('ERROR', 'Order not found', '', $ext_ref, 404);
            return;
        }

        // Verify signature using the gateway's API key
        $gateway = self::resolve_gateway($order);
        if (!$gateway) {
            MPS_Logger::error("VP3D Webhook: Gateway not found for order #{$order_id} — rejecting", 'mps-vp3d-webhook');
            self::send_response('ERROR', 'Gateway not found', '', $ext_ref, 400);
            return;
        }

        $api_key  = $gateway->credentials['api_key'] ?? $gateway->credentials['api_token'] ?? '';
        $expected = hash('sha256', $api_key . $raw_post . $api_key);
        $received = $_SERVER['HTTP_SIGNATURE'] ?? '';

        if (!hash_equals($expected, $received)) {
            MPS_Logger::error("VP3D Webhook: Signature mismatch for order #{$order_id}", 'mps-vp3d-webhook');
            self::send_response('ERROR', 'Invalid signature', '', $ext_ref, 401);
            return;
        }

        $tx_type = $data['transactionType'] ?? $data['type'] ?? 'payment';

        if (in_array($tx_type, ['payment', 'deposit'], true)) {
            self::process_payment_webhook($order, $data);
        } elseif ($tx_type === 'refund') {
            self::process_refund_webhook($order, $data);
        }

        $tx_id = $data['transactionId'] ?? '';
        self::send_response('OK', 'Transaction Updated', $tx_id, $ext_ref);
    }

    /**
     * Handle 3DS return (customer redirected back after challenge).
     * Registered on: woocommerce_api_mps_vsafe_3ds_return
     */
    public static function handle_3ds_return(): void {
        MPS_Logger::info('VP3D 3DS Return received — GET: ' . wp_json_encode($_GET), 'mps-vp3d-webhook');

        $order_id       = (int) ($_GET['order_id'] ?? 0);
        $threed_result  = null;

        // Try externalReference
        if (!$order_id && !empty($_GET['externalReference'])) {
            $order_id = (int) explode('-', $_GET['externalReference'])[0];
        }

        // vSafe sends base64-encoded 'result' param after 3DS challenge
        if (!empty($_GET['result'])) {
            $threed_result = json_decode(base64_decode($_GET['result']), true);
            MPS_Logger::info('VP3D 3DS result decoded: ' . wp_json_encode($threed_result), 'mps-vp3d-webhook');

            // Resolve order by transaction reference if not yet found
            if (!$order_id && !empty($threed_result['reference'])) {
                $tx_ref = sanitize_text_field($threed_result['reference']);
                $orders = wc_get_orders([
                    'limit'      => 1,
                    'meta_key'   => '_mps_vp3d_transaction_id',
                    'meta_value' => $tx_ref,
                ]);
                if (!empty($orders)) {
                    $order_id = $orders[0]->get_id();
                    MPS_Logger::info("VP3D 3DS Return: Order #{$order_id} found by TX ref {$tx_ref}", 'mps-vp3d-webhook');
                }
            }
        }

        $order = $order_id ? wc_get_order($order_id) : null;

        if (!$order) {
            MPS_Logger::error("VP3D 3DS Return: Order not found", 'mps-vp3d-webhook');
            wc_add_notice('Could not locate your order. Please contact support.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        MPS_Logger::info("VP3D 3DS Return: Order #{$order_id} status=" . $order->get_status(), 'mps-vp3d-webhook');

        // Already completed? Just redirect to thank-you
        if ($order->has_status(['processing', 'completed'])) {
            $gateway = self::resolve_gateway($order);
            wp_redirect($gateway ? $gateway->get_return_url($order) : $order->get_checkout_order_received_url());
            exit;
        }

        // Failed
        if ($order->has_status('failed')) {
            wc_add_notice('Payment failed. Please try again.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Process the 3DS result
        if ($threed_result && isset($threed_result['status'])) {
            $threed_status = strtoupper($threed_result['status']);
            $stored_tx_id  = $order->get_meta('_mps_vp3d_transaction_id');
            $result_ref    = $threed_result['reference'] ?? '';
            $result_tx_id  = $threed_result['transactionId'] ?? '';
            $auth_number   = $threed_result['authorizationNumber'] ?? '';
            $tx_id         = $stored_tx_id ?: $result_ref ?: $result_tx_id;

            if ($threed_status === 'APPROVED') {
                if (!$order->has_status(['processing', 'completed'])) {
                    $order->payment_complete($tx_id);
                    wc_reduce_stock_levels($order_id);
                    $order->add_order_note(sprintf('VP3D: 3DS approved. TX: %s Auth: %s', $tx_id, $auth_number));
                    $order->update_meta_data('_mps_vp3d_webhook_status', 'approved');
                    $order->save();

                    MPS_Transaction_Reporter::report($order, [
                        'status' => 'approved',
                        'processor_tx_id' => $tx_id,
                        'is_3ds' => true,
                    ]);
                }

                $gateway = self::resolve_gateway($order);
                wp_redirect($gateway ? $gateway->get_return_url($order) : $order->get_checkout_order_received_url());
                exit;
            }

            if (in_array($threed_status, ['DECLINED', 'REJECTED', 'FAILED'], true)) {
                $order->update_status('failed', 'VP3D: 3DS ' . $threed_status);
                $order->update_meta_data('_mps_vp3d_webhook_status', 'declined');
                $order->save();

                MPS_Transaction_Reporter::report($order, [
                    'status' => 'declined',
                    'status_message' => '3DS ' . $threed_status,
                    'is_3ds' => true,
                ]);

                wc_add_notice('Payment verification failed. Please try again.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
        }

        // No definitive result yet — set up polling
        $order->update_status('on-hold', 'Customer returned from 3DS. Awaiting final confirmation.');
        $order->save();

        $gateway = self::resolve_gateway($order);
        $polling_url = add_query_arg([
            'mps_vp3d_poll' => '1',
            'order_id'      => $order_id,
            'key'           => $order->get_order_key(),
        ], $gateway ? $gateway->get_return_url($order) : $order->get_checkout_order_received_url());

        wp_redirect($polling_url);
        exit;
    }

    private static function process_payment_webhook(WC_Order $order, array $data): void {
        $status     = strtolower($data['result']['status'] ?? 'error');
        $tx_id      = $data['transactionId'] ?? '';
        $redirect   = $data['result']['redirectUrl'] ?? $data['redirectUrl'] ?? '';

        // Skip if already completed
        if ($order->has_status(['processing', 'completed'])) return;

        // 3DS redirect URL via webhook
        if (!empty($redirect) && $status === 'pending') {
            $order->update_meta_data('_mps_vp3d_3ds_redirect_url', esc_url_raw($redirect));
            $order->update_meta_data('_mps_vp3d_webhook_status', 'redirect_3ds');
            $order->save();
            return;
        }

        if ($status === 'approved') {
            // Clear cache for race condition
            clean_post_cache($order->get_id());
            wp_cache_delete('order-' . $order->get_id(), 'orders');
            $fresh = wc_get_order($order->get_id());
            if ($fresh && $fresh->has_status(['processing', 'completed'])) return;

            $order->payment_complete($tx_id);
            wc_reduce_stock_levels($order->get_id());
            $order->add_order_note('VP3D Webhook: Payment approved. TX: ' . $tx_id);
            $order->update_meta_data('_mps_vp3d_webhook_status', 'approved');

            // Update card info if present
            if (!empty($data['cardBrand'])) {
                $order->update_meta_data('_mps_card_brand', strtolower($data['cardBrand']));
            }
            if (!empty($data['lastFour'])) {
                $order->update_meta_data('_mps_last_four', $data['lastFour']);
            }
            $order->save();

            MPS_Transaction_Reporter::report($order, [
                'status' => 'approved',
                'processor_tx_id' => $tx_id,
                'is_3ds' => true,
            ]);
        } elseif (in_array($status, ['declined', 'error'], true)) {
            $detail = $data['result']['errorDetail'] ?? $status;
            $order->update_status('failed', "VP3D Webhook: {$status} — {$detail}");
            $order->update_meta_data('_mps_vp3d_webhook_status', $status);
            $order->save();

            MPS_Transaction_Reporter::report($order, [
                'status' => 'declined',
                'status_message' => $detail,
                'is_3ds' => true,
            ]);
        }
    }

    private static function process_refund_webhook(WC_Order $order, array $data): void {
        $status = strtolower($data['result']['status'] ?? 'error');
        if ($status === 'approved') {
            $order->add_order_note('VP3D Webhook: Refund approved. TX: ' . ($data['transactionId'] ?? ''));
        }
    }

    private static function resolve_gateway(WC_Order $order): ?MPS_Base_Gateway {
        $payment_method = $order->get_payment_method();
        return MPS_Gateway_Factory::find($payment_method);
    }

    private static function send_response(string $status, string $desc, string $tx_id, string $merchant_tx_id, int $http_code = 200): void {
        status_header($http_code);
        header('Content-Type: application/json');
        echo wp_json_encode([
            'status'                => $status,
            'description'           => $desc,
            'transactionId'         => $tx_id,
            'merchantTransactionId' => $merchant_tx_id,
        ]);
        exit;
    }
}
