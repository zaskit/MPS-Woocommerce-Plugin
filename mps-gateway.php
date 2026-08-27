<?php
/**
 * Plugin Name: MPS Gateway
 * Description: Connect your WooCommerce store to MPS Gateway for multi-processor payment processing. Transactions go directly to processors; the portal manages configuration.
 * Version: 2.7.3
 * Author: ZASK
 * Author URI: https://zask.it
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

// Duplicate-install guard.
// If a copy of this plugin is already loaded (e.g. an old folder like
// MPS-Woocommerce-Plugin-main/ alongside the canonical mps-gateway/), bail
// before redeclaring constants/classes — otherwise PHP fatals on redeclare
// and the site goes down. Surface an admin notice so the merchant can clean
// up the duplicate folder.
if (defined('MPS_PLUGIN_FILE')) {
    $mps_dup_first  = plugin_basename(MPS_PLUGIN_FILE);
    $mps_dup_second = plugin_basename(__FILE__);
    add_action('admin_notices', function() use ($mps_dup_first, $mps_dup_second) {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p><strong>MPS Gateway:</strong> Two copies of this plugin are installed. Only the first is active; the second has been skipped to prevent a fatal error.</p>';
        echo '<ul style="list-style:disc;padding-left:24px;margin:8px 0;"><li>Active: <code>' . esc_html($mps_dup_first) . '</code></li><li>Skipped: <code>' . esc_html($mps_dup_second) . '</code></li></ul>';
        echo '<p>Delete the skipped copy from <code>wp-content/plugins/</code> (via SFTP or the Plugins admin page). Going forward, MPS Gateway should always live in <code>mps-gateway/</code>.</p></div>';
    });
    return;
}

define('MPS_PLUGIN_FILE', __FILE__);
define('MPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPS_PLUGIN_VERSION', '2.7.3');

// HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) return;

    // One-time migration from legacy 'gateway_enabled' setting key to the WC-standard
    // 'enabled' key (renamed in v2.2.3). Without this, merchants who saved their
    // settings on <=v2.2.2 would silently disable themselves after the rename
    // because the WC Payments-list toggle reads the 'enabled' field.
    $mps_opts = get_option('woocommerce_mps_settings_settings', null);
    if (is_array($mps_opts) && isset($mps_opts['gateway_enabled'])) {
        if (!isset($mps_opts['enabled'])) {
            $mps_opts['enabled'] = $mps_opts['gateway_enabled'];
        }
        unset($mps_opts['gateway_enabled']);
        update_option('woocommerce_mps_settings_settings', $mps_opts);
    }

    // Load includes
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-logger.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-portal-client.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-transaction-reporter.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-card-validator.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-merchant-contact.php';
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
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-decline-codes.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-bin-blocker.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-aprocessor.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-gateway-factory.php';

    // ─── Transaction Monitor ───
    // Per-attempt ledger, decline-reason dashboard and the customer decline email. Loaded after
    // MPS_Decline_Codes, which owns the classification the monitor's copy is keyed to.
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-ledger.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-copy.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-capture.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-notifier.php';
    require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-ack.php';

    // The table is created on activation, but an update delivered by the self-updater never fires
    // the activation hook, so the stored schema version is checked on every load instead. Gated on
    // the master switch: a merchant who never enables the monitor gets no table on their database.
    if (MPS_Monitor_Notifier::is_enabled()
        && (int) get_option('mps_monitor_ledger_version') < MPS_Monitor_Ledger::VERSION) {
        MPS_Monitor_Ledger::install();
    }

    MPS_Monitor_Capture::init();
    MPS_Monitor_Notifier::init();
    MPS_Monitor_Ack::init();

    if (is_admin()) {
        require_once MPS_PLUGIN_DIR . 'includes/class-mps-monitor-admin.php';
        MPS_Monitor_Admin::init();
    }

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

    // v2.3.2: make the single visible "MPS Gateway" (mps_settings) row control WHERE the MPS methods
    // appear at checkout. The real per-processor gateways are hidden "shell" rows, so the merchant can
    // only drag the "MPS Gateway" row — cluster the dynamic MPS methods at that row's saved position,
    // for BOTH classic and Block checkout. Also fixes the Block checkout not reflecting the admin
    // gateway order generally (core builds its sort order from an unstable internal list).
    add_filter('woocommerce_available_payment_gateways', 'mps_reorder_available_gateways', 20);
    add_action('woocommerce_blocks_checkout_enqueue_data', 'mps_set_block_sort_order', 1);
    add_action('woocommerce_blocks_cart_enqueue_data', 'mps_set_block_sort_order', 1);

    class MPS_Settings_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'mps_settings';
            $this->method_title = 'MPS Gateway';
            $this->method_description = 'Multi-processor payment gateway. Processors are assigned and managed via the MPS Gateway portal.';
            $this->has_fields = false;
            $this->supports = [];

            $this->init_form_fields();
            $this->init_settings();

            // Read the saved enable state back so the WC Payments-list toggle
            // reflects what the merchant ticked in the settings form.
            $this->enabled = $this->get_option('enabled', 'no');
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
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable MPS Gateway',
                    'default' => 'yes',
                    'description' => 'Globally enable or disable all MPS payment methods at checkout. This toggle is also reflected on the WooCommerce → Settings → Payments list.',
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
                // Printed on the thank-you page and in every post-purchase email, directly under
                // "contact us". A customer who cannot find these goes looking for the descriptor
                // instead and ends up phoning the MID holder — which is what happened on
                // 2026-08-20 and why these fields exist.
                'support_heading' => [
                    'title' => 'Customer Support Contact',
                    'type'  => 'title',
                    'description' => 'Shown to your customers after they pay, so they contact YOU about their order rather than searching for the name on their bank statement. Left blank, each line falls back to your WordPress/WooCommerce values — except the phone number, which has no other source and is simply left out.',
                ],
                'support_name' => [
                    'title' => 'Business Name',
                    'type'  => 'text',
                    'placeholder' => get_bloginfo('name'),
                    'description' => 'The name your customers know you by. Defaults to your store name.',
                    'desc_tip' => true,
                ],
                'support_email' => [
                    'title' => 'Support Email',
                    'type'  => 'email',
                    'placeholder' => 'support@yourstore.com',
                    'description' => 'Where customers should email about orders, refunds and returns. Defaults to your WooCommerce sender address — but a placeholder address (anything ending .local, .test or example.com) is never shown to a customer.',
                    'desc_tip' => true,
                ],
                'support_phone' => [
                    'title' => 'Support Phone',
                    'type'  => 'text',
                    'placeholder' => '555-555-5555',
                    'description' => 'Your customer support number. There is no other source for this, so if it is blank the phone line is left out of the notices entirely.',
                    'desc_tip' => true,
                ],
                'support_url' => [
                    'title' => 'Support Website',
                    'type'  => 'text',
                    'placeholder' => home_url(),
                    'description' => 'Where customers can reach you online. Defaults to this site.',
                    'desc_tip' => true,
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
                    $is_redirect = ($gw['processor_code'] === 'k' || $gw['processor_code'] === 'a' || $gw['processor_type'] === 'hosted');

                    // Type badge color
                    $code = $gw['processor_code'] ?? '';
                    if ($code === 'v') $badge_color = '#3b82f6';
                    elseif ($code === 'e') $badge_color = '#8b5cf6';
                    elseif ($code === 'k') $badge_color = '#10b981';
                    elseif ($code === 'a') $badge_color = '#f59e0b';
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

                    // Field defaults come from the gateway's OWN default-copy methods,
                    // so the settings form shows exactly what the checkout would show
                    // when the field is left untouched (no more form/checkout mismatch).
                    $gw_instance = MPS_Gateway_Factory::find($gw_id);
                    $default_checkout_title = $gw_instance ? $gw_instance->build_default_title() : '';
                    $default_checkout_desc  = $gw_instance ? $gw_instance->build_default_description() : '';

                    $fields['title_' . $gw_id] = [
                        'title'       => 'Checkout Title',
                        'type'        => 'text',
                        'default'     => $default_checkout_title,
                        'css'         => 'max-width:350px;',
                    ];
                    $fields['desc_' . $gw_id] = [
                        'title'       => 'Checkout Description',
                        'type'        => 'textarea',
                        'default'     => $default_checkout_desc,
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

            // v2.3.3: only show the K-Processor callback URL when a K-Processor gateway is actually
            // assigned to this merchant. It is meaningless (and confusing) for merchants without
            // K-Processor, and was previously displayed by default on every MPS Gateway settings page.
            $mps_has_kprocessor = false;
            if (class_exists('MPS_Portal_Client')) {
                foreach ((array) MPS_Portal_Client::get_gateways() as $mps_gw_cfg) {
                    if (strtolower($mps_gw_cfg['processor_code'] ?? '') === 'k') { $mps_has_kprocessor = true; break; }
                }
            }
            if ($mps_has_kprocessor) {
                $kp_callback_url = esc_url_raw(rest_url('mps-kprocessor/v1/callback'));
                $kp_callback_url_legacy = esc_url_raw(rest_url('wpgfull/v1/callback'));

                echo '<table class="form-table">';
                echo '<tr><th>K-Processor Callback URL</th><td>';
                echo '<input type="text" readonly value="' . esc_attr($kp_callback_url) . '" style="width:520px;font-family:monospace;" onclick="this.select()">';
                echo '<p class="description">Share this URL with the K-Processor (Payvelonix) support team and ask them to register it as the notification / callback URL for your merchant account. Required for K-Processor (2D and 3D) payments to complete — without it, customers redirect to the hosted page but the order never updates after payment.</p>';
                echo '<p class="description" style="margin-top:6px;"><strong>Legacy URL (also accepted):</strong> <code>' . esc_html($kp_callback_url_legacy) . '</code></p>';
                echo '</td></tr>';
                echo '</table>';
            }

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
        wp_enqueue_script('mps-ep3d-checkout-poll', plugin_dir_url(__FILE__) . 'assets/js/mps-ep3d-checkout-poll.js', ['jquery'], MPS_PLUGIN_VERSION, true);
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
    /**
     * Cardholder Charge Acknowledgment capture (client 2026-07-22).
     *
     * Stored centrally rather than inside each gateway so EVERY processor (V, E, K, A) gets it with
     * no per-processor code, including the redirect ones where the card details are entered off-site
     * and the last four is only known once the charge comes back.
     *
     * Only the fact of consent + when/where it was given is recorded here; the reporter composes the
     * full form from the order at report time.
     */
    function mps_capture_charge_acknowledgment($order) {
        if (!$order instanceof WC_Order) return;

        $accepted = false;
        foreach ($_POST as $key => $value) {
            // Classic posts <gateway_id>_charge_ack; the Block bridge posts charge_ack.
            if ($key === 'charge_ack' || substr((string) $key, -11) === '_charge_ack') {
                if (in_array((string) $value, ['1', 'true', 'yes', 'on'], true)) { $accepted = true; break; }
            }
        }
        if (!$accepted) return;

        $order->update_meta_data('_mps_charge_ack_accepted', 'yes');
        $order->update_meta_data('_mps_charge_ack_at', gmdate('Y-m-d H:i:s'));
        $order->update_meta_data('_mps_charge_ack_ip', $order->get_customer_ip_address());
    }
    add_action('woocommerce_checkout_create_order', 'mps_capture_charge_acknowledgment', 10, 1);
    add_action('woocommerce_store_api_checkout_update_order_from_request', 'mps_capture_charge_acknowledgment', 10, 1);

    /**
     * Blocked-BIN enforcement for BLOCK checkout.
     *
     * Classic is covered by MPS_Base_Gateway::validate_fields(), which the Store API never calls —
     * it goes straight to process_payment(). So the same check runs here, on the last hook before
     * payment, where both the order and the submitted card number are available.
     *
     * Deliberately central rather than inside each processor's process_payment(): it covers all
     * seven with no per-processor code, and leaves that method free for the duplicate-charge guard
     * to restructure without the two changes fighting.
     */
    function mps_block_blocked_bins($order, $request = null) {
        if (!$order instanceof WC_Order || !class_exists('MPS_BIN_Blocker')) return;

        $gateway_id = (string) $order->get_payment_method();
        if (strpos($gateway_id, 'mps_') !== 0) return;

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway  = $gateways[$gateway_id] ?? null;
        if (!$gateway || empty($gateway->blocked_bins)) return;

        $card_number = mps_store_api_card_number($gateway_id, $request);
        if ('' === $card_number) return;

        $blocked = MPS_BIN_Blocker::match($gateway->blocked_bins, $card_number);
        if (!$blocked) return;

        MPS_BIN_Blocker::log($gateway_id, $blocked['bin'], $order);

        // RouteException is how the Store API surfaces a message to the customer. Throwing before
        // the processor call is the whole point — nothing is sent, so nothing can be declined.
        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'mps_blocked_bin', $blocked['message'], 400
            );
        }
        throw new Exception($blocked['message']);
    }
    add_action('woocommerce_store_api_checkout_update_order_from_request', 'mps_block_blocked_bins', 20, 2);

    /**
     * Scheme/length/Luhn validation for Block checkout.
     *
     * Same reason the BIN guard lives here: Block checkout never calls validate_fields(), so
     * without this the only card validation on a Blocks store is the browser's — and JS can be
     * broken by a theme or a script optimizer, which is exactly what v2.5.8 was about.
     *
     * Runs AFTER the BIN guard (priority 21) so a card that is both blocked and mistyped is
     * reported as blocked: that is the more useful thing to tell the customer.
     */
    function mps_validate_card_store_api($order, $request = null) {
        if (!$order instanceof WC_Order || !class_exists('MPS_Card_Validator')) return;

        $gateway_id = (string) $order->get_payment_method();
        if (strpos($gateway_id, 'mps_') !== 0) return;

        $card_number = mps_store_api_card_number($gateway_id, $request);
        // Hosted/redirect gateways collect the card on the processor's page — nothing to check.
        if ('' === $card_number) return;

        $error = MPS_Card_Validator::error($card_number);

        if (!$error) {
            // Scheme allow-list. Block checkout never ran this at all — validate_fields() is where
            // it used to live — so an Amex on a Visa/Mastercard MID went to the processor and came
            // back as an Error.
            $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
            $gateway  = $gateways[$gateway_id] ?? null;
            if ($gateway && method_exists($gateway, 'get_allowed_cards')) {
                $error = MPS_Card_Validator::brand_error($card_number, $gateway->get_allowed_cards());
            }
        }

        if (!$error) return;

        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'mps_invalid_card', $error, 400
            );
        }
        throw new Exception($error);
    }
    add_action('woocommerce_store_api_checkout_update_order_from_request', 'mps_validate_card_store_api', 21, 2);

    /**
     * The card number as it arrives on a Store API checkout request.
     *
     * The Store API puts the fields in payment_data; $_POST is the classic/bridged shape. Shared by
     * the BIN guard and the validator so the two can never read different values for the same
     * submission.
     */
    function mps_store_api_card_number($gateway_id, $request = null) {
        if ($request && isset($request['payment_data']) && is_array($request['payment_data'])) {
            foreach ($request['payment_data'] as $field) {
                $key = $field['key'] ?? '';
                if ($key === 'card_number' || substr((string) $key, -12) === '_card_number') {
                    return (string) ($field['value'] ?? '');
                }
            }
        }
        foreach ([$gateway_id . '_card_number', 'card_number'] as $key) {
            if (!empty($_POST[$key])) {
                return sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }

        return '';
    }

    /**
     * Read-only endpoint the Block checkout calls after a failed payment to fetch the decline message
     * the server remembered for THIS session (see MPS_Decline_Codes::remember()). Block checkout does
     * not reload and a thrown gateway error does not carry payment details to the JS, so this is how
     * the same message that shows in the top notice also gets rendered under the card fields.
     *
     * Returns only our own generic wording — no card data, no order data, nothing session-identifying.
     */
    add_action('rest_api_init', function() {
        register_rest_route('mps/v1', '/last-decline', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => ['order' => ['sanitize_callback' => 'absint']],
            'callback'            => function($request) {
                if (!class_exists('MPS_Decline_Codes') || !function_exists('WC')) {
                    return new WP_REST_Response(['decline' => null], 200);
                }
                // Primary: keyed by order id (Block checkout hands the JS the order id) — no dependence
                // on the WC session cookie reaching this custom route.
                $order_id = (int) $request->get_param('order');
                $d = MPS_Decline_Codes::take_for_order($order_id);

                // Fallback: the session-remembered decline (classic checkout, or if no order id given).
                if (!$d) {
                    if (null === WC()->session) {
                        $handler = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
                        if (class_exists($handler)) {
                            WC()->session = new $handler();
                            WC()->session->init();
                        }
                    }
                    $d = MPS_Decline_Codes::consume();
                }
                return new WP_REST_Response(['decline' => $d ? [
                    'message' => (string) $d['message'],
                    'final'   => !empty($d['final']),
                ] : null], 200);
            },
        ]);
    });

    /**
     * One-time cleanup of checkout copy the merchant never chose (client 2026-07-22).
     *
     * Since v2.4.5 a saved title/description always wins over the default — which is what merchants
     * asked for, but it means simply changing the default to "Pay with Card" would leave every
     * existing store still showing "Pay securely via V I S A & M A S T E R C A R D", because that
     * string is sitting in their settings from an older version.
     *
     * So on upgrade we clear ONLY values that exactly match copy we generated ourselves. Anything a
     * merchant actually typed is left untouched. Runs once, guarded by an option.
     */
    function mps_migrate_legacy_checkout_copy() {
        if (get_option('mps_legacy_copy_migrated') === MPS_PLUGIN_VERSION) return;

        $settings = get_option('woocommerce_mps_settings_settings', []);
        if (is_array($settings) && $settings) {
            $legacy  = MPS_Base_Gateway::legacy_auto_copy();
            $changed = false;
            foreach ($settings as $key => $value) {
                if (!is_string($value)) continue;
                if (strpos($key, 'title_') !== 0 && strpos($key, 'desc_') !== 0) continue;
                if (in_array(trim($value), $legacy, true)) {
                    $settings[$key] = '';   // empty → falls back to the current default
                    $changed = true;
                }
            }
            if ($changed) update_option('woocommerce_mps_settings_settings', $settings);
        }

        update_option('mps_legacy_copy_migrated', MPS_PLUGIN_VERSION);
    }
    add_action('admin_init', 'mps_migrate_legacy_checkout_copy');

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

    /**
     * ─── Billing descriptor notice email ───
     *
     * Registered as a real WooCommerce email so the merchant sees it in WooCommerce → Settings →
     * Emails alongside the others, with the store's own branding, and can edit the subject or turn
     * it off. @see MPS_Billing_Notice_Email
     */
    add_filter('woocommerce_email_classes', function($emails) {
        require_once MPS_PLUGIN_DIR . 'includes/class-mps-billing-notice-email.php';
        if (class_exists('MPS_Billing_Notice_Email')) {
            $emails['MPS_Billing_Notice_Email'] = new MPS_Billing_Notice_Email();
        }
        return $emails;
    });

    /**
     * Sent the moment the payment succeeds (Salman, 2026-08-20: "lets send immediately, two emails
     * are fine"), so the customer has the descriptor in writing before the charge can surprise them
     * on a statement.
     *
     * Three entry points because processors finish in different ways: 2D returns approved inline,
     * 3D and hosted come back through a redirect or webhook that flips the status later. All three
     * land on the same guarded trigger, which is idempotent via order meta.
     */
    function mps_send_billing_notice($order_id) {
        if (!$order_id || !function_exists('WC')) return;

        $order = wc_get_order($order_id);
        if (!$order || strpos($order->get_payment_method(), 'mps_') !== 0) return;

        $mailer = WC()->mailer();
        $emails = $mailer ? $mailer->get_emails() : [];
        if (!empty($emails['MPS_Billing_Notice_Email'])) {
            $emails['MPS_Billing_Notice_Email']->trigger($order_id, $order);
        }
    }
    add_action('woocommerce_payment_complete', 'mps_send_billing_notice', 20);
    add_action('woocommerce_order_status_processing', 'mps_send_billing_notice', 20);
    add_action('woocommerce_order_status_completed', 'mps_send_billing_notice', 20);

    /**
     * Tell the merchant when the notices cannot name them properly.
     *
     * The whole point of this work is sending the customer to the merchant instead of the
     * descriptor holder, which fails silently if the store never filled in its support details —
     * and a placeholder address like dev-email@wpengine.local counts as not filled in.
     */
    add_action('admin_notices', function() {
        if (!current_user_can('manage_woocommerce') || !class_exists('MPS_Merchant_Contact')) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && !in_array($screen->id, ['dashboard', 'woocommerce_page_wc-settings', 'plugins'], true)) return;

        $missing = MPS_Merchant_Contact::missing();
        if (!$missing) return;

        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=mps_settings');
        echo '<div class="notice notice-warning"><p><strong>MPS Gateway:</strong> your customers are told to contact you about their order, but this store has no ' .
            esc_html(implode(', ', $missing)) . ' set. ' .
            '<a href="' . esc_url($url) . '">Add your support contact details</a> so the post-purchase notices can point them to you.</p></div>';
    });

    // ─── Descriptor Display (Thank-you page + Customer emails) ───
    // 🛑 ONCE each, not twice. Until 2026-08-20 this printed at the top of the thank-you page AND
    // again after the order details, and twice more in every customer email. The notice is now much
    // louder, and a customer who reads the same warning four times across two emails stops reading
    // it. The full treatment lives in the dedicated billing-notice email; these are the reminders.
    add_action('woocommerce_before_thankyou', 'mps_show_descriptor_thankyou', 10);
    add_action('woocommerce_email_before_order_table', 'mps_show_descriptor_email', 10, 4);

    /**
     * May this order's billing descriptor be shown to the customer yet?
     *
     * 🛑 Only AFTER the money has actually moved (client 2026-08-21: naming the descriptor before
     * the transaction takes place "will lead to possible deactivation of your processing"). The
     * order screens below are reachable well before that — the order-received page after a 3DS
     * attempt that never completed, the customer invoice sent as a payment REMINDER, the on-hold
     * and failed-order emails. All of those are pre-transaction, so none of them may name it.
     * Checkout, which is earlier still, shows the descriptor-free billing notice instead
     * (MPS_Monitor_Ack::disclosure_html).
     *
     * is_paid() covers the paid statuses; the date_paid check catches an order whose status has
     * since moved on (cancelled after payment, for instance) but which really was charged.
     */
    function mps_descriptor_may_be_shown($order): bool {
        if (!$order instanceof WC_Order) return false;
        if (strpos($order->get_payment_method(), 'mps_') !== 0) return false;
        if ('' === trim((string) $order->get_meta('_mps_descriptor'))) return false;

        return $order->is_paid() || (bool) $order->get_date_paid();
    }

    function mps_show_descriptor_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Only for MPS gateway orders, and only once the payment has actually gone through.
        if (!mps_descriptor_may_be_shown($order)) return;

        $descriptor = $order->get_meta('_mps_descriptor');

        $contact = MPS_Merchant_Contact::all();

        // Two blocks, in this order on purpose: who they bought from (with how to reach them) comes
        // FIRST, so the support route is the thing they read before the unfamiliar name; the
        // statement notice follows and points straight back to it.
        echo '<div class="mps-post-purchase" style="margin:0 0 32px;">';

        echo '<div class="mps-merchant-card" style="background:#ecfdf5;border:1px solid #6ee7b7;border-left:6px solid #059669;padding:26px 28px;border-radius:10px 10px 0 0;text-align:center;color:#064e3b;line-height:1.6;">';
        echo '<div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#059669;margin-bottom:8px;">Thank you for your purchase from</div>';
        if ($contact['name']) {
            echo '<div style="font-size:30px;font-weight:800;color:#047857;line-height:1.2;margin-bottom:10px;">' . esc_html($contact['name']) . '</div>';
        }
        $lines = [];
        if ($contact['email']) {
            $lines[] = '<a href="mailto:' . esc_attr($contact['email']) . '" style="color:#047857;text-decoration:underline;">' . esc_html($contact['email']) . '</a>';
        }
        if ($contact['phone']) {
            $lines[] = '<a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $contact['phone'])) . '" style="color:#047857;text-decoration:none;">' . esc_html($contact['phone']) . '</a>';
        }
        if ($lines) {
            echo '<div style="font-size:16px;font-weight:600;">' . implode(' <span style="color:#6ee7b7;">|</span> ', $lines) . '</div>';
        }
        echo '</div>';

        echo '<div class="mps-statement-notice" style="background:#fffbeb;border:1px solid #fcd34d;border-left:6px solid #d97706;border-top:0;padding:22px 28px;border-radius:0 0 10px 10px;color:#78350f;line-height:1.65;">';
        echo '<p style="margin:0 0 10px;font-size:15px;"><strong style="text-transform:uppercase;letter-spacing:0.5px;">Bank statement notice:</strong> Your statement will display <strong style="font-size:19px;letter-spacing:0.3px;">' . esc_html($descriptor) . '</strong>.</p>';
        echo '<p style="margin:0 0 10px;font-size:15px;font-weight:700;">' . esc_html($descriptor) . ' is a statement descriptor only — DO NOT CONTACT ' . esc_html($descriptor) . '.</p>';
        echo '<p style="margin:0;font-size:15px;">For refunds, returns, cancellations, order questions, or payment support, contact ' .
            ($contact['name'] ? '<strong>' . esc_html($contact['name']) . '</strong>' : 'us') . ' only.</p>';
        echo '</div>';

        echo '</div>';
    }

    function mps_show_descriptor_email($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin) return;

        // Paid orders only — the invoice can go out as a payment reminder, and the on-hold and
        // failed-order mails are sent on orders that were never charged. @see the rule above.
        if (!mps_descriptor_may_be_shown($order)) return;

        $descriptor = $order->get_meta('_mps_descriptor');

        $contact = MPS_Merchant_Contact::all();
        $who = $contact['name'] ?: __('us', 'mps-gateway');

        // The COMPACT version. The full "do not contact the descriptor" treatment is the dedicated
        // billing-notice email — see MPS_Billing_Notice_Email. This is the reminder that rides along
        // with the normal order mail, so it says the same thing in three lines.
        if ($plain_text) {
            echo "\n" . strtoupper($descriptor) . "\n";
            echo sprintf("Your bank statement will show %s. That is a statement descriptor only — please do not contact it.\n", $descriptor);
            echo sprintf("For refunds, returns, cancellations or any question about this order, contact %s", $who);
            if ($contact['email']) { echo sprintf(" at %s", $contact['email']); }
            if ($contact['phone']) { echo sprintf(" or %s", $contact['phone']); }
            echo ".\n\n";
        } else {
            echo '<div style="background:#fffbeb;border:1px solid #fcd34d;border-left:5px solid #d97706;padding:20px 24px;margin:16px 0;border-radius:6px;font-size:15px;line-height:1.7;color:#78350f;">';
            echo '<p style="margin:0 0 8px;font-size:15px;">Your bank statement will show <strong style="font-size:19px;letter-spacing:0.3px;">' . esc_html($descriptor) . '</strong> — a statement descriptor only. <strong>Please do not contact ' . esc_html($descriptor) . '.</strong></p>';
            echo '<p style="margin:0;font-size:15px;">For refunds, returns, cancellations or any question about this order, contact <strong>' . esc_html($who) . '</strong>';
            $reach = [];
            if ($contact['email']) {
                $reach[] = '<a href="mailto:' . esc_attr($contact['email']) . '" style="color:#92400e;">' . esc_html($contact['email']) . '</a>';
            }
            if ($contact['phone']) {
                $reach[] = esc_html($contact['phone']);
            }
            if ($reach) {
                echo ' &mdash; ' . implode(' &middot; ', $reach);
            }
            echo '.</p>';
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

    // ─── Post-Payment Status Sync (Cancel/Refund → Portal) ───
    add_action('woocommerce_order_status_cancelled', 'mps_sync_order_status_to_portal', 10, 1);
    add_action('woocommerce_order_status_refunded', 'mps_sync_order_status_to_portal', 10, 1);

    function mps_sync_order_status_to_portal($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Only for MPS gateway orders
        if (strpos($order->get_payment_method(), 'mps_') !== 0) return;

        $wc_status = $order->get_status();
        $portal_status = ($wc_status === 'refunded') ? 'refunded' : 'cancelled';

        $data = [
            'gateway_id'      => $order->get_meta('_mps_portal_gateway_id'),
            'order_ref'       => (string) $order->get_id(),
            'processor_tx_id' => $order->get_meta('_mps_processor_tx_id') ?: null,
            'amount'          => (float) $order->get_total(),
            'currency'        => $order->get_currency(),
            'status'          => $portal_status,
            'status_message'  => ucfirst($portal_status) . ' by merchant',
            'customer_email'  => $order->get_billing_email(),
        ];

        $result = MPS_Portal_Client::report_transaction($data);
        MPS_Logger::info("Order #{$order_id} status sync to portal: {$portal_status} — " . ($result ? 'success' : 'queued for retry'), 'mps-reporter');
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

    // ─── A-Processor Return Handler (customer back from the cloak pay page) ───
    add_action('template_redirect', function() {
        if (empty($_GET['mps_a_return']) || empty($_GET['order_id'])) return;

        $order_id = (int) $_GET['order_id'];
        $order    = wc_get_order($order_id);
        if (!$order) return;

        // Verify the order key so the return URL can't be forged for another order.
        if (!isset($_GET['key']) || !hash_equals($order->get_order_key(), sanitize_text_field($_GET['key']))) {
            MPS_Logger::error("A-Processor return: order key mismatch for order #{$order_id}", 'mps-a');
            return;
        }

        $gateway = MPS_Gateway_Factory::find($order->get_payment_method());
        if ($gateway instanceof MPS_AProcessor) {
            $gateway->process_return([
                'order_id'       => $order_id,
                'transaction_id' => isset($_GET['transaction_id']) ? (int) $_GET['transaction_id'] : 0,
                'status'         => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            ]);
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

// ─── Activation: flush rewrites, schedule cron, redirect to settings ───
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

    // Flag for redirect to settings page (60s window covers slow plugin-page reloads)
    set_transient('mps_activation_redirect', true, 60);
});

// Redirect to settings on first activation.
// Belt-and-braces: also exposes a "Settings" link on the Plugins page row below,
// so merchants can find the settings page even if this redirect is intercepted
// by another plugin or by a non-admin landing page.
add_action('admin_init', function() {
    if (!get_transient('mps_activation_redirect')) return;
    delete_transient('mps_activation_redirect');

    // Skip if this isn't a context where a redirect makes sense.
    if (wp_doing_ajax() || wp_doing_cron() || is_network_admin()) return;
    if (isset($_GET['activate-multi'])) return;
    if (!current_user_can('manage_woocommerce')) return;

    wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=mps_settings'));
    exit;
});

// "Settings" link on the Plugins page row for MPS Gateway.
// Lets merchants jump straight to the gateway settings even if they missed
// (or another plugin swallowed) the post-activation redirect above.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=mps_settings')) . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
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

// ─── GitHub Auto-Updater ───
// Checks zaskit/MPS-Woocommerce-Plugin releases for new versions.
// WordPress will show "Update available" and allow one-click update.
add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = plugin_basename(MPS_PLUGIN_FILE);
    $current_version = MPS_PLUGIN_VERSION;
    $github_repo = 'zaskit/MPS-Woocommerce-Plugin';

    // Check GitHub for latest release (cached for 6 hours)
    $cache_key = 'mps_github_update_check';
    $remote = get_transient($cache_key);

    if ($remote === false) {
        // Try releases first, fall back to tags
        $response = wp_remote_get("https://api.github.com/repos/{$github_repo}/releases/latest", [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github.v3+json'],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $remote = json_decode(wp_remote_retrieve_body($response));
        } else {
            // Fallback: check latest tag
            $response = wp_remote_get("https://api.github.com/repos/{$github_repo}/tags?per_page=1", [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github.v3+json'],
            ]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $tags = json_decode(wp_remote_retrieve_body($response));
                if (!empty($tags[0]->name)) {
                    $remote = (object) [
                        'tag_name'   => $tags[0]->name,
                        'zipball_url' => $tags[0]->zipball_url ?? null,
                    ];
                }
            }
        }

        if (!empty($remote)) {
            set_transient($cache_key, $remote, 6 * HOUR_IN_SECONDS);
        } else {
            return $transient;
        }
    }

    if (empty($remote->tag_name)) return $transient;

    $remote_version = ltrim($remote->tag_name, 'v');

    if (version_compare($remote_version, $current_version, '>')) {
        $transient->response[$plugin_slug] = (object) [
            'slug'        => dirname($plugin_slug),
            'plugin'      => $plugin_slug,
            'new_version' => $remote_version,
            'url'         => "https://github.com/{$github_repo}",
            'package'     => $remote->zipball_url ?? "https://github.com/{$github_repo}/archive/refs/tags/{$remote->tag_name}.zip",
        ];
    }

    return $transient;
});

// Plugin info popup (when user clicks "View details")
add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;

    $plugin_slug = dirname(plugin_basename(MPS_PLUGIN_FILE));
    if ($args->slug !== $plugin_slug) return $result;

    $github_repo = 'zaskit/MPS-Woocommerce-Plugin';

    // Try releases first, fall back to tags
    $remote = null;
    $response = wp_remote_get("https://api.github.com/repos/{$github_repo}/releases/latest", [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github.v3+json'],
    ]);
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $remote = json_decode(wp_remote_retrieve_body($response));
    } else {
        $response = wp_remote_get("https://api.github.com/repos/{$github_repo}/tags?per_page=1", [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github.v3+json'],
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $tags = json_decode(wp_remote_retrieve_body($response));
            if (!empty($tags[0]->name)) {
                $remote = (object) ['tag_name' => $tags[0]->name, 'zipball_url' => $tags[0]->zipball_url ?? null, 'body' => '', 'published_at' => ''];
            }
        }
    }

    if (empty($remote->tag_name)) return $result;

    return (object) [
        'name'          => 'MPS Gateway',
        'slug'          => $plugin_slug,
        'version'       => ltrim($remote->tag_name, 'v'),
        'author'        => '<a href="https://zask.it">ZASK</a>',
        'homepage'      => "https://github.com/{$github_repo}",
        'requires'      => '6.0',
        'requires_php'  => '8.0',
        'downloaded'    => 0,
        'last_updated'  => $remote->published_at ?? '',
        'sections'      => [
            'description'  => 'Multi-processor payment gateway for WooCommerce. Connect to MPS Gateway portal for centralized processor management.',
            'changelog'    => nl2br(esc_html($remote->body ?? 'See GitHub for details.')),
        ],
        'download_link' => $remote->zipball_url ?? "https://github.com/{$github_repo}/archive/refs/tags/{$remote->tag_name}.zip",
    ];
}, 10, 3);

