<?php
/**
 * Card number validation, done before anything is sent for processing.
 *
 * A card number that fails these checks cannot be approved by any processor — the digits are not a
 * card. Sending it anyway produces a "Format Error" on the MID and reads to the customer as a
 * decline, which is the worst of both: our acceptance rate drops and they think their bank refused
 * them. The processor's own guidance (2026-08-20) is the sequence implemented here:
 *
 *   strip separators -> digits only -> identify the scheme from the BIN -> check the length for
 *   that scheme -> Luhn -> expiry -> CVV length for that scheme -> only then authorise.
 *
 * Luhn alone is not enough: 0000000000000000 passes it. Scheme and length are what make it mean
 * something.
 *
 * @see MPS_BIN_Blocker for the separate question of cards that are real but never approved.
 */

defined('ABSPATH') || exit;

class MPS_Card_Validator {

    /**
     * Card schemes we can recognise, with the PAN lengths each one issues.
     *
     * Ranges are the current ones published by the schemes. An unrecognised BIN is NOT an error —
     * new ranges appear (Mastercard's 2-series did) and refusing an unknown range would decline
     * good cards. Unknown falls back to the generic 13-19 length check plus Luhn.
     */
    const SCHEMES = [
        'visa'       => ['label' => 'Visa',        'lengths' => [13, 16, 19], 'cvv' => [3]],
        'mastercard' => ['label' => 'Mastercard',  'lengths' => [16],         'cvv' => [3]],
        'amex'       => ['label' => 'American Express', 'lengths' => [15],    'cvv' => [4]],
        'discover'   => ['label' => 'Discover',    'lengths' => [16, 19],     'cvv' => [3]],
        'diners'     => ['label' => 'Diners Club', 'lengths' => [14, 16, 19], 'cvv' => [3]],
        'jcb'        => ['label' => 'JCB',         'lengths' => [16, 17, 18, 19], 'cvv' => [3]],
        'unionpay'   => ['label' => 'UnionPay',    'lengths' => [16, 17, 18, 19], 'cvv' => [3]],
    ];

    /** Digits only. Customers paste numbers with spaces, hyphens and non-breaking spaces. */
    public static function digits(string $card_number): string {
        return preg_replace('/\D/', '', $card_number);
    }

    /**
     * The Luhn (mod 10) check digit test.
     *
     * From the right: keep the check digit, double every second digit, subtract 9 from any result
     * over 9, sum, and a valid PAN is divisible by 10.
     */
    public static function luhn(string $card_number): bool {
        $pan = self::digits($card_number);
        if ('' === $pan) {
            return false;
        }

        $sum = 0;
        $double = false;
        for ($i = strlen($pan) - 1; $i >= 0; $i--) {
            $digit = (int) $pan[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return 0 === $sum % 10;
    }

    /** Scheme key for a PAN, or null when the range is not one we recognise. */
    public static function scheme(string $card_number): ?string {
        $pan = self::digits($card_number);
        if (strlen($pan) < 4) {
            return null;
        }

        $p1 = (int) substr($pan, 0, 1);
        $p2 = (int) substr($pan, 0, 2);
        $p3 = (int) substr($pan, 0, 3);
        $p4 = (int) substr($pan, 0, 4);
        $p6 = (int) substr($pan, 0, 6);

        if (4 === $p1) {
            return 'visa';
        }
        if (($p2 >= 51 && $p2 <= 55) || ($p4 >= 2221 && $p4 <= 2720)) {
            return 'mastercard';
        }
        if (34 === $p2 || 37 === $p2) {
            return 'amex';
        }
        if (6011 === $p4 || 65 === $p2 || ($p3 >= 644 && $p3 <= 649) || ($p6 >= 622126 && $p6 <= 622925)) {
            return 'discover';
        }
        if (($p3 >= 300 && $p3 <= 305) || 36 === $p2 || 38 === $p2) {
            return 'diners';
        }
        if ($p4 >= 3528 && $p4 <= 3589) {
            return 'jcb';
        }
        if (62 === $p2) {
            return 'unionpay';
        }

        return null;
    }

    /** Human name for the scheme, for messages. */
    public static function scheme_label(string $card_number): string {
        $key = self::scheme($card_number);

        return $key ? self::SCHEMES[$key]['label'] : '';
    }

    /**
     * The reason this card number cannot be a card, or null if it survives every check.
     *
     * One deliberately generic message for every failure: the customer's fix is the same in all
     * cases (re-type the number), and naming which test failed would tell a card tester which digit
     * to change.
     */
    public static function error(string $card_number): ?string {
        $pan = self::digits($card_number);

        if ('' === $pan) {
            return __('Card number is required.', 'mps-gateway');
        }

        $generic = __('Please check the card number and enter it again.', 'mps-gateway');

        // No PAN is shorter than 12 or longer than 19, whatever the scheme.
        if (strlen($pan) < 12 || strlen($pan) > 19) {
            return $generic;
        }

        // Every digit the same passes Luhn for some repeats (0000000000000000 among them) and is
        // never a real card — the processor's own note calls this out.
        if (preg_match('/^(\d)\1+$/', $pan)) {
            return $generic;
        }

        $scheme = self::scheme($pan);
        if ($scheme && ! in_array(strlen($pan), self::SCHEMES[$scheme]['lengths'], true)) {
            return $generic;
        }

        if (! self::luhn($pan)) {
            return $generic;
        }

        return null;
    }

    /**
     * The reason this CVV cannot belong to this card, or null.
     *
     * Amex uses 4 digits, everything else 3. When the scheme is unknown, accept either rather than
     * guess — a wrong guess here blocks a sale over a field the processor would have accepted.
     */
    public static function cvv_error(string $cvv, string $card_number = ''): ?string {
        $cvv = preg_replace('/\D/', '', $cvv);
        if ('' === $cvv) {
            return __('Please enter the security code (CVV) from your card.', 'mps-gateway');
        }

        $scheme = self::scheme($card_number);
        $allowed = $scheme ? self::SCHEMES[$scheme]['cvv'] : [3, 4];

        if (! in_array(strlen($cvv), $allowed, true)) {
            return 4 === reset($allowed) && 1 === count($allowed)
                ? __('American Express security codes are 4 digits.', 'mps-gateway')
                : __('Please enter a valid security code (CVV).', 'mps-gateway');
        }

        return null;
    }
}
