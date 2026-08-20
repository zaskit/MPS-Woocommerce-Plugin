<?php
/**
 * Who the customer should contact — resolved once, used everywhere.
 *
 * A customer of Nxtstate googled the descriptor on their statement (POSICIONA MKT), found the MID
 * holder and phoned them instead of the store (2026-08-20). Every message we send after a payment
 * now has to name the merchant and its support details loudly enough that this does not happen
 * again — which only works if the details are the merchant's REAL ones.
 *
 * 🛑 Hence the placeholder rejection below. A WordPress install ships with an admin address like
 * dev-email@wpengine.local and WooCommerce happily sends from it; printing that under "CONTACT US"
 * is worse than printing nothing, because it looks like an answer and is a dead end. Anything we
 * cannot vouch for is dropped and reported to the merchant in wp-admin instead.
 */

defined('ABSPATH') || exit;

class MPS_Merchant_Contact {

    /** Hosts that only ever appear in a default install, a staging clone, or documentation. */
    const PLACEHOLDER_HOSTS = [
        'wpengine.local', 'example.com', 'example.org', 'example.net', 'localhost',
        'sentry.wordpress.org', 'wordpress.local',
    ];

    /** Suffixes that are never routable mail domains. */
    const PLACEHOLDER_TLDS = ['.local', '.test', '.invalid', '.example', '.localhost'];

    /** Local parts WordPress/WooCommerce generate on their own. */
    const PLACEHOLDER_LOCALS = ['dev-email', 'wordpress', 'admin@localhost'];

    private static function settings(): array {
        return (array) get_option('woocommerce_mps_settings_settings', []);
    }

    /** The merchant's trading name, as the customer knows it. */
    public static function name(): string {
        $s = self::settings();
        $candidates = [
            $s['support_name'] ?? '',
            get_option('woocommerce_email_from_name', ''),
            get_bloginfo('name'),
        ];

        foreach ($candidates as $value) {
            $value = trim(wp_strip_all_tags((string) $value));
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /**
     * A support address we are willing to print, or ''.
     *
     * Order: what the merchant typed for MPS, then WooCommerce's sender, then the site admin. Each
     * is only used if it survives is_real_email().
     */
    public static function email(): string {
        $s = self::settings();
        $candidates = [
            $s['support_email'] ?? '',
            get_option('woocommerce_email_from_address', ''),
            get_option('admin_email', ''),
        ];

        foreach ($candidates as $value) {
            $value = trim((string) $value);
            if (self::is_real_email($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The support phone, or ''.
     *
     * There is no other source for this: WooCommerce has no store-phone setting and the portal does
     * not hold one either. If the merchant has not typed it, the line is left out rather than
     * printed empty.
     */
    public static function phone(): string {
        $s = self::settings();
        $phone = trim((string) ($s['support_phone'] ?? ''));

        // Needs enough digits to be dialled. "TBD", "n/a" and "-" are all things people type.
        return preg_match('/\d{6,}/', preg_replace('/\D/', '', $phone)) ? $phone : '';
    }

    /** The store's own address, which is where "contact the merchant" ultimately points. */
    public static function website(): string {
        $s = self::settings();
        $url = trim((string) ($s['support_url'] ?? ''));
        if ('' === $url) {
            $url = home_url();
        }

        return esc_url_raw($url);
    }

    /** Everything at once, for the templates. */
    public static function all(): array {
        return [
            'name'    => self::name(),
            'email'   => self::email(),
            'phone'   => self::phone(),
            'website' => self::website(),
        ];
    }

    /**
     * Would this address send a customer somewhere real?
     *
     * 🛑 Deliberately conservative: a false positive here is a customer emailing a black hole and
     * then phoning the MID holder anyway, which is the exact failure we are fixing.
     */
    public static function is_real_email(string $email): bool {
        $email = trim($email);
        if ('' === $email || ! is_email($email)) {
            return false;
        }

        [$local, $host] = array_pad(explode('@', strtolower($email), 2), 2, '');

        if (in_array($host, self::PLACEHOLDER_HOSTS, true)) {
            return false;
        }
        foreach (self::PLACEHOLDER_TLDS as $tld) {
            if (substr($host, -strlen($tld)) === $tld) {
                return false;
            }
        }
        if (in_array($local, self::PLACEHOLDER_LOCALS, true)) {
            return false;
        }
        // A host with no dot cannot be a public mail domain.
        if (false === strpos($host, '.')) {
            return false;
        }

        return true;
    }

    /**
     * Which customer-facing details are still missing, in plain words.
     *
     * Surfaced in wp-admin rather than silently tolerated: a store that has not filled these in is
     * sending post-purchase mail that cannot tell the customer where to go.
     */
    public static function missing(): array {
        $missing = [];
        if ('' === self::name()) {
            $missing[] = __('business name', 'mps-gateway');
        }
        if ('' === self::email()) {
            $missing[] = __('support email address', 'mps-gateway');
        }
        if ('' === self::phone()) {
            $missing[] = __('support phone number', 'mps-gateway');
        }

        return $missing;
    }
}
