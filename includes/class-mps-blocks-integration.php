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
        return ($this->settings['enabled'] ?? 'yes') === 'yes';
    }

    public function get_payment_method_script_handles(): array {
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
            // Endpoint the JS polls after a failed payment to render the decline under the card fields.
            'rest_decline_url' => rest_url('mps/v1/last-decline'),
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
        return esc_html__('I authorise this charge and confirm my details may be used for a charge acknowledgment.', 'mps-gateway');
    }
}
