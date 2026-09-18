<?php
defined('ABSPATH') || exit;

class MPS_EProcessor_API {

    public const PROCESS_URL = 'https://ts.secure1gateway.com/api/v2/processTx';
    public const REFUND_URL  = 'https://ts.secure1gateway.com/api/v2/processRefund';

    /**
     * Generate SHA256 for payment request (with card).
     */
    public static function sha_with_card(string $passphrase, string $amount, string $account_id, string $email, string $card_number, string $ip): string {
        return hash('sha256', $passphrase . $amount . $account_id . $email . $card_number . $ip);
    }

    /**
     * Generate SHA256 for hosted payment request (no card).
     */
    public static function sha_without_card(string $passphrase, string $amount, string $account_id, string $email, string $ip): string {
        return hash('sha256', $passphrase . $amount . $account_id . $email . $ip);
    }

    /**
     * Generate SHA256 for refund.
     */
    public static function sha_refund(string $passphrase, string $account_id, string $transaction_id): string {
        return hash('sha256', $passphrase . $account_id . $transaction_id);
    }

    /**
     * Refund an E payment — shared by the 2D, 3D and Hosted gateways.
     *
     * v2.8.0: the amount is SENT. Before, no amount went to the processor, so a partial refund typed in
     * WooCommerce refunded the FULL payment at the processor while Woo recorded only the partial (and
     * the order note printed the requested amount, so it looked right). The processor allows ONE refund
     * per payment, partial allowed (`transac_amount`) — so a second refund on the same order is refused
     * here, before anything is sent.
     *
     * A full refund sends exactly what it always did (no amount). `transac_amount` is added only when
     * the refund is partial. The request signature is unchanged — the guide signs passphrase +
     * account_id + trans_id only.
     */
    public static function refund(array $credentials, WC_Order $order, $amount, string $label): bool|\WP_Error {
        $tx_id = (string) $order->get_meta('_mps_ep_transaction_id');
        if ($tx_id === '') {
            return new \WP_Error('no_tx', 'No transaction ID found.');
        }

        if ($order->get_meta('_mps_ep_refund_done') === 'yes') {
            return new \WP_Error('refund_once', 'This processor allows one refund per payment, and this payment already has one. Refund anything more to the customer another way.');
        }

        $amount = round((float) $amount, 2);
        $total  = round((float) $order->get_total(), 2);
        if ($amount <= 0) {
            return new \WP_Error('refund_amount', 'Enter a refund amount.');
        }
        if ($amount > $total) {
            return new \WP_Error('refund_amount', 'The refund is more than the payment.');
        }
        $partial = $amount < $total;

        $account_id = $credentials['account_id'] ?? '';
        $password   = $credentials['account_password'] ?? '';
        $passphrase = $credentials['account_passphrase'] ?? '';

        $data = [
            'account_id'       => $account_id,
            'account_password' => $password,
            'account_sha'      => self::sha_refund($passphrase, $account_id, $tx_id),
            'trans_id'         => $tx_id,
            'option'           => '',
        ];
        if ($partial) {
            $data['transac_amount'] = number_format($amount, 2, '.', '');
        }

        $response = self::post(self::REFUND_URL, $data);
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $result = self::parse_response($response);
        $status = (string) ($result['resp_trans_status'] ?? '');
        $desc   = (string) ($result['resp_trans_description_status'] ?? '');
        MPS_Logger::info(sprintf('%s refund order %d tx %s amount %s%s → %s %s', $label, $order->get_id(), $tx_id,
            number_format($amount, 2, '.', ''), $partial ? ' (partial)' : '', $status ?: 'no status', $desc), 'mps-ep-refund');

        // The status API lists a refund as its own R0000 transaction, so the refund call may answer with
        // R0000 rather than 00000 — both mean done. PEND = accepted, still being processed: the money is
        // on its way back, so reporting a failure (and letting someone refund again) would be worse.
        if (in_array($status, ['00000', 'R0000', 'PEND'], true)) {
            $order->update_meta_data('_mps_ep_refund_done', 'yes');
            $order->update_meta_data('_mps_ep_refund_tx_id', (string) ($result['resp_trans_id'] ?? ''));
            $order->save();
            $order->add_order_note(sprintf('%s refund %s: %s %s (%s). Refund TX: %s',
                $label, $status === 'PEND' ? 'accepted, pending at the processor' : 'approved',
                number_format($amount, 2), $order->get_currency(), $partial ? 'partial' : 'full',
                $result['resp_trans_id'] ?? '—'));
            return true;
        }

        return new \WP_Error('refund_failed', trim(($status ? "[{$status}] " : '') . ($desc ?: 'Refund failed')));
    }

    /**
     * Verify response SHA256.
     */
    public static function verify_response_sha(string $passphrase, array $response): bool {
        $expected = hash('sha256',
            $passphrase .
            ($response['resp_trans_id'] ?? '') .
            ($response['resp_trans_amount'] ?? '') .
            ($response['resp_trans_status'] ?? '')
        );
        return hash_equals($expected, $response['resp_sha'] ?? '');
    }

    /**
     * Send form-encoded POST request.
     */
    public static function post(string $url, array $data, int $timeout = 95): array|\WP_Error {
        return wp_remote_post($url, [
            'method'      => 'POST',
            'timeout'     => $timeout,
            'httpversion' => '1.1',
            'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'        => $data,
            'sslverify'   => true,
        ]);
    }

    /**
     * Parse transaction status from API response.
     */
    public static function parse_transaction_status(array $data): array {
        $status = $data['resp_trans_status'] ?? 'error';
        return [
            'status'         => $status,
            'transaction_id' => $data['resp_trans_id'] ?? '',
            'amount'         => $data['resp_trans_amount'] ?? '',
            'description'    => $data['resp_trans_description_status'] ?? $status,
            'is_success'     => $status === '00000',
            'is_pending'     => $status === 'PEND',
            'is_failed'      => $status !== '00000' && $status !== 'PEND',
        ];
    }

    /**
     * Build redirect URL from EuPaymentz response (for 3DS).
     */
    public static function build_redirect_url(array $response): string {
        if (empty($response['UrlToRedirect'])) return '';
        $url    = $response['UrlToRedirect'];
        $method = $response['UrlToRedirectMethod'] ?? 'GET';

        if ($method === 'GET' && !empty($response['UrlToRedirecPostedParameters'])) {
            $params = [];
            foreach ($response['UrlToRedirecPostedParameters'] as $p) {
                if (isset($p['key'], $p['value'])) {
                    $params[$p['key']] = $p['value'];
                }
            }
            if (!empty($params)) {
                $url = add_query_arg($params, $url);
            }
        }
        return $url;
    }

    /**
     * Parse API response body (handles wrapped or unwrapped JSON).
     */
    public static function parse_response($response): ?array {
        if (is_wp_error($response)) return null;
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        if (isset($result['success']) && $result['success'] === true && isset($result['data'])) {
            return $result['data'];
        }
        return $result;
    }
}
