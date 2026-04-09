<?php
defined('ABSPATH') || exit;

class MPS_Gateway_Factory {

    private static array $instances = [];

    /**
     * Map processor_code + processor_type to gateway class.
     */
    private static array $class_map = [
        'v_2d' => 'MPS_VProcessor_2D',
        'v_3d' => 'MPS_VProcessor_3D',
        'e_2d' => 'MPS_EProcessor_2D',
        'e_3d' => 'MPS_EProcessor_3D',
        'e_hosted' => 'MPS_EProcessor_Hosted',
        'k_2d' => 'MPS_KProcessor',
        'k_3d' => 'MPS_KProcessor',
    ];

    /**
     * Build gateway instances from portal config.
     */
    public static function build(): array {
        if (!empty(self::$instances)) {
            return self::$instances;
        }

        $gateways = MPS_Portal_Client::get_gateways();
        if (empty($gateways)) return [];

        foreach ($gateways as $gw) {
            $key = ($gw['processor_code'] ?? '') . '_' . ($gw['processor_type'] ?? '');
            $class = self::$class_map[$key] ?? null;

            if (!$class || !class_exists($class)) {
                MPS_Logger::debug("No handler for processor type: {$key}");
                continue;
            }

            try {
                self::$instances[] = new $class($gw);
            } catch (\Exception $e) {
                MPS_Logger::error("Failed to create gateway {$key}: " . $e->getMessage());
            }
        }

        return self::$instances;
    }

    /**
     * Register all gateway instances with WooCommerce.
     */
    public static function register(array $wc_gateways): array {
        foreach (self::build() as $instance) {
            $wc_gateways[] = $instance;
        }
        return $wc_gateways;
    }

    /**
     * Register blocks support for all gateway instances.
     */
    public static function register_blocks(): void {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;

        add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
            foreach (self::build() as $instance) {
                $registry->register(new MPS_Blocks_Integration($instance));
            }
        });
    }

    /**
     * Find a gateway instance by its WC gateway ID.
     */
    public static function find(string $gateway_id): ?MPS_Base_Gateway {
        foreach (self::build() as $instance) {
            if ($instance->id === $gateway_id) {
                return $instance;
            }
        }
        return null;
    }
}