// Fix folder name after GitHub ZIP extraction (GitHub adds repo-name-hash prefix)
add_filter('upgrader_source_selection', function($source, $remote_source, $upgrader, $hook_extra) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(MPS_PLUGIN_FILE)) {
        return $source;
    }

    $expected_dir = dirname(plugin_basename(MPS_PLUGIN_FILE));
    $corrected = trailingslashit($remote_source) . $expected_dir . '/';

    if ($source !== $corrected) {
        if (rename($source, $corrected)) {
            return $corrected;
        }
    }

    return $source;
}, 10, 4);

/**
 * v2.5.6 — Recover from WooCommerce silently dropping a gateway.
 *
 * WC_Payment_Gateways::init() stores each gateway as
 *     $this->payment_gateways[ $ordering[ $gateway->id ] ] = $gateway;
 * keyed by its NUMBER in the woocommerce_gateway_order option, then ksort()s. Two gateways holding
 * the same number therefore overwrite each other — the loser (whichever core loads first) vanishes
 * from the registry entirely. It never reaches the classic checkout, never reaches the Store API's
 * payment_methods list (CartSchema reads get_available_payment_gateways()), and NOTHING is logged
 * or surfaced to the merchant. The gateway still constructs fine and still registers its Blocks
 * integration — which is what makes this so confusing to diagnose.
 *
 * The per-processor MPS gateways are unusually exposed to this: they hold values in that option but
 * are hidden "shell" rows in the Payments UI, so the merchant cannot drag them and a stale value can
 * end up colliding after the other gateways are re-sequenced by the grouped WC 9.9+/10.x UI.
 *
 * Two-part repair, deliberately conservative — we only ever remove OUR OWN key from the shared
 * option, never another plugin's:
 *   1. Put any missing MPS gateway back into the registry so THIS request's checkout is correct.
 *   2. Drop the colliding mps_* key from woocommerce_gateway_order so the tie disappears for every
 *      later request. That also rescues the third-party gateway in the case where the MPS gateway
 *      was the one that won the collision. Display position is unaffected: mps_sorted_gateway_ids()
 *      below clusters the MPS methods at the "MPS Gateway" row regardless of their raw value.
 */
