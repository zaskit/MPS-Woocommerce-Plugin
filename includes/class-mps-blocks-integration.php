<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MPS_Blocks_Integration extends AbstractPaymentMethodType {

    private MPS_Base_Gateway $gateway;

    public function __construct(MPS_Base_Gateway $gateway) {
        $this->gateway = $gateway;
        $this->name    = $gateway->id;
    }

    public function initialize(): void {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active(): bool {
        // v2.8.0: a gateway in the portal's "Test" state is not even registered for customers.
        // (The Store API's own availability list already leaves it out — this is belt and braces.)
        if (!empty($this->gateway->admin_only) && !MPS_Base_Gateway::current_user_is_store_admin()) {
            return false;
        }
        return ($this->settings['enabled'] ?? 'yes') === 'yes';
    }

    public function get_payment_method_script_handles(): array {
        // D-Processor: NMI Payment Component iframes instead of our own card inputs (v2.9.0). Its own
        // script and its own data prefix, so mps-blocks.js never draws plain card inputs for it.
        if ($this->gateway instanceof MPS_DProcessor) {
            $handle = 'mps-d-blocks-' . $this->name;
            MPS_DProcessor::register_scripts();
            wp_register_script($handle, plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/js/mps-d-blocks.js',
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'mps-dprocessor'], MPS_PLUGIN_VERSION, true);
            wp_localize_script($handle, 'mps_dblocks_data_' . $this->name, $this->get_payment_method_data() + $this->gateway->frontend_config());
            return [$handle];
        }

        $handle = 'mps-blocks-' . $this->name;

        wp_register_script(
            $handle,
            plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/js/mps-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            MPS_PLUGIN_VERSION,
            true
        );

        // Pass data as global JS variable (same approach as old plugin)
        wp_localize_script($handle, 'mps_blocks_data_' . $this->name, $this->get_payment_method_data());

        return [$handle];
    }

    public function get_payment_method_data(): array {
        $data = [
            'id'           => $this->name,
            // get_title()/get_description() so woocommerce_gateway_title and
            // woocommerce_gateway_description apply on the Block checkout too. Reading the raw
            // properties made those filters silently do nothing here while working on classic.
            'title'        => $this->gateway->get_title(),
            'description'  => $this->gateway->get_description() ?? '',
            'supports'     => ['products'],
            'icons'        => $this->get_icons(),
            // Optional charge-acknowledgment consent line (client 2026-07-22). Empty string hides
            // the tick-box entirely; <strong> around the descriptor is intentional.
            'ack_text'     => $this->charge_acknowledgment_text(),
            // Same disclosure markup classic renders, so the wording cannot drift between the
            // two checkouts. Empty string when the merchant has not enabled it.
            'disclosure'   => class_exists('MPS_Monitor_Ack') ? MPS_Monitor_Ack::disclosure_html($this->gateway) : '',
            // BINs this processor never approves, so the Block checkout can refuse the card as it
            // is typed. The server checks again before processing — see mps_block_blocked_bins().
            'blocked_bins' => $this->gateway->blocked_bins ?? [],
            // Default refusal wording, already resolved to the merchant's name server-side so the
            // Block checkout cannot render a different sentence from classic.
            'blocked_message' => class_exists('MPS_BIN_Blocker') ? MPS_BIN_Blocker::default_message() : '',
            // Card schemes this merchant accepts (portal allowed_cards). Block checkout had no
            // brand awareness at all before v2.5.9 — client or server.
            'allowed_cards' => method_exists($this->gateway, 'get_allowed_cards') ? $this->gateway->get_allowed_cards() : [],
            // Endpoint the JS polls after a failed payment to render the decline under the card fields.
            'rest_decline_url' => rest_url('mps/v1/last-decline'),
            // v2.8.0: the processor's ticket range + the two sentences, resolved server-side so the
            // Block checkout says exactly what classic says. See MPS_Base_Gateway::ticket_limit_message().
            'ticket_min'         => $this->gateway->ticket_min,
            'ticket_max'         => $this->gateway->ticket_max,
            'ticket_min_message' => $this->gateway->ticket_min !== null ? (string) $this->gateway->ticket_limit_message(max(0.01, $this->gateway->ticket_min - 0.01)) : '',
            'ticket_max_message' => $this->gateway->ticket_max !== null ? (string) $this->gateway->ticket_limit_message($this->gateway->ticket_max + 0.01) : '',
            'supports_3ds' => $this->gateway->supports_3ds,
            'has_fields'   => $this->gateway->has_fields,
        ];

        // Pass countries list for billing address dropdown (3DS only)
        if ($this->gateway->supports_3ds && function_exists('WC')) {
            $countries = [];
            foreach (WC()->countries->get_countries() as $code => $name) {
                $countries[] = ['code' => $code, 'name' => $name];
            }
            $data['countries']      = $countries;
            $data['defaultCountry'] = WC()->customer ? WC()->customer->get_billing_country() : '';
        }

        return $data;
    }

    private function get_icons(): array {
        // Checkout intentionally shows title + description only — no card-brand icons.
        return [];
    }

    /**
     * Consent wording for the Block checkout tick-box — mirrors the classic markup. No descriptor:
     * it is not shown publicly (client 2026-07-22); it appears only on the generated PDF.
     */
    private function charge_acknowledgment_text(): string {
        return esc_html__('I authorize this charge and confirm my details may be used for a charge acknowledgment.', 'mps-gateway');
    }
}
