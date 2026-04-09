<?php
/**
 * Plugin Name: MPS Gateway
 * Description: Connect your WooCommerce store to MPS Gateway for multi-processor payment processing. Transactions go directly to processors; the portal manages configuration.
 * Version: 2.1.0
 * Author: ZASK
 * Author URI: https://zask.it
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

define('MPS_PLUGIN_FILE', __FILE__);
define('MPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPS_PLUGIN_VERSION', '2.1.0');

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
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-eprocessor-3d.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-eprocessor-hosted.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-kprocessor.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-gateway-factory.php';

    // Register dynamic gateways with WooCommerce
    add_filter('woocommerce_payment_gateways', [MPS_Gateway_Factory::class, 'register']);

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
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'flush_caches_on_save'], 20);
        }

        /**
         * After settings save, flush the gateway transient + retry queue.
         * Ensures a portal mode switch takes effect immediately and stale
         * queued reports don't get pushed to the wrong portal.
         */
        public function flush_caches_on_save(): void {
            delete_transient('mps_gateway_config');
            delete_option('mps_transaction_retry_queue');
            // Clear both per-mode fallback caches + legacy unnamespaced one
            delete_option('mps_gateway_config_fallback');
            delete_option('mps_gateway_config_fallback_live');
            delete_option('mps_gateway_config_fallback_staging');
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
                'portal_mode' => [
                    'title'   => 'Portal Mode',
                    'type'    => 'select',
                    'default' => 'live',
                    'options' => [
                        'live'    => 'Live (mpsgateway.com)',
                        'staging' => 'Staging (staging.mpsgateway.com)',
                    ],
                    'description' => 'Which MPS Gateway portal this site reads processor config from and reports transactions to. Switch to Staging for testing — gateway cache is flushed on save.',
                    'desc_tip' => false,
                ],
                'api_key' => [
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'Your MPS Gateway portal API key (must match the selected portal mode).',
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
                    'title' => 'Active Processors (' . count($gateways) . ')',
                    'type'  => 'title',
                    'description' => 'Customize the checkout title, description, and fees for each assigned processor.',
                ];

                foreach ($gateways as $idx => $gw) {
                    $gw_id   = 'mps_' . ($gw['processor_code'] ?? '') . '_' . ($gw['processor_type'] ?? '') . '_' . ($gw['id'] ?? 0);
                    $name    = $gw['display_name'] ?? 'Unknown';
                    $env     = strtoupper($gw['environment'] ?? 'sandbox');
                    $cards   = implode(', ', array_map('ucfirst', $gw['supported_cards'] ?? []));
                    $threeds = !empty($gw['supports_3ds']) ? '3D-Secure' : 'Direct';
                    $is_redirect = ($gw['processor_code'] === 'k' || $gw['processor_type'] === 'hosted');

                    // Type badge color
                    $code = $gw['processor_code'] ?? '';
                    if ($code === 'v') $badge_color = '#3b82f6';
                    elseif ($code === 'e') $badge_color = '#8b5cf6';
                    elseif ($code === 'k') $badge_color = '#10b981';
                    else $badge_color = '#6b7280';

                    $env_badge = $env === 'LIVE'
                        ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">LIVE</span>'
                        : '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">SANDBOX</span>';

                    $flow_badge = $is_redirect
                        ? '<span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;">Redirect</span>'
                        : '<span style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:11px;">' . $threeds . '</span>';

                    // Section header per processor
                    $fields['section_' . $gw_id] = [
                        'title' => '<span style="display:inline-flex;align-items:center;gap:8px;">'
                            . '<span style="background:' . $badge_color . ';color:#fff;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.5px;">' . esc_html(strtoupper($code)) . '</span>'
                            . '<span style="font-size:15px;font-weight:600;">' . esc_html($name) . '</span>'
                            . $env_badge . $flow_badge
                            . '<span style="color:#9ca3af;font-size:12px;">' . esc_html($cards) . '</span>'
                            . '</span>',
                        'type'  => 'title',
                        'description' => '',
                    ];

                    $fields['title_' . $gw_id] = [
                        'title'       => 'Checkout Title',
                        'type'        => 'text',
                        'default'     => $name,
                        'css'         => 'max-width:350px;',
                    ];
                    $fields['desc_' . $gw_id] = [
                        'title'       => 'Checkout Description',
                        'type'        => 'textarea',
                        'default'     => 'Pay securely with your ' . implode(' or ', array_map('ucfirst', $gw['supported_cards'] ?? [])) . '.',
                        'css'         => 'max-width:400px;height:60px;',
                    ];
                    $fields['fee_pct_' . $gw_id] = [
                        'title'       => 'Fee %',
                        'type'        => 'text',
                        'description' => 'Added to cart total when selected. 0 = no fee.',
                        'default'     => $gw['fee_percentage'] ?? '0',
                        'desc_tip'    => true,
                        'css'         => 'max-width:80px;',
                    ];
                    $fields['fee_label_' . $gw_id] = [
                        'title'       => 'Fee Label',
                        'type'        => 'text',
                        'default'     => $gw['fee_label'] ?? 'Handling Fee',
                        'css'         => 'max-width:250px;',
                    ];
                }
            }

            $this->form_fields = $fields;
        }

        public function admin_options(): void {
            echo '<style>
                .wc-settings-sub-title { margin-top: 2em !important; }
                /* Processor section headers — add card-like styling */
                .form-table + h2,
                .form-table + .wc-settings-sub-title {
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    padding: 12px 16px !important;
                    margin-top: 28px !important;
                    margin-bottom: 4px !important;
                }
                /* First title (Active Processors heading) — different style */
                #woocommerce_mps_settings_processor_heading {
                    font-size: 16px;
                    border-bottom: 2px solid #e2e8f0;
                    padding-bottom: 10px;
                    margin-top: 30px !important;
                    background: none !important;
                    border: none !important;
                    border-bottom: 2px solid #e2e8f0 !important;
                    border-radius: 0 !important;
                    padding: 0 0 10px 0 !important;
                }
                /* Tighten the form rows within each processor group */
                .form-table tr th { padding-top: 12px; padding-bottom: 12px; width: 160px; }
                .form-table tr td { padding-top: 8px; padding-bottom: 8px; }
            </style>';
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
        if (!empty($params['mps_vp3d_poll']) || !empty($params['mps_ep2d_poll']) || !empty($params['mps_ep3d_poll']) || !empty($params['mps_ep_hosted_poll']) || !empty($params['mps_kp_poll'])) {
            wp_enqueue_script('mps-polling', plugin_dir_url(__FILE__) . 'assets/js/mps-polling.js', [], MPS_PLUGIN_VERSION, true);
        }
    });

    // ─── K-Processor Callback REST API Endpoints ───
    add_action('rest_api_init', function() {
        // New MPS endpoint
        register_rest_route('mps-kprocessor/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => 'mps_kprocessor_callback_handler',
            'permission_callback' => '__return_true',
        ]);
        // Legacy WPG endpoint (Payvelonix may have this configured)
        register_rest_route('wpgfull/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => 'mps_kprocessor_callback_handler',
            'permission_callback' => '__return_true',
        ]);
    });

    // K-Processor AJAX polling
    add_action('wp_ajax_mps_kp_poll_status', ['MPS_KProcessor', 'ajax_poll_status']);
    add_action('wp_ajax_nopriv_mps_kp_poll_status', ['MPS_KProcessor', 'ajax_poll_status']);

    // ─── Percentage Fee on Cart ───
    add_action('woocommerce_cart_calculate_fees', 'mps_add_percentage_fee');

    function mps_add_percentage_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!$cart) return;

        // Determine chosen payment method
        $chosen = '';
        if (!empty($_POST['payment_method'])) {
            $chosen = sanitize_text_field($_POST['payment_method']);
        } elseif (WC()->session) {
            $chosen = WC()->session->get('chosen_payment_method', '');
        }

        // Must be an MPS gateway
        if (!empty($chosen)) {
            if (strpos($chosen, 'mps_') !== 0) return;
        } else {
            $available = WC()->payment_gateways()->get_available_payment_gateways();
            if (empty($available)) return;
            $first = array_key_first($available);
            if (strpos($first, 'mps_') !== 0) return;
            $chosen = $first;
        }

        $main_settings = get_option('woocommerce_mps_settings_settings', []);
        $pct   = floatval($main_settings['fee_pct_' . $chosen] ?? 0);
        $label = $main_settings['fee_label_' . $chosen] ?? 'Handling Fee';

        if ($pct <= 0) return;

        $total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        $fee = round($total * ($pct / 100), 2);
        if ($fee > 0) {
            $cart->add_fee(sprintf('%s (%s%%)', $label, $pct), $fee, true);
        }
    }

    // ─── Descriptor Display (Thank-you page + Customer emails) ───
    // Thank-you page: very top (before everything) and after order details
    add_action('woocommerce_before_thankyou', 'mps_show_descriptor_thankyou', 10);
    add_action('woocommerce_thankyou', 'mps_show_descriptor_thankyou', 20);
    // Customer emails: show before and after order table (twice)
    add_action('woocommerce_email_before_order_table', 'mps_show_descriptor_email', 10, 4);
    add_action('woocommerce_email_after_order_table', 'mps_show_descriptor_email', 10, 4);

    function mps_show_descriptor_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Only for MPS gateway orders
        if (strpos($order->get_payment_method(), 'mps_') !== 0) return;

        $descriptor = $order->get_meta('_mps_descriptor');
        if (empty($descriptor)) return;

        $msg = sprintf(
            'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions regarding this transaction, please contact our support team. Please do not do chargebacks.',
            esc_html($descriptor)
        );

        echo '<div class="mps-descriptor-message" style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0 24px;border-radius:4px;font-size:15px;line-height:1.6;color:#1d2327;">';
        echo wp_kses_post(nl2br($msg));
        echo '</div>';
    }

    function mps_show_descriptor_email($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin) return;
        if (strpos($order->get_payment_method(), 'mps_') !== 0) return;

        $descriptor = $order->get_meta('_mps_descriptor');
        if (empty($descriptor)) return;

        $msg = sprintf(
            'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions regarding this transaction, please contact our support team. Please do not do chargebacks.',
            esc_html($descriptor)
        );

        if ($plain_text) {
            echo "\n" . wp_strip_all_tags($msg) . "\n\n";
        } else {
            echo '<div style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0;font-size:15px;line-height:1.6;color:#1d2327;">';
            echo wp_kses_post(nl2br($msg));
            echo '</div>';
        }
    }

    // ─── VP3D Webhook & 3DS Return Endpoints ───
    // Use same endpoints as the old merchant-payment-gateway plugin (already configured on vSafe dashboard)
    add_action('woocommerce_api_vsafe_webhook', [MPS_VProcessor_3D_Webhook::class, 'handle']);
    add_action('woocommerce_api_vsafe_3ds_return', [MPS_VProcessor_3D_Webhook::class, 'handle_3ds_return']);
    // Also register with mps_ prefix for future use
    add_action('woocommerce_api_mps_vsafe_webhook', [MPS_VProcessor_3D_Webhook::class, 'handle']);
    add_action('woocommerce_api_mps_vsafe_3ds_return', [MPS_VProcessor_3D_Webhook::class, 'handle_3ds_return']);

    // ─── K-Processor Callback Handler ───
    function mps_kprocessor_callback_handler(WP_REST_Request $request) {
        $params = $request->get_params();

        MPS_Logger::debug('KP Callback received: ' . wp_json_encode($params), 'mps-kp');

        $order_id = (int) ($params['order_number'] ?? 0);
        if (!$order_id) {
            MPS_Logger::error('KP Callback: Missing order_number', 'mps-kp');
            return new WP_REST_Response(['error' => 'Missing order_number'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            MPS_Logger::error("KP Callback: Order #{$order_id} not found", 'mps-kp');
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }

        // Find the K-Processor gateway for this order
        $payment_method = $order->get_payment_method();
        $gateway = MPS_Gateway_Factory::find($payment_method);

        if (!$gateway || !($gateway instanceof MPS_KProcessor)) {
            MPS_Logger::error("KP Callback: Gateway not found for order #{$order_id} method: {$payment_method}", 'mps-kp');
            return new WP_REST_Response(['error' => 'Gateway not found'], 400);
        }

        $gateway->process_callback($params);

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

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
                    if ($gateway && ($gateway instanceof MPS_EProcessor_2D || $gateway instanceof MPS_EProcessor_3D || $gateway instanceof MPS_EProcessor_Hosted)) {
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
                    if ($gateway && ($gateway instanceof MPS_EProcessor_2D || $gateway instanceof MPS_EProcessor_3D || $gateway instanceof MPS_EProcessor_Hosted)) {
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

// ─── WP Cron: Retry failed transaction reports + Reconciliation ───
add_action('mps_retry_failed_reports', function() {
    if (!class_exists('MPS_Portal_Client')) return;
    MPS_Portal_Client::process_retry_queue();
});

add_action('mps_reconcile_transactions', function() {
    if (!class_exists('MPS_Portal_Client')) return;
    MPS_Portal_Client::reconcile();
});

// ─── Flush rewrite rules on activation ───
register_activation_hook(__FILE__, function() {
    add_rewrite_endpoint('mps-eupaymentz-callback', EP_ROOT);
    add_rewrite_endpoint('mps-eupaymentz-return', EP_ROOT);
    flush_rewrite_rules();

    // Schedule cron events
    if (!wp_next_scheduled('mps_retry_failed_reports')) {
        wp_schedule_event(time(), 'every_5_minutes', 'mps_retry_failed_reports');
    }
    if (!wp_next_scheduled('mps_reconcile_transactions')) {
        wp_schedule_event(time(), 'twicedaily', 'mps_reconcile_transactions');
    }
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook('mps_retry_failed_reports');
    wp_clear_scheduled_hook('mps_reconcile_transactions');
});

// Custom cron interval: every 5 minutes
add_filter('cron_schedules', function($schedules) {
    $schedules['every_5_minutes'] = [
        'interval' => 300,
        'display'  => 'Every 5 Minutes',
    ];
    return $schedules;
});
