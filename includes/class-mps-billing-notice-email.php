<?php
/**
 * "Your Order Confirmation & Important Billing Information" — the dedicated post-purchase email.
 *
 * Written after a customer of one merchant googled the descriptor on their statement, found the MID
 * holder and contacted them instead of the store (2026-08-20). The job of this email is narrow and
 * it should not be diluted: name the descriptor, say plainly that it is not a support contact, and
 * make the merchant's own details the most prominent thing on the page.
 *
 * A real WC_Email subclass rather than wp_mail(), so it appears under WooCommerce → Settings →
 * Emails with the store's own branding, subject and heading, and can be previewed and disabled like
 * any other. Sends immediately after payment (Salman, 2026-08-20 — "lets send immediately, two
 * emails are fine").
 */

defined('ABSPATH') || exit;

if (! class_exists('WC_Email')) {
    return;
}

class MPS_Billing_Notice_Email extends WC_Email {

    public function __construct() {
        $this->id             = 'mps_billing_notice';
        $this->customer_email = true;
        $this->title          = __('Billing descriptor notice', 'mps-gateway');
        $this->description    = __('Sent to the customer immediately after a successful payment. Tells them which name will appear on their bank statement and that it is not a support contact, so they come to you instead of the descriptor holder.', 'mps-gateway');
        $this->template_html  = '';
        $this->template_plain = '';
        $this->placeholders   = [
            '{order_number}' => '',
            '{order_date}'   => '',
            '{descriptor}'   => '',
            '{merchant}'     => '',
        ];

        parent::__construct();
    }

    public function get_default_subject() {
        return __('Your order confirmation & important billing information', 'mps-gateway');
    }

    public function get_default_heading() {
        return __('Order confirmed', 'mps-gateway');
    }

    /**
     * Send for one order.
     *
     * 🛑 Guarded by order meta, not by a static flag: the payment-complete hooks can fire more than
     * once for the same order (a 3DS return landing twice, a status flipping back and forth), and a
     * customer receiving this twice reads as spam from a store they have just paid.
     */
    public function trigger($order_id, $order = null) {
        $this->setup_locale();

        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        if (! $order) {
            $this->restore_locale();

            return;
        }

        if (strpos($order->get_payment_method(), 'mps_') !== 0) {
            $this->restore_locale();

            return;
        }

        if ($order->get_meta('_mps_billing_notice_sent')) {
            $this->restore_locale();

            return;
        }

        $descriptor = trim((string) $order->get_meta('_mps_descriptor'));
        // Without a descriptor there is no notice to give — and naming nothing would be worse than
        // staying quiet. The order emails still carry the compact reminder once it is known.
        if ('' === $descriptor) {
            $this->restore_locale();

            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        $contact = MPS_Merchant_Contact::all();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{order_date}']   = wc_format_datetime($order->get_date_created());
        $this->placeholders['{descriptor}']   = $descriptor;
        $this->placeholders['{merchant}']     = $contact['name'];

        if ($this->is_enabled() && $this->get_recipient()) {
            $sent = $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());

            // Recorded either way: a failed send that we retry on the next hook fire would send two
            // if the first actually went out. One notice, or none.
            $order->update_meta_data('_mps_billing_notice_sent', current_time('mysql'));
            $order->save();

            if (class_exists('MPS_Logger')) {
                MPS_Logger::debug(sprintf('Billing notice for order #%d: %s', $order->get_id(), $sent ? 'sent' : 'send failed'), 'mps-billing-notice');
            }
        }

        $this->restore_locale();
    }

    public function get_content_html() {
        return $this->render(false);
    }

    public function get_content_plain() {
        return $this->render(true);
    }

    /**
     * The body, in one place for both formats.
     *
     * The visual hierarchy is the requirement, not decoration: "DO NOT CONTACT <descriptor>" and
     * "CONTACT <merchant>" are the two largest elements, with the merchant's email and phone
     * directly beneath the second so there is no ambiguity about where to go.
     */
    private function render(bool $plain): string {
        $order      = $this->object;
        $descriptor = trim((string) $order->get_meta('_mps_descriptor'));
        $contact    = MPS_Merchant_Contact::all();
        $merchant   = $contact['name'] ?: get_bloginfo('name');

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' × ' . $item->get_quantity();
        }
        $items = implode(', ', $items);

        $cannot = [
            __('Refunds', 'mps-gateway'),
            __('Returns', 'mps-gateway'),
            __('Cancellations', 'mps-gateway'),
            __('Order status', 'mps-gateway'),
            __('Shipping questions', 'mps-gateway'),
            __('Product or service questions', 'mps-gateway'),
            __('Payment questions', 'mps-gateway'),
            __('Any other customer service issue', 'mps-gateway'),
        ];

