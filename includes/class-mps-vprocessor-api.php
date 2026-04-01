<?php
defined('ABSPATH') || exit;

class MPS_VProcessor_API {

    public static function endpoint(string $env, string $type, string $version = '1'): string {
        $base = ($env === 'live') ? 'https://vsafe.tech' : 'https://sandbox.vsafe.tech';
        return $base . '/api/v' . $version . '/' . $type . '/';
    }

    public static function sign(string $key, string $json): string {
        return hash('sha256', $key . $json . $key);
    }

    public static function post(string $url, string $key, array $body, int $timeout = 70): array|\WP_Error {
        $json = wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Signature'    => self::sign($key, $json),
            ],
            'body'    => $json,
            'timeout' => $timeout,
        ]);
    }

    /**
     * Map vSafe error codes to user-friendly messages.
     */
    public static function friendly_error(string $code): string {
        $map = [
            '1050' => 'Invalid request. Please try again.',
            '1052' => 'Payment service temporarily unavailable.',
            '1053' => 'Payment service temporarily unavailable.',
            '1054' => 'Payment service temporarily unavailable.',
            '1079' => 'Payment could not be processed.',
            '1082' => 'Invalid billing information.',
            '1083' => 'Invalid billing information.',
            '1103' => 'Payment service temporarily unavailable.',
            '1507' => 'Invalid card details. Please check and try again.',
            '1508' => 'Invalid card number.',
            '1509' => 'Invalid amount.',
            '1510' => 'Invalid currency.',
            '1511' => 'Invalid card details.',
            '1536' => 'Card expired.',
            '1545' => 'Transaction limit exceeded. Try a smaller amount.',
            '1552' => 'Session expired. Please try again.',
            '1565' => 'Invalid billing details.',
            '1569' => 'Payment declined. Please try a different card.',
            '9011' => 'Card declined by your bank.',
            '9025' => 'Card declined by your bank.',
            '9034' => 'Suspected fraud. Contact your bank.',
            '9051' => 'Insufficient funds.',
            '9054' => 'Card expired.',
            '9055' => 'Incorrect PIN.',
            '9057' => 'Transaction not permitted.',
            '9061' => 'Transaction limit exceeded.',
            '9062' => 'Card restricted.',
            '9065' => 'Transaction limit exceeded.',
            '9082' => 'CVV verification failed.',
            '9091' => 'Bank unavailable. Try again later.',
            '9096' => 'Payment could not be processed.',
            '9553' => 'Payment declined. Please try again.',
            '9566' => 'Invalid amount format.',
            '9836' => 'Payment could not be processed.',
            '9847' => 'Velocity limit reached. Try again later.',
            '9862' => 'Bank unavailable. Try again later.',
            '9867' => 'Bank unavailable. Try again later.',
        ];

        if (isset($map[$code])) return $map[$code];

        $num = (int) $code;
        if ($num >= 1050 && $num <= 1103) return 'Payment service temporarily unavailable.';
        if ($num >= 1507 && $num <= 1569) return 'Payment declined. Please check your card details.';
        if ($num >= 1700 && $num <= 1796) return 'Payment could not be processed.';
        if ($num >= 9000 && $num <= 9099) return 'Card declined by your bank.';
        if ($num >= 9500) return 'Card declined by your bank.';

        return 'Payment could not be processed. Please try again.';
    }
}