add_action('wc_payment_gateways_initialized', 'mps_restore_dropped_gateways', 5);

function mps_restore_dropped_gateways($wc_payment_gateways) {
    if (!is_object($wc_payment_gateways) || !isset($wc_payment_gateways->payment_gateways)) return;
    if (!class_exists('MPS_Gateway_Factory')) return;

    $registry = $wc_payment_gateways->payment_gateways;
    if (!is_array($registry)) return;

    $present = [];
    foreach ($registry as $gateway) {
        if (is_object($gateway) && isset($gateway->id)) $present[$gateway->id] = true;
    }

    $ordering = (array) get_option('woocommerce_gateway_order', []);
    $missing  = [];

    foreach (MPS_Gateway_Factory::build() as $gateway) {
        if (isset($present[$gateway->id])) continue;
        $missing[] = $gateway->id;

        // Re-insert at the first free slot past the ordering block so nothing else is displaced.
        $key = 999;
        while (isset($registry[$key])) $key++;
        $registry[$key] = $gateway;

        $collided_with = [];
        if (isset($ordering[$gateway->id])) {
            foreach ($ordering as $other_id => $value) {
                if ($other_id !== $gateway->id && (string) $value === (string) $ordering[$gateway->id]) {
                    $collided_with[] = $other_id;
                }
            }
        }
        MPS_Logger::error(sprintf(
            'Gateway %s was dropped by WooCommerce (order value %s shared with: %s) — restored for this request.',
            $gateway->id,
            $ordering[$gateway->id] ?? 'none',
            $collided_with ? implode(', ', $collided_with) : 'unknown'
        ));
    }

    if (!$missing) return;

    ksort($registry);
    $wc_payment_gateways->payment_gateways = $registry;

    // Break the tie permanently by removing our own key. WC then appends the gateway after the
    // ordered block on the next request, and the sort filter puts it back where it belongs.
    $changed = false;
    foreach ($missing as $id) {
        if (isset($ordering[$id])) { unset($ordering[$id]); $changed = true; }
    }
    if ($changed) {
        update_option('woocommerce_gateway_order', $ordering);
        MPS_Logger::info('Removed the colliding mps_* entries from woocommerce_gateway_order.');
    }
}