        if ($plain) {
            $out  = strtoupper(__('Order confirmed', 'mps-gateway')) . "\n\n";
            $out .= sprintf(__('Thank you for your purchase from %s. Your payment was successfully processed.', 'mps-gateway'), $merchant) . "\n\n";
            $out .= "=======================================\n";
            $out .= strtoupper(__('Important billing information', 'mps-gateway')) . "\n";
            $out .= "=======================================\n\n";
            $out .= __('Your bank/card statement will show:', 'mps-gateway') . "\n\n";
            $out .= '    ' . strtoupper($descriptor) . "\n\n";
            $out .= __('Please save this email so you recognise this charge on your statement.', 'mps-gateway') . "\n\n";
            $out .= sprintf(__('%s is the billing descriptor associated with this transaction. It is NOT the customer support contact for your purchase.', 'mps-gateway'), $descriptor) . "\n\n";
            $out .= sprintf(__('DO NOT CONTACT %s FOR:', 'mps-gateway'), strtoupper($descriptor)) . "\n";
            foreach ($cannot as $line) {
                $out .= '  - ' . $line . "\n";
            }
            $out .= "\n" . strtoupper(sprintf(__('For all support, contact %s', 'mps-gateway'), $merchant)) . "\n\n";
            if ($contact['email']) { $out .= __('Email:', 'mps-gateway') . '   ' . $contact['email'] . "\n"; }
            if ($contact['phone']) { $out .= __('Phone:', 'mps-gateway') . '   ' . $contact['phone'] . "\n"; }
            if ($contact['website']) { $out .= __('Website:', 'mps-gateway') . ' ' . $contact['website'] . "\n"; }
            $out .= "\n" . sprintf(__('%s can handle refunds, returns, cancellations and any other question about your order. Contacting %s will not help resolve an issue with your purchase.', 'mps-gateway'), $merchant, $descriptor) . "\n\n";
            $out .= strtoupper(__('Order details', 'mps-gateway')) . "\n";
            $out .= __('Order #:', 'mps-gateway') . ' ' . $order->get_order_number() . "\n";
            $out .= __('Date:', 'mps-gateway') . ' ' . wc_format_datetime($order->get_date_created()) . "\n";
            if ($items) { $out .= __('Product(s):', 'mps-gateway') . ' ' . $items . "\n"; }
            $out .= __('Total:', 'mps-gateway') . ' ' . wp_strip_all_tags($order->get_formatted_order_total()) . "\n";
            $out .= __('Payment method:', 'mps-gateway') . ' ' . $order->get_payment_method_title() . "\n\n";
            $out .= sprintf(__('Again: your statement will show %s, but your customer service contact is %s.', 'mps-gateway'), $descriptor, $merchant) . "\n\n";
            $out .= __('Thank you for your order.', 'mps-gateway') . "\n";

            return $out;
        }

        ob_start();
        wc_get_template('emails/email-header.php', ['email_heading' => $this->get_heading(), 'email' => $this]);
        ?>
        <p style="font-size:15px;line-height:1.7;margin:0 0 24px;">
            <?php printf(esc_html__('Thank you for your purchase from %s. Your payment was successfully processed.', 'mps-gateway'), '<strong>' . esc_html($merchant) . '</strong>'); ?>
        </p>

        <div style="background:#fffbeb;border:1px solid #fcd34d;border-left:6px solid #d97706;border-radius:8px;padding:24px;margin:0 0 24px;">
            <div style="font-size:13px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#b45309;margin-bottom:14px;"><?php esc_html_e('Important billing information', 'mps-gateway'); ?></div>
            <p style="margin:0 0 6px;font-size:15px;color:#78350f;"><?php esc_html_e('Your bank/card statement will show:', 'mps-gateway'); ?></p>
            <div style="font-size:32px;font-weight:800;color:#78350f;letter-spacing:0.5px;line-height:1.2;margin:0 0 14px;"><?php echo esc_html($descriptor); ?></div>
            <p style="margin:0 0 14px;font-size:14px;color:#92400e;"><?php esc_html_e('Please save this email so you recognise this charge on your statement.', 'mps-gateway'); ?></p>
            <p style="margin:0;font-size:15px;color:#78350f;">
                <?php printf(esc_html__('%s is the billing descriptor associated with this transaction. It is NOT the customer support contact for your purchase.', 'mps-gateway'), '<strong>' . esc_html($descriptor) . '</strong>'); ?>
            </p>
        </div>

