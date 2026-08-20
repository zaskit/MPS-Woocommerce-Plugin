<?php
defined('ABSPATH') || exit;

abstract class MPS_Base_Gateway extends WC_Payment_Gateway {

    protected int    $portal_gateway_id;
    public    array  $credentials;
    protected string $processor_code;
    protected string $processor_type;
    protected string $environment;
    protected array  $supported_cards;
    public    bool   $supports_3ds;
    public    string $fee_label;
    public    float  $fee_percentage;
    protected array  $allowed_cards;
    public    string $portal_descriptor;
    /** BINs this processor will never approve, longest-first, from the portal. */
    public    array  $blocked_bins;

    public function __construct(array $gateway_config) {
        $this->portal_gateway_id = (int) ($gateway_config['id'] ?? 0);
        $this->credentials       = $gateway_config['credentials'] ?? [];
        $this->processor_code    = $gateway_config['processor_code'] ?? '';
        $this->processor_type    = $gateway_config['processor_type'] ?? '';
        $this->environment       = $gateway_config['environment'] ?? 'sandbox';
        $this->supported_cards   = $gateway_config['supported_cards'] ?? [];
        $this->supports_3ds      = (bool) ($gateway_config['supports_3ds'] ?? false);
        $this->fee_percentage    = (float) ($gateway_config['fee_percentage'] ?? 0);
        $this->fee_label         = $gateway_config['fee_label'] ?? 'Handling Fee';
        $this->allowed_cards     = $gateway_config['allowed_cards'] ?? $this->supported_cards;
        $this->portal_descriptor = $gateway_config['descriptor'] ?? '';
        $this->blocked_bins      = is_array($gateway_config['blocked_bins'] ?? null) ? $gateway_config['blocked_bins'] : [];

        // Gateway ID: mps_{code}_{type}_{portal_id}
        $this->id = 'mps_' . $this->processor_code . '_' . $this->processor_type . '_' . $this->portal_gateway_id;

        $this->has_fields = true;
        $this->supports   = ['products'];

        // Read global settings from the single MPS settings page
        $main_settings = get_option('woocommerce_mps_settings_settings', []);
        // v2.3.2 FIX: read the WC-standard 'enabled' key. v2.2.3 migrated 'gateway_enabled' → 'enabled'
        // and deleted the old key, so this read always fell back to 'yes' — the "MPS Gateway" global
        // Enable/Disable toggle had NO effect on the dynamic checkout gateways (appeared locked ON).
        // Fall back to the legacy key for any not-yet-migrated install.
        $global_enabled = ($main_settings['enabled'] ?? $main_settings['gateway_enabled'] ?? 'yes') === 'yes';

        $default_title = $this->build_default_title();
        $default_desc  = $this->build_default_description();

        // Title + description from main settings (per-processor fields)
        $this->enabled     = $global_enabled ? 'yes' : 'no';
        // Honor whatever the merchant saved in MPS Gateway Settings for this
        // processor. Only fall back to the generated default when the field is
        // empty. (The settings-form field default is computed from the SAME
        // build_default_*() methods, so an untouched field and the checkout stay
        // in sync — see MPS_Settings_Gateway::init_form_fields().)
        $stored_title = $main_settings['title_' . $this->id] ?? '';
        $stored_desc  = $main_settings['desc_' . $this->id] ?? '';
        $this->title       = ($stored_title !== '') ? $stored_title : $default_title;
        $this->description = ($stored_desc  !== '') ? $stored_desc  : $default_desc;
        // Leave method_title and method_description empty so WC treats these as "shell" gateways.
        // WC hides shells from the admin Payments list when a non-shell gateway (MPS_Settings_Gateway)
        // exists from the same plugin. The individual processors still appear at checkout.
        $this->method_title       = '';
        $this->method_description = '';

        // No card-brand icon next to the checkout title (client 2026-07-22) — title and
        // description only, on both classic and Block checkout.
        $this->icon = '';

        // Blocks checkout: map payment_data to $_POST before process_payment() runs
        add_action('woocommerce_rest_checkout_process_payment_with_context', [$this, 'bridge_block_payment_data'], 10, 2);
    }

