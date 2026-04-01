<?php
defined('ABSPATH') || exit;

class MPS_Logger {
    private static ?WC_Logger $logger = null;

    private static function logger(): WC_Logger {
        if (!self::$logger) {
            self::$logger = wc_get_logger();
        }
        return self::$logger;
    }

    public static function debug(string $message, string $source = 'mps-gateway'): void {
        $settings = get_option('woocommerce_mps_settings_settings', []);
        if (($settings['debug'] ?? 'no') === 'yes') {
            self::logger()->debug(self::sanitize($message), ['source' => $source]);
        }
    }

    public static function error(string $message, string $source = 'mps-gateway'): void {
        self::logger()->error(self::sanitize($message), ['source' => $source]);
    }

    public static function info(string $message, string $source = 'mps-gateway'): void {
        self::logger()->info(self::sanitize($message), ['source' => $source]);
    }

    /**
     * Strip sensitive card data from log messages (PCI compliance).
     */
    private static function sanitize(string $message): string {
        // Mask card numbers (13-19 digit sequences, with or without spaces/dashes)
        $message = preg_replace('/\b(\d{6})\d{3,9}(\d{4})\b/', '$1******$2', $message);

        // Mask JSON fields that may contain card data
        $sensitive_keys = ['cardNumber', 'transac_cc_number', 'transac_cc_cvc', 'cvv', 'cvc',
                          'account_password', 'account_passphrase', 'api_key', 'api_token'];

        foreach ($sensitive_keys as $key) {
            $message = preg_replace('/"' . preg_quote($key, '/') . '"\s*:\s*"[^"]*"/', '"' . $key . '":"***REDACTED***"', $message);
        }

        return $message;
    }
}