        <div style="border:2px solid #dc2626;border-radius:8px;padding:24px;margin:0 0 24px;">
            <div style="font-size:24px;font-weight:800;color:#dc2626;line-height:1.25;margin:0 0 14px;text-transform:uppercase;">
                <?php printf(esc_html__('Do not contact %s for:', 'mps-gateway'), esc_html($descriptor)); ?>
            </div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;font-size:15px;color:#1f2937;line-height:1.8;">
                <?php foreach ($cannot as $line) : ?>
                    <tr><td style="padding:0;">• <?php echo esc_html($line); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div style="background:#ecfdf5;border:2px solid #059669;border-radius:8px;padding:24px;margin:0 0 24px;">
            <div style="font-size:24px;font-weight:800;color:#047857;line-height:1.25;margin:0 0 16px;text-transform:uppercase;">
                <?php printf(esc_html__('For all support, contact %s', 'mps-gateway'), esc_html($merchant)); ?>
            </div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;font-size:17px;color:#064e3b;line-height:1.9;">
                <?php if ($contact['email']) : ?>
                    <tr>
                        <td style="padding:0;width:90px;font-weight:700;"><?php esc_html_e('Email', 'mps-gateway'); ?></td>
                        <td style="padding:0;"><a href="mailto:<?php echo esc_attr($contact['email']); ?>" style="color:#047857;font-weight:700;"><?php echo esc_html($contact['email']); ?></a></td>
                    </tr>
                <?php endif; ?>
                <?php if ($contact['phone']) : ?>
                    <tr>
                        <td style="padding:0;font-weight:700;"><?php esc_html_e('Phone', 'mps-gateway'); ?></td>
                        <td style="padding:0;"><a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $contact['phone'])); ?>" style="color:#047857;font-weight:700;text-decoration:none;"><?php echo esc_html($contact['phone']); ?></a></td>
                    </tr>
                <?php endif; ?>
                <?php if ($contact['website']) : ?>
                    <tr>
                        <td style="padding:0;font-weight:700;"><?php esc_html_e('Website', 'mps-gateway'); ?></td>
                        <td style="padding:0;"><a href="<?php echo esc_url($contact['website']); ?>" style="color:#047857;"><?php echo esc_html(preg_replace('#^https?://#', '', untrailingslashit($contact['website']))); ?></a></td>
                    </tr>
                <?php endif; ?>
            </table>
            <p style="margin:16px 0 0;font-size:14px;color:#065f46;">
                <?php printf(esc_html__('%1$s is responsible for assisting you with your order and can handle refunds, returns, cancellations and other transaction-related questions. Contacting %2$s will not help resolve an issue with your purchase.', 'mps-gateway'), esc_html($merchant), esc_html($descriptor)); ?>
            </p>
        </div>

        <h2 style="font-size:17px;margin:0 0 10px;"><?php esc_html_e('Order details', 'mps-gateway'); ?></h2>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;font-size:15px;line-height:1.8;margin:0 0 24px;">
            <tr><td style="padding:0;width:140px;color:#6b7280;"><?php esc_html_e('Order #', 'mps-gateway'); ?></td><td style="padding:0;"><?php echo esc_html($order->get_order_number()); ?></td></tr>
            <tr><td style="padding:0;color:#6b7280;"><?php esc_html_e('Date', 'mps-gateway'); ?></td><td style="padding:0;"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td></tr>
            <?php if ($items) : ?>
                <tr><td style="padding:0;color:#6b7280;"><?php esc_html_e('Product(s)', 'mps-gateway'); ?></td><td style="padding:0;"><?php echo esc_html($items); ?></td></tr>
            <?php endif; ?>
            <tr><td style="padding:0;color:#6b7280;"><?php esc_html_e('Total', 'mps-gateway'); ?></td><td style="padding:0;"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td></tr>
            <tr><td style="padding:0;color:#6b7280;"><?php esc_html_e('Payment method', 'mps-gateway'); ?></td><td style="padding:0;"><?php echo esc_html($order->get_payment_method_title()); ?></td></tr>
        </table>

        <h3 style="font-size:15px;margin:0 0 8px;"><?php esc_html_e("Don't recognise the charge, or have a problem?", 'mps-gateway'); ?></h3>
        <p style="font-size:15px;line-height:1.7;margin:0 0 20px;">
            <?php printf(esc_html__('Please first review the merchant name and order details above. If you have questions about the purchase, contact %s directly using the support information in this email. Again, your statement will show %s, but your customer service contact is %s.', 'mps-gateway'), '<strong>' . esc_html($merchant) . '</strong>', '<strong>' . esc_html($descriptor) . '</strong>', '<strong>' . esc_html($merchant) . '</strong>'); ?>
        </p>
        <p style="font-size:15px;margin:0;"><?php esc_html_e('Thank you for your order.', 'mps-gateway'); ?></p>
        <?php
        wc_get_template('emails/email-footer.php', ['email' => $this]);

        return ob_get_clean();
    }
}