    /**
     * No individual settings page — all config is in MPS Gateway Settings.
     * Hide this gateway from WC Payments settings list.
     */
    public function init_form_fields(): void {
        $this->form_fields = [];
    }

    public function admin_options(): void {
        echo '<p>This payment method is managed via <strong>MPS Gateway</strong> settings. ';
        echo '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mps_settings') . '">Go to MPS Gateway Settings</a></p>';
    }

    /**
     * Render card input fields + cardholder billing address on checkout.
     * Matches Merchant Payment Gateway plugin design.
     */
    public function payment_fields(): void {
        // v2.3.4: echo the gateway description under the title. Classic checkout renders ONLY
        // payment_fields() for a has_fields gateway (WC's default payment_fields — which prints the
        // description — is overridden here for the card form), so without this the description never
        // showed on classic checkout (e.g. SciLife/Elementor). Block checkout gets it separately.
        if ($this->description) {
            echo wpautop( wptexturize( $this->description ) );
        }
        $prefix = esc_attr($this->id);
        $allowed = $this->get_allowed_cards();
        $mc_only = (count($allowed) === 1 && in_array('mastercard', $allowed));
        $decline = MPS_Decline_Codes::consume();
        ?>
        <div class="mps-card-form" id="<?php echo $prefix; ?>-form">
            <div class="mps-field">
                <label>Cardholder Name</label>
                <input type="text" name="<?php echo $prefix; ?>_card_name" placeholder="Name on card" autocomplete="cc-name" required>
            </div>
            <div class="mps-field">
                <label>Card Number</label>
                <?php /* data-blocked-bins lets the browser refuse a known-bad card as it is typed,
                          with no request. It is only a BIN list — no customer data — and the server
                          checks again in validate_fields() regardless, so a stripped or broken
                          script costs nothing but the instant feedback. */ ?>
                <input type="text" name="<?php echo $prefix; ?>_card_number" inputmode="numeric" maxlength="23" placeholder="0000 0000 0000 0000" autocomplete="cc-number" data-mc-only="<?php echo $mc_only ? '1' : '0'; ?>" data-allowed-cards="<?php echo esc_attr(wp_json_encode(array_values($allowed))); ?>" data-blocked-bins="<?php echo esc_attr(wp_json_encode($this->blocked_bins)); ?>" required>
                <div class="mps-bin-blocked" role="alert" style="display:none"></div>
            </div>
            <div class="mps-row">
                <div class="mps-field">
                    <label>Expiry</label>
                    <input type="text" name="<?php echo $prefix; ?>_card_expiry" maxlength="7" inputmode="numeric" placeholder="MM / YY" autocomplete="cc-exp" required>
                </div>
                <div class="mps-field">
                    <label>CVC</label>
                    <input type="text" name="<?php echo $prefix; ?>_card_cvv" maxlength="4" inputmode="numeric" placeholder="&bull;&bull;&bull;" autocomplete="cc-csc" required>
                </div>
            </div>
            <?php // Billing address fields removed — EP3D uses WC checkout billing details ?>

            <?php if ($decline) : ?>
                <?php /* The last decline, shown RIGHT UNDER the card fields so the customer cannot
                          miss it (client 2026-07-22). A "do not retry" decline is styled red and
                          final; a retryable one is amber. */ ?>
                <div class="mps-decline-notice <?php echo $decline['final'] ? 'mps-decline-final' : 'mps-decline-retry'; ?>" role="alert">
                    <?php echo esc_html($decline['message']); ?>
                </div>
            <?php endif; ?>

            <?php $this->render_charge_acknowledgment_field(); ?>

            <div class="mps-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </div>
        <?php
    }

    /**
     * Cardholder Charge Acknowledgment tick-box. We capture the cardholder's confirmation that they
     * authorized this charge, and the portal turns it into a signed PDF the merchant can submit if
     * the charge is ever disputed.
     *
     * v2.5.6 (client 2026-07-29): MANDATORY, not optional. Ticked by default and it cannot be
     * cleared — attempting to clear it re-ticks the box and explains why (assets/js/mps-card-
     * formatting.js on classic, assets/js/mps-blocks.js on Block). `required` is the no-JS backstop:
     * the browser refuses to submit the form with the box clear.
     *
     * Consent is still only recorded when the value is actually posted. A customer who defeats both
     * the script and the browser validation has visibly refused, and we must not record consent they
     * did not give — the PDF is dispute evidence and has to be truthful.
     *
     * The billing descriptor is deliberately NOT shown here (client 2026-07-22) — the descriptor is
     * not for public display. It still appears on the generated PDF, where the form requires it as
     * the Merchant / Business Name.
     */
    public function render_charge_acknowledgment_field(): void {
        $prefix = esc_attr($this->id);
        ?>
        <div class="mps-ack-field">
            <?php
            /* Card-payment disclosure, when the merchant has enabled it. Deliberately INSIDE
               .mps-ack-field and above the label: the customer should read what the payment
               involves before ticking the box that says they authorize it. Same string feeds
               Block checkout via MPS_Blocks_Integration, so the two cannot drift apart. */
            if (class_exists('MPS_Monitor_Ack')) {
                echo MPS_Monitor_Ack::disclosure_html($this); // phpcs:ignore WordPress.Security.EscapeOutput
            }
            ?>
            <label class="mps-ack-label">
                <input type="checkbox" name="<?php echo $prefix; ?>_charge_ack" value="1" class="mps-ack-checkbox" checked required>
                <span class="mps-ack-text">
                    <?php esc_html_e('I authorize this charge and confirm my details may be used for a charge acknowledgment.', 'mps-gateway'); ?>
                </span>
            </label>
            <?php /* Inline display:none, not the hidden attribute — a theme rule setting display on
                     this element would otherwise override [hidden] and leak the message. */ ?>
            <div class="mps-ack-required" role="alert" style="display:none">
                <?php esc_html_e('This acknowledgment is required to complete your payment.', 'mps-gateway'); ?>
            </div>
        </div>
        <?php
    }

    /** Did the customer tick the acknowledgment box? Handles classic and block field names. */
    public function charge_acknowledgment_accepted(): bool {
        $key = $this->id . '_charge_ack';
        $val = $_POST[$key] ?? $_POST['charge_ack'] ?? '';

        return in_array((string) $val, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get allowed card brands for this gateway (from portal config).
     */
    public function get_allowed_cards(): array {
        return $this->allowed_cards ?? $this->supported_cards ?? ['mastercard', 'visa'];
    }

    public function validate_fields(): bool {
        $prefix = $this->id;
        $errors = [];

        // Card fields — check prefixed key (classic checkout) then unprefixed (blocks checkout)
        $card_name = $this->post_field('card_name');
        $card_number = preg_replace('/\D/', '', $this->post_field('card_number'));
        $expiry = $this->post_field('card_expiry');
        $cvv = preg_replace('/\D/', '', $this->post_field('card_cvv'));

        // Blocked BIN first: if this card can never be approved, telling the customer that is more
        // useful than telling them the CVV is missing, and there is no point validating the rest.
        $blocked = MPS_BIN_Blocker::match($this->blocked_bins, $card_number);
        if ($blocked) {
            MPS_BIN_Blocker::log($this->id, $blocked['bin']);
            wc_add_notice($blocked['message'], 'error');
            return false;
        }

        if (empty($card_name)) $errors[] = 'Cardholder name is required.';

        // Scheme + length + Luhn, before anything is sent. A number that fails these cannot be
        // approved by anyone, and letting it through costs us a Format Error on the MID and reads
        // to the customer as a decline. @see MPS_Card_Validator
        $card_error = MPS_Card_Validator::error($card_number);
        if ($card_error) {
            $errors[] = $card_error;
        } else {
            // Scheme allow-list, from the portal. No longer skipped outside live: a sandbox that
            // accepts brands the live MID refuses is a test bed that lies about the thing being
            // tested — and an Amex on a Visa/Mastercard MID never approves anywhere.
            $brand_error = MPS_Card_Validator::brand_error($card_number, $this->get_allowed_cards());
            if ($brand_error) {
                $errors[] = $brand_error;
            }
        }

        // Expiry
        $expiry_digits = preg_replace('/\D/', '', $expiry);
        if (strlen($expiry_digits) !== 4) {
            $errors[] = 'Please enter a valid expiry date (MM/YY).';
        } else {
            $month = (int) substr($expiry_digits, 0, 2);
            $year = (int) substr($expiry_digits, 2, 2);
            if ($month < 1 || $month > 12) {
                $errors[] = 'Please enter a valid expiry month (01-12).';
            } else {
                $now_month = (int) gmdate('n');
                $now_year = (int) gmdate('y');
                if ($year < $now_year || ($year === $now_year && $month < $now_month)) {
                    $errors[] = 'Your card has expired.';
                }
            }
        }

        // CVV length is scheme-specific (Amex 4, the rest 3) but stays permissive when the scheme
        // is unknown — see MPS_Card_Validator::cvv_error().
        $cvv_error = MPS_Card_Validator::cvv_error($cvv, $card_number);
        if ($cvv_error) {
            $errors[] = $cvv_error;
        }

        foreach ($errors as $err) {
            wc_add_notice($err, 'error');
        }

        return empty($errors);
    }

    /**
     * Check if card number matches allowed brands.
     */
    protected function validate_card_brand(string $card_digits): bool {
        $first_digit = (int) $card_digits[0];
        $first_two   = (int) substr($card_digits, 0, 2);
        $first_four  = (int) substr($card_digits, 0, 4);

        $is_visa       = $first_digit === 4;
        $is_mastercard = ($first_two >= 51 && $first_two <= 55) || ($first_four >= 2221 && $first_four <= 2720);

        $allowed = $this->get_allowed_cards();
        foreach ($allowed as $brand) {
            if ($brand === 'visa' && $is_visa) return true;
            if ($brand === 'mastercard' && $is_mastercard) return true;
        }

        return false;
    }

    /**
     * Detect card brand from number.
     */
    protected function detect_card_brand(string $card_digits): string {
        $first_digit = (int) $card_digits[0];
        $first_two   = (int) substr($card_digits, 0, 2);
        $first_four  = (int) substr($card_digits, 0, 4);

        if ($first_digit === 4) return 'visa';
        if (($first_two >= 51 && $first_two <= 55) || ($first_four >= 2221 && $first_four <= 2720)) return 'mastercard';
        if ($first_two === 34 || $first_two === 37) return 'amex';
        return 'unknown';
    }

    /**
     * Extract card data from POST.
     */
    protected function get_card_data(): array {
        $expiry = $this->post_field('card_expiry');
        $parts  = explode('/', $expiry);

        return [
            'name'      => $this->post_field('card_name'),
            'number'    => preg_replace('/\D/', '', $this->post_field('card_number')),
            'exp_month' => trim($parts[0] ?? ''),
            'exp_year'  => trim($parts[1] ?? ''),
            'cvv'       => $this->post_field('card_cvv'),
        ];
    }

    /**
     * Extract cardholder billing address from POST (separate from WC billing, 3DS only).
     */
    protected function get_cardholder_billing(): array {
        $state_raw = $this->post_field('billing_state');
        return [
            'street'  => $this->post_field('billing_street'),
            'city'    => $this->post_field('billing_city'),
            'state'   => !empty($state_raw) ? strtoupper(substr($state_raw, 0, 2)) : 'NA',
            'country' => $this->post_field('billing_country'),
            'zipCode' => $this->post_field('billing_zip'),
        ];
    }

    /**
     * Read a payment field from POST. Checks prefixed key (classic checkout)
     * then unprefixed key (WC Blocks checkout).
     */
    protected function post_field(string $field): string {
        $prefixed = $this->id . '_' . $field;
        if (!empty($_POST[$prefixed])) {
            return sanitize_text_field($_POST[$prefixed]);
        }
        if (!empty($_POST[$field])) {
            return sanitize_text_field($_POST[$field]);
        }
        return '';
    }

    /**
     * Store common order meta after a transaction attempt.
     */
    protected function store_order_meta(WC_Order $order, array $extra = []): void {
        $order->update_meta_data('_mps_portal_gateway_id', $this->portal_gateway_id);
        $order->update_meta_data('_mps_processor_code', $this->processor_code);
        $order->update_meta_data('_mps_processor_type', $this->processor_type);
        foreach ($extra as $key => $value) {
            $order->update_meta_data($key, $value);
        }
        $order->save();
    }

    /**
     * Report transaction result to portal.
     */
    protected function report_to_portal(WC_Order $order, string $status, array $extra = []): void {
        MPS_Transaction_Reporter::report($order, array_merge(['status' => $status], $extra));
    }

    /**
     * Bridge blocks checkout payment data into $_POST so process_payment() works unchanged.
     * Called via woocommerce_rest_checkout_process_payment_with_context hook.
     */
    public function bridge_block_payment_data($context, &$result): void {
        if ($context->payment_method !== $this->id) return;

        $pd = $context->payment_data ?? [];
        $prefix = $this->id;

        $map = [
            'card_name'   => $prefix . '_card_name',
            'card_number' => $prefix . '_card_number',
            'card_expiry' => $prefix . '_card_expiry',
            'card_cvv'    => $prefix . '_card_cvv',
            // Charge-acknowledgment tick-box; bridged unprefixed too so the central capture hook
            // sees it regardless of which gateway rendered the form.
            'charge_ack'  => $prefix . '_charge_ack',
        ];

        foreach ($map as $block_key => $post_key) {
            if (isset($pd[$block_key])) {
                $_POST[$post_key] = sanitize_text_field($pd[$block_key]);
                if ($block_key === 'charge_ack') {
                    $_POST['charge_ack'] = $_POST[$post_key];
                }
            }
        }

        // Record the charge acknowledgment HERE rather than leaving it to the central capture hook.
        // On Block checkout the order is built from the request BEFORE this filter runs, so by the
        // time mps_capture_charge_acknowledgment() fires the tick-box value does not exist yet — and
        // Store API requests carry JSON, not $_POST, so it would never see it anyway. This is the
        // first point where the order and the payment data are both in hand.
        $order = $context->order ?? null;
        if ($order instanceof WC_Order && in_array((string) ($pd['charge_ack'] ?? ''), ['1', 'true', 'yes', 'on'], true)) {
            $order->update_meta_data('_mps_charge_ack_accepted', 'yes');
            $order->update_meta_data('_mps_charge_ack_at', gmdate('Y-m-d H:i:s'));
            $order->update_meta_data('_mps_charge_ack_ip', $order->get_customer_ip_address());
            $order->save();
        }
    }

    /**
     * Default checkout copy. Deliberately brand-neutral (client 2026-07-22): the old defaults spelled
     * out "V I S A & M A S T E R C A R D", which the client wants gone from the title along with the
     * brand icons. A merchant can still set their own wording — a saved value always wins.
     */
    public function build_default_title(): string {
        return 'Pay with Card';
    }

    public function build_default_description(): string {
        return 'Pay securely with your credit or debit card.';
    }

    /**
     * The auto-generated copy we have shipped over time. Used ONCE on upgrade to replace a stored
     * value the merchant never actually chose (see mps_migrate_legacy_checkout_copy) — a genuine
     * custom title is never in this list and is left alone.
     */
    public static function legacy_auto_copy(): array {
        $legacy = [
            'Pay securely via V I S A & M A S T E R C A R D',
            'Pay securely via M A S T E R C A R D | NO V I S A',
            'Pay securely with Credit/Debit Card',
            'You will be redirected to a secure page to complete your card payment.',
        ];
        foreach ([['Visa', 'Mastercard'], ['Mastercard', 'Visa'], ['Visa'], ['Mastercard'],
                  ['Visa', 'Mastercard', 'Amex'], ['Amex'], ['Discover']] as $combo) {
            $legacy[] = 'Pay securely with your ' . implode(' or ', $combo) . '.';
        }

        return $legacy;
    }

    protected function get_card_icons_url(): string {
        $icons = [];
        $base = plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/img/';
        foreach ($this->get_allowed_cards() as $brand) {
            if (in_array($brand, ['visa', 'mastercard'])) {
                $icons[] = $base . $brand . '.svg';
            }
        }
        return $icons[0] ?? '';
    }

    /** No brand icons at checkout (client 2026-07-22). Filter kept so a theme can still add its own. */
    public function get_icon(): string {
        return apply_filters('woocommerce_gateway_icon', '', $this->id);
    }

    protected function log(string $message): void {
        MPS_Logger::debug($message, 'mps-' . $this->processor_code . $this->processor_type);
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        return new \WP_Error('refund_not_available', 'Refunds are not yet supported for this gateway.');
    }
}
