<?php
defined('ABSPATH') || exit;

/**
 * V-Processor decline codes → what we tell the customer at checkout.
 *
 * Source: the client's "Vsafe_Codes_Color_coded" sheet (2026-07-22). Each code there carries a
 * colour AND a matching Type column; this map is built from that Type, which is the unambiguous
 * form of the same classification:
 *
 *   RED    "Fraud - Do Not Retry"                    → do not retry THIS card
 *   YELLOW "Do Not Retry - Unless Issuer contacted"  → do not retry until the bank is called
 *   GREEN  "Could Retry But card data needs verified"→ re-check the card details
 *   GREY   "Retry with another Card"                 → try a different card
 *
 * The point of showing the right one is to stop a customer hammering a card that will never work —
 * repeated declines on the same card is exactly what gets a merchant's MID flagged.
 */
class MPS_Decline_Codes {

    const DO_NOT_RETRY   = 'do_not_retry';
    const CONTACT_ISSUER = 'contact_issuer';
    const VERIFY_CARD    = 'verify_card';
    const RETRY_OTHER    = 'retry_other';

    /** Codes the sheet marks RED — fraud-related, the card must not be retried at all. */
    private static array $do_not_retry = [
        '1547', '1566', '1544', '1541', '9521', '9058',
        '9048', '9085', '9543', '9920', '9929', '9935',
    ];

    /** YELLOW — retrying is pointless until the cardholder speaks to their bank. */
    private static array $contact_issuer = ['9053', '9912'];

    /** GREEN — the card itself may be fine; the details entered need checking (expired card). */
    private static array $verify_card = ['9086'];

    /** GREY — this card won't go through, but another one might. */
    private static array $retry_other = [
        '9032', '1502', '1794', '1781', '1556', '9028', '1540', '9007', '9027', '9524',
        '1506', '9044', '9014', '1545', '9079', '9081', '9083', '9398', '9950', '9951',
    ];

    /** Which bucket a processor decline code falls into. Unknown codes → try another card. */
    public static function classify(string $code): string {
        $code = trim($code);
        if (in_array($code, self::$do_not_retry, true))   return self::DO_NOT_RETRY;
        if (in_array($code, self::$contact_issuer, true)) return self::CONTACT_ISSUER;
        if (in_array($code, self::$verify_card, true))    return self::VERIFY_CARD;

        // Everything else — including codes not on the sheet at all — gets the safe generic
        // "try a different card" (client 2026-07-22, answer to Q6).
        return self::RETRY_OTHER;
    }

    /** The message shown to the customer under the card fields. Wording is the client's. */
    public static function message(string $code): string {
        switch (self::classify($code)) {
            case self::DO_NOT_RETRY:
                return __('Transaction Decline: Please do not retry this card', 'mps-gateway');
            case self::CONTACT_ISSUER:
                return __('Transaction Declined: Please contact your card issuer before retrying this card.', 'mps-gateway');
            case self::VERIFY_CARD:
                return __('Transaction Declined: Please check your card details and expiry date, or try a different card.', 'mps-gateway');
            default:
                return __('Transaction Declined: Please try a different card.', 'mps-gateway');
        }
    }

    /** True when the customer must NOT put the same card in again. Drives the red styling. */
    public static function is_final(string $code): bool {
        return in_array(self::classify($code), [self::DO_NOT_RETRY, self::CONTACT_ISSUER], true);
    }

    /**
     * Remember the decline so the checkout can show it under the card fields on the next render.
     * Classic checkout reloads the page after a failed payment, so the message has to survive one
     * request; WC's session is the natural place. Nothing card-identifying is stored — only the
     * processor code and our own message.
     */
    public static function remember(string $code): void {
        if (!function_exists('WC') || !WC()->session) return;
        WC()->session->set('mps_last_decline', [
            'code'    => $code,
            'message' => self::message($code),
            'final'   => self::is_final($code),
            'at'      => time(),
        ]);
    }

    /**
     * Remember the decline against the ORDER, not the session. Block checkout gives the JS the order
     * id in the failure payload, so keying by order id lets the under-card notice fetch the exact
     * message without depending on the WC session cookie reaching a custom REST route (which the
     * Store API does not guarantee). Short TTL — it only has to survive until the JS asks for it.
     */
    public static function remember_for_order(int $order_id, string $code): void {
        if ($order_id <= 0) return;
        set_transient('mps_decline_' . $order_id, [
            'message' => self::message($code),
            'final'   => self::is_final($code),
        ], 10 * MINUTE_IN_SECONDS);
    }

    /** The decline stored for an order, taken once. Carries only our own wording — no card/PII. */
    public static function take_for_order(int $order_id): ?array {
        if ($order_id <= 0) return null;
        $d = get_transient('mps_decline_' . $order_id);
        if (!is_array($d) || empty($d['message'])) return null;
        delete_transient('mps_decline_' . $order_id);
        return ['message' => (string) $d['message'], 'final' => !empty($d['final'])];
    }

    /** The remembered decline, consumed once so it doesn't haunt the next checkout attempt. */
    public static function consume(): ?array {
        if (!function_exists('WC') || !WC()->session) return null;
        $d = WC()->session->get('mps_last_decline');
        if (!is_array($d) || empty($d['message'])) return null;
        WC()->session->set('mps_last_decline', null);

        // Ignore anything stale (e.g. a decline from an abandoned session an hour ago).
        if (!empty($d['at']) && (time() - (int) $d['at']) > 900) return null;

        return $d;
    }
}