/**
 * v2.3.2 — Gateway ordering helpers (payment-provider sorting).
 *
 * The per-processor MPS gateways are hidden "shell" rows in the admin Payments list, so the merchant
 * can only drag the single visible "MPS Gateway" (mps_settings) row. These helpers make that row's
 * saved position govern where the MPS methods appear at checkout, and make the Block checkout honor
 * the admin drag order (which core does not reliably do).
 */
function mps_sorted_gateway_ids(array $ids): array {
    $opt     = (array) get_option('woocommerce_gateway_order', []);
    $mps_pos = (isset($opt['mps_settings']) && is_numeric($opt['mps_settings'])) ? (float) $opt['mps_settings'] : (float) PHP_INT_MAX;
    $rows = [];
    foreach (array_values($ids) as $i => $id) {
        $is_mps_dynamic = (strpos($id, 'mps_') === 0 && $id !== 'mps_settings');
        if ($is_mps_dynamic) {
            $pos = $mps_pos + ($i * 0.0001);                         // cluster at the "MPS Gateway" slot
        } else {
            $pos = (isset($opt[$id]) && is_numeric($opt[$id])) ? (float) $opt[$id] : (float) PHP_INT_MAX - 1;
        }
        $rows[] = [$id, $pos, $i];
    }
    usort($rows, function ($a, $b) { return $a[1] === $b[1] ? ($a[2] <=> $b[2]) : ($a[1] <=> $b[1]); });
    return array_map(function ($r) { return $r[0]; }, $rows);
}

/** Classic checkout: reorder the available-gateways array by the admin order. */
function mps_reorder_available_gateways($gateways) {
    if (!is_array($gateways) || count($gateways) < 2) return $gateways;
    $ordered = [];
    foreach (mps_sorted_gateway_ids(array_keys($gateways)) as $id) {
        if (isset($gateways[$id])) $ordered[$id] = $gateways[$id];
    }
    return $ordered ?: $gateways;
}

/** Block checkout: set paymentMethodSortOrder (core guards with !exists, so first writer wins). */
function mps_set_block_sort_order() {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Package') || !function_exists('WC')) return;
    try {
        $registry = \Automattic\WooCommerce\Blocks\Package::container()
            ->get(\Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class);
    } catch (\Throwable $e) {
        return; // never break checkout
    }
    if (!$registry || $registry->exists('paymentMethodSortOrder')) return;
    $pg = (function_exists('WC') && WC()->payment_gateways) ? WC()->payment_gateways->payment_gateways() : [];
    $enabled = array_filter($pg, function ($g) { return filter_var($g->enabled, FILTER_VALIDATE_BOOLEAN); });
    $registry->add('paymentMethodSortOrder', array_values(mps_sorted_gateway_ids(array_keys($enabled))));
}
