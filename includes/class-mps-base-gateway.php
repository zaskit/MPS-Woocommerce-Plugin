<?php
defined('ABSPATH') || exit;

abstract class MPS_Base_Gateway extends WC_Payment_Gateway {

    protected int    $portal_gateway_id;
    protected array  $credentials;
    protected string $processor_code;
    protected string $processor_type;
    protected string $environment;
    protected array  $supported_cards;
    public    bool   $supports_3ds;
    protected string $fee_label;
    protected float  $fee_percentage;
    protected array  $allowed_cards;

    public function __construct(array $gateway_config) {
        $this->portal_gateway_id = (int) ($gateway_config['id'] ?? 0);
        $this->credentials       = $gateway_config['credentials'] ?? [];
        $this->processor_code    = $gateway_config['processor_code'] ?? '';
        $this->processor_type    = $gateway_config['processor_type'] ?? '';
        $this->environment       = $gateway_config['environment'] ?? 'sandbox';
        $this->supported_cards   = $gateway_config['supported_cards'] ?? [];
        $this->supports_3ds      = (bool) ($gateway_config['supports_3ds'] ?? false);
        $this->fee_percentage    = (float) ($gateway_config['fee_percentage'] ?? 0);
        $this->fee_label         = $gateway_config['fee_label'] ?? 'Processing Fee';
        $this->allowed_cards     = $gateway_config['allowed_cards'] ?? $this->supported_cards;

        // Gateway ID: mps_{code}_{type}_{portal_id}
        $this->id = 'mps_' . $this->processor_code . '_' . $this->processor_type . '_' . $this->portal_gateway_id;

        $this->has_fields = true;
        $this->supports   = ['products'];

        // Read global settings from the single MPS settings page
        $main_settings = get_option('woocommerce_mps_settings_settings', []);
        $global_enabled = ($main_settings['gateway_enabled'] ?? 'yes') === 'yes';

        $default_title = $gateway_config['display_name'] ?? 'Pay by Card';
        $default_desc  = $this->build_default_description();

        // Title + description from main settings (per-processor fields)
        $this->enabled     = $global_enabled ? 'yes' : 'no';
        $this->title       = !empty($main_settings['title_' . $this->id]) ? $main_settings['title_' . $this->id] : $default_title;
        $this->description = !empty($main_settings['desc_' . $this->id]) ? $main_settings['desc_' . $this->id] : $default_desc;
        $this->method_title       = 'MPS: ' . $default_title;
        $this->method_description = sprintf(
            '%s (%s) — %s',
            $default_title,
            implode(', ', array_map('ucfirst', $this->supported_cards)),
            ucfirst($this->environment)
        );

        // Default icon
        $this->icon = $this->get_card_icons_url();

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
        $prefix = esc_attr($this->id);
        $allowed = $this->get_allowed_cards();
        $mc_only = (count($allowed) === 1 && in_array('mastercard', $allowed));
        ?>
        <div class="mps-card-form" id="<?php echo $prefix; ?>-form">
            <div class="mps-field">
                <label>Cardholder Name</label>
                <input type="text" name="<?php echo $prefix; ?>_card_name" placeholder="Name on card" autocomplete="cc-name" required>
            </div>
            <div class="mps-field">
                <label>Card Number</label>
                <input type="text" name="<?php echo $prefix; ?>_card_number" inputmode="numeric" maxlength="23" placeholder="0000 0000 0000 0000" autocomplete="cc-number" data-mc-only="<?php echo $mc_only ? '1' : '0'; ?>" required>
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
            <?php if ($this->supports_3ds): ?>
            <div class="mps-billing-heading">Cardholder Billing Address</div>
            <div class="mps-field">
                <label>Street Address <span class="required">*</span></label>
                <input type="text" name="<?php echo $prefix; ?>_billing_street" autocomplete="address-line1" placeholder="Street address" required>
            </div>
            <div class="mps-row">
                <div class="mps-field">
                    <label>City <span class="required">*</span></label>
                    <input type="text" name="<?php echo $prefix; ?>_billing_city" autocomplete="address-level2" placeholder="City" required>
                </div>
                <div class="mps-field">
                    <label>State / Province <span class="required">*</span></label>
                    <input type="text" name="<?php echo $prefix; ?>_billing_state" autocomplete="address-level1" placeholder="e.g. MO, NY" maxlength="50" required>
                </div>
            </div>
            <div class="mps-row">
                <div class="mps-field">
                    <label>Country <span class="required">*</span></label>
                    <select name="<?php echo $prefix; ?>_billing_country" autocomplete="country" required>
                        <option value="">Select country&hellip;</option>
                        <?php
                        foreach (WC()->countries->get_countries() as $code => $name) {
                            $selected = (WC()->customer && WC()->customer->get_billing_country() === $code) ? ' selected' : '';
                            echo '<option value="' . esc_attr($code) . '"' . $selected . '>' . esc_html($name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="mps-field">
                    <label>ZIP / Postal Code <span class="required">*</span></label>
                    <input type="text" name="<?php echo $prefix; ?>_billing_zip" autocomplete="postal-code" placeholder="ZIP / Postal" maxlength="10" required>
                </div>
            </div>
            <?php endif; ?>
            <div class="mps-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption</span>
            </div>
        </div>
        <?php
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

        if (empty($card_name)) $errors[] = 'Cardholder name is required.';

        if (empty($card_number)) {
            $errors[] = 'Card number is required.';
        } elseif (strlen($card_number) < 13 || strlen($card_number) > 19) {
            $errors[] = 'Please enter a valid card number.';
        } elseif ($this->environment === 'live' && !$this->validate_card_brand($card_number)) {
            $brands = implode(' or ', array_map('ucfirst', $this->get_allowed_cards()));
            $errors[] = "Only {$brands} is accepted on this gateway.";
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

        if (empty($cvv) || strlen($cvv) < 3 || strlen($cvv) > 4) {
            $errors[] = 'Please enter a valid CVV (3 or 4 digits).';
        }

        // Cardholder billing address (3DS gateways only)
        if ($this->supports_3ds) {
            $b_street  = $this->post_field('billing_street');
            $b_city    = $this->post_field('billing_city');
            $b_country = $this->post_field('billing_country');
            $b_zip     = $this->post_field('billing_zip');

            if (empty($b_street)) $errors[] = 'Cardholder billing street address is required.';
            if (empty($b_city)) $errors[] = 'Cardholder billing city is required.';
            if (empty($b_country) || strlen($b_country) !== 2) $errors[] = 'Please select a valid billing country.';
            if (empty($b_zip)) $errors[] = 'Cardholder billing ZIP / postal code is required.';
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
        ];

        // Billing address fields only for 3DS gateways
        if ($this->supports_3ds) {
            $map['billing_street']  = $prefix . '_billing_street';
            $map['billing_city']    = $prefix . '_billing_city';
            $map['billing_state']   = $prefix . '_billing_state';
            $map['billing_country'] = $prefix . '_billing_country';
            $map['billing_zip']     = $prefix . '_billing_zip';
        }

        foreach ($map as $block_key => $post_key) {
            if (isset($pd[$block_key])) {
                $_POST[$post_key] = sanitize_text_field($pd[$block_key]);
            }
        }

    }

    protected function build_default_description(): string {
        $brands = array_map('ucfirst', $this->supported_cards);
        return 'Pay securely with your ' . implode(' or ', $brands) . '.';
    }

    protected function get_card_icons_url(): string {
        $icons = [];
        $base = plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/img/';
        foreach ($this->supported_cards as $brand) {
            if (in_array($brand, ['visa', 'mastercard'])) {
                $icons[] = $base . $brand . '.svg';
            }
        }
        return $icons[0] ?? '';
    }

    protected function log(string $message): void {
        MPS_Logger::debug($message, 'mps-' . $this->processor_code . $this->processor_type);
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error {
        return new \WP_Error('refund_not_available', 'Refunds are not yet supported for this gateway.');
    }
}
