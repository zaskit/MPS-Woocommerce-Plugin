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
     * Generate SHA256 for refund.
     */
    public static function sha_refund(string $passphrase, string $account_id, string $transaction_id): string {
        return hash('sha256', $passphrase . $account_id . $transaction_id);
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
