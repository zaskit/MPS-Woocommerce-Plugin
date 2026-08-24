<?php
/**
 * Card BINs the processor will never approve.
 *
 * The point is not a nicer decline message — it is that the card is never sent for processing, so
 * an attempt that was always going to be refused does not become a decline on our numbers or on the
 * merchant's MID. The list is defined per processor in the portal and arrives with the gateway
 * config the plugin already fetches and caches, so nothing here is hardcoded.
 *
 * Two layers, doing different jobs:
 *   - the browser checks as the number is typed, so the customer is told immediately;
 *   - the server checks again before the processor call, because JS can be broken by a theme or a
 *     script optimizer. v2.5.8 exists because exactly that happened to the Block checkout.
 */

defined('ABSPATH') || exit;

class MPS_BIN_Blocker {

    /** Shown when the portal record carries no message of its own, and no merchant name resolves. */
    const DEFAULT_MESSAGE = 'This card cannot be used at this store. Please try a different card.';

    /**
     * The default refusal, naming the merchant where we can.
     *
     * "this store" is vague at the one moment the customer is deciding whether the problem is them
     * or us; naming the merchant says plainly which shop refused the card, and matches how every
     * other post-2026-08-20 customer-facing string identifies the merchant rather than the
     * processor (client 2026-08-21). Falls back to the generic wording when no name resolves, so
     * the sentence can never render with a hole in it.
     */
    public static function default_message(): string {
        $merchant = class_exists('MPS_Merchant_Contact') ? MPS_Merchant_Contact::name() : '';

        if ('' === $merchant) {
            return self::DEFAULT_MESSAGE;
        }

        return sprintf('This card cannot be used at %s. Please try a different card.', $merchant);
    }

    /**
     * The rule matching this card number, or null.
     *
     * @param array  $bins        [['bin' => '411111', 'message' => '...'], ...] — the portal sends
     *                            these longest-first so a specific 8-digit rule beats a broad
     *                            6-digit one covering the same range.
     * @param string $card_number Raw input; non-digits are ignored.
     */
    public static function match(array $bins, string $card_number): ?array {
        $digits = preg_replace('/\D/', '', $card_number);
        if ('' === $digits) {
            return null;
        }

        foreach ($bins as $rule) {
            $bin = preg_replace('/\D/', '', (string) ($rule['bin'] ?? ''));
            if ('' === $bin) {
                continue;
            }
            // Only compare once the customer has typed at least as many digits as the rule is
            // long; a 6-digit rule must not fire on the first two digits they enter.
            if (strlen($digits) >= strlen($bin) && 0 === strncmp($digits, $bin, strlen($bin))) {
                return [
                    'bin'     => $bin,
                    'message' => trim((string) ($rule['message'] ?? '')) ?: self::default_message(),
                ];
            }
        }

        return null;
    }

    /**
     * Record a prevented attempt in the Transaction Monitor ledger.
     *
     * Written as its own outcome so it never lands in the decline numbers — a blocked card is the
     * opposite of a decline, and counting it as one would make the rate we are trying to reduce go
     * up as the feature does its job. There may be no order yet (classic checkout validates before
     * the order exists), hence order_id 0 and the email read from the posted checkout fields.
     */
    public static function log(string $gateway_id, string $bin, ?WC_Order $order = null): void {
        if (!class_exists('MPS_Monitor_Ledger') || !MPS_Monitor_Notifier::is_enabled()) {
            return;
        }

        $email = $order ? $order->get_billing_email() : '';
        if ('' === $email && !empty($_POST['billing_email'])) {
            $email = sanitize_email(wp_unslash($_POST['billing_email']));
        }

        $now = current_time('mysql');

        MPS_Monitor_Ledger::record([
            'order_id'       => $order ? $order->get_id() : 0,
            'customer_id'    => $order ? $order->get_customer_id() : get_current_user_id(),
            'billing_email'  => $email,
            'billing_name'   => $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '',
            'gateway'        => $gateway_id,
            'outcome'        => 'blocked',
            'amount'         => $order ? (float) $order->get_total() : (float) (WC()->cart ? WC()->cart->get_total('edit') : 0),
            'currency'       => get_woocommerce_currency(),
            'decline_code'   => 'BIN',
            'decline_detail' => 'Blocked BIN ' . $bin . ' — not sent for processing',
            'posture'        => MPS_Decline_Codes::RETRY_OTHER,
            'notify_state'   => 'na',
            'notify_note'    => 'Blocked before processing — no decline occurred',
            'created_at'     => $now,
            // One row per card entry attempt, not per keystroke.
            'note_hash'      => md5('blocked|' . $gateway_id . '|' . $bin . '|' . $email . '|' . $now),
        ]);
    }
}
