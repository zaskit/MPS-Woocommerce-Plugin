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
            'title'        => $this->gateway->title,
            'description'  => $this->gateway->description ?? '',
            'supports'     => ['products'],
            'icons'        => $this->get_icons(),
            'supports_3ds' => $this->gateway->supports_3ds,
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
        $allowed = $this->gateway->get_allowed_cards();
        $icons = [];
        $base = plugin_dir_url(MPS_PLUGIN_FILE) . 'assets/img/';
        if (in_array('mastercard', $allowed)) {
            $icons[] = ['id' => 'mastercard', 'src' => $base . 'mastercard.svg', 'alt' => 'Mastercard'];
        }
        if (in_array('visa', $allowed)) {
            $icons[] = ['id' => 'visa', 'src' => $base . 'visa.svg', 'alt' => 'Visa'];
        }
        return $icons;
    }
}
