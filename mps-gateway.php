<?php
/**
 * Plugin Name: MPS Gateway
 * Description: Connect your WooCommerce store to MPS Gateway for multi-processor payment processing. Transactions go directly to processors; the portal manages configuration.
 * Version: 2.0.1
 * Author: ZASK
 * Author URI: https://zask.it
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

define('MPS_PLUGIN_FILE', __FILE__);
define('MPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPS_PLUGIN_VERSION', '2.0.3');

// HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) return;

    // Load includes
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-logger.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-portal-client.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-transaction-reporter.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-base-gateway.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-vprocessor-api.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-vprocessor-2d.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-vprocessor-3d.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-vprocessor-3d-webhook.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-eprocessor-api.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-eprocessor-2d.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-gateway-factory.php';

    // Register dynamic gateways with WooCommerce
    add_filter('woocommerce_payment_gateways', [MPS_Gateway_Factory::class, 'register']);

    // Hide individual MPS processor gateways from WC admin Payments settings list.
    // They remain registered for checkout — only MPS Gateway settings shows in admin.
    if (is_admin()) {
        add_filter('woocommerce_payment_gateways', function($gateways) {
            return array_filter($gateways, function($gw) {
                if (is_string($gw)) return true;
                return !($gw instanceof MPS_Base_Gateway);
            });
        }, 999);
    }

    // Register blocks support
    add_action('woocommerce_blocks_loaded', function() {
        require_once MPS_PLUGIN_DIR . 'includes/class-mps-blocks-integration.php';
        MPS_Gateway_Factory::register_blocks();
    });

    // ─── Admin Settings Page (single unified config) ───
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'MPS_Settings_Gateway';
        return $gateways;
    });

    class MPS_Settings_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'mps_settings';
            $this->method_title = 'MPS Gateway';
            $this->method_description = 'Multi-processor payment gateway. Processors are assigned and managed via the MPS Gateway portal.';
            $this->has_fields = false;
            $this->supports = [];
            $this->enabled = 'no'; // Not a real payment method

            $this->init_form_fields();
            $this->init_settings();

            $this->title = 'MPS Gateway';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void {
            $fields = [
                'gateway_enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable MPS Gateway',
                    'default' => 'yes',
                    'description' => 'Globally enable or disable all MPS payment methods at checkout.',
                ],
                'api_key' => [
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'Your MPS Gateway portal API key.',
                    'desc_tip' => true,
                ],
                'api_secret' => [
                    'title' => 'API Secret',
                    'type' => 'password',
                    'description' => 'Your MPS Gateway portal API secret.',
                    'desc_tip' => true,
                ],
                'debug' => [
                    'title' => 'Debug Log',
                    'type' => 'checkbox',
                    'label' => 'Enable debug logging',
                    'default' => 'no',
                    'description' => 'Logs to WooCommerce > Status > Logs (mps-*). Sensitive data is automatically redacted.',
                ],
            ];

            // Dynamic title + description fields for each active processor
            $gateways = MPS_Portal_Client::get_gateways();
            if (!empty($gateways)) {
                $fields['processor_heading'] = [
                    'title' => 'Active Processors',
                    'type'  => 'title',
                    'description' => 'Customize the checkout title and description for each assigned processor.',
                ];

                foreach ($gateways as $gw) {
                    $gw_id   = 'mps_' . ($gw['processor_code'] ?? '') . '_' . ($gw['processor_type'] ?? '') . '_' . ($gw['id'] ?? 0);
                    $name    = $gw['display_name'] ?? 'Unknown';
                    $env     = strtoupper($gw['environment'] ?? 'sandbox');
                    $cards   = implode(', ', array_map('ucfirst', $gw['supported_cards'] ?? []));
                    $threeds = !empty($gw['supports_3ds']) ? ' | 3D-Secure' : '';

                    $fields['title_' . $gw_id] = [
                        'title'       => $name . ' — Title',
                        'type'        => 'text',
                        'description' => $cards . ' | ' . $env . $threeds,
                        'default'     => $name,
                    ];
                    $fields['desc_' . $gw_id] = [
                        'title'       => $name . ' — Description',
                        'type'        => 'textarea',
                        'default'     => 'Pay securely with your ' . implode(' or ', array_map('ucfirst', $gw['supported_cards'] ?? [])) . '.',
                        'css'         => 'max-width:400px;',
                    ];
                }
            }

            $this->form_fields = $fields;
        }

        public function admin_options(): void {
            parent::admin_options();

            $nonce = wp_create_nonce('mps_admin');
            echo '<table class="form-table"><tr><th>Connection</th><td>';
            echo '<button type="button" id="mps-test-btn" class="button button-secondary">Test Connection</button>';
            echo ' <button type="button" id="mps-refresh-btn" class="button">Refresh Gateways</button>';
            echo ' <span id="mps-test-result" style="margin-left:10px;"></span>';
            echo '<script>
            jQuery("#mps-test-btn").on("click",function(){
                var b=jQuery(this),r=jQuery("#mps-test-result");
                b.prop("disabled",true).text("Testing...");r.text("").css("color","");
                jQuery.post(ajaxurl,{action:"mps_test_connection",_wpnonce:"' . $nonce . '"},function(d){
                    b.prop("disabled",false).text("Test Connection");
                    if(d.success){r.text("Connected: "+d.data.merchant+" — "+d.data.gateways+" gateway(s)").css("color","green");}
                    else{r.text("Failed: "+(d.data||"Unknown")).css("color","red");}
                }).fail(function(){b.prop("disabled",false).text("Test Connection");r.text("Request failed").css("color","red");});
            });
            jQuery("#mps-refresh-btn").on("click",function(){
                var b=jQuery(this),r=jQuery("#mps-test-result");
                b.prop("disabled",true).text("Refreshing...");r.text("").css("color","");
                jQuery.post(ajaxurl,{action:"mps_refresh_gateways",_wpnonce:"' . $nonce . '"},function(d){
                    b.prop("disabled",false).text("Refresh Gateways");
                    if(d.success){r.text("Refreshed: "+d.data.count+" gateway(s) loaded").css("color","green");location.reload();}
                    else{r.text("Failed: "+(d.data||"Unknown")).css("color","red");}
                }).fail(function(){b.prop("disabled",false).text("Refresh Gateways");r.text("Request failed").css("color","red");});
            });
            </script>';
            echo '</td></tr></table>';
        }

        public function is_available(): bool {
            return false; // Never show at checkout
        }
    }

    // ─── Admin AJAX Handlers ───
    add_action('wp_ajax_mps_test_connection', function() {
        check_ajax_referer('mps_admin');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $result = MPS_Portal_Client::ping();
        if (!empty($result['success'])) {
            wp_send_json_success([
                'merchant' => $result['merchant'] ?? 'Unknown',
                'gateways' => $result['active_gateways'] ?? 0,
            ]);
        }
        wp_send_json_error($result['error'] ?? $result['message'] ?? 'Connection failed');
    });

    add_action('wp_ajax_mps_refresh_gateways', function() {
        check_ajax_referer('mps_admin');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $gateways = MPS_Portal_Client::refresh();
        wp_send_json_success(['count' => count($gateways)]);
    });

    // ─── Frontend Assets ───
    add_action('wp_enqueue_scripts', function() {
        if (!is_checkout() && !is_cart() && !has_block('woocommerce/checkout') && !has_block('woocommerce/cart')) return;

        wp_enqueue_style('mps-checkout-css', plugin_dir_url(__FILE__) . 'assets/css/mps-checkout.css', [], MPS_PLUGIN_VERSION);
        wp_enqueue_script('mps-card-formatting', plugin_dir_url(__FILE__) . 'assets/js/mps-card-formatting.js', ['jquery'], MPS_PLUGIN_VERSION, true);
    });

    // Polling JS on thank-you page
    add_action('wp_enqueue_scripts', function() {
        if (!is_wc_endpoint_url('order-received')) return;

        $params = $_GET;
        if (!empty($params['mps_vp3d_poll']) || !empty($params['mps_ep2d_poll'])) {
            wp_enqueue_script('mps-polling', plugin_dir_url(__FILE__) . 'assets/js/mps-polling.js', [], MPS_PLUGIN_VERSION, true);
        }
    });

    // ─── VP3D Webhook & 3DS Return Endpoints ───
    add_action('woocommerce_api_mps_vsafe_webhook', [MPS_VProcessor_3D_Webhook::class, 'handle']);
    add_action('woocommerce_api_mps_vsafe_3ds_return', [MPS_VProcessor_3D_Webhook::class, 'handle_3ds_return']);

    // ─── EP2D Callback & Return Endpoints ───
    add_action('init', function() {
        add_rewrite_endpoint('mps-eupaymentz-callback', EP_ROOT);
        add_rewrite_endpoint('mps-eupaymentz-return', EP_ROOT);
    });

    add_action('template_redirect', function() {
        global $wp_query;

        // EP2D Callback (async POST from processor)
        if (isset($wp_query->query_vars['mps-eupaymentz-callback'])) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (empty($data)) $data = $_POST;

            MPS_Logger::debug('EP2D Callback received: ' . ($raw ?: wp_json_encode($data)), 'mps-ep2d');

            $order_id = (int) ($data['resp_merchant_data1'] ?? 0);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $payment_method = $order->get_payment_method();
                    $gateway = MPS_Gateway_Factory::find($payment_method);
                    if ($gateway && $gateway instanceof MPS_EProcessor_2D) {
                        $gateway->process_callback($data);
                    }
                }
            }

            status_header(200);
            echo 'OK';
            exit;
        }

        // EP2D Return (customer redirected back)
        if (isset($wp_query->query_vars['mps-eupaymentz-return'])) {
            $data = $_REQUEST;
            MPS_Logger::debug('EP2D Return: ' . wp_json_encode($data), 'mps-ep2d');

            $order_id = (int) ($data['order_id'] ?? 0);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $payment_method = $order->get_payment_method();
                    $gateway = MPS_Gateway_Factory::find($payment_method);
                    if ($gateway && $gateway instanceof MPS_EProcessor_2D) {
                        $gateway->process_return($data);
                        return; // process_return handles redirect
                    }
                }
            }

            wp_redirect(wc_get_checkout_url());
            exit;
        }
    });
});

// ─── Flush rewrite rules on activation ───
register_activation_hook(__FILE__, function() {
    add_rewrite_endpoint('mps-eupaymentz-callback', EP_ROOT);
    add_rewrite_endpoint('mps-eupaymentz-return', EP_ROOT);
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
