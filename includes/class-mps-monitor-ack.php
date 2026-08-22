<?php
/**
 * The card-payment disclosure shown beside the charge acknowledgment at checkout.
 *
 * 🛑 This does NOT render a checkbox. An earlier standalone build did, and hid the gateway's own
 * acknowledgment with CSS to avoid showing two. That was fragile in three ways worth remembering:
 * the gateway's box is mandatory and renders pre-ticked, so hiding it did not remove the consent
 * it records — it only removed the customer's sight of the wording they were agreeing to; the
 * replacement was classic-checkout only, so on Block checkout the disclosure never appeared and
 * its server-side validation never ran (the Store API does not fire woocommerce_checkout_process);
 * and it wrote _mps_charge_ack_* itself, duplicating MPS_Base_Gateway's central capture.
 *
 * So consent stays exactly where it was — one mandatory tick-box, captured by
 * mps_capture_charge_acknowledgment() on both checkouts and reported to the portal for the signed
 * chargeback PDF. This class contributes only the explanatory text above it, on both checkouts,
 * from one string.
 *
 * OFF by default. 🛑 The billing descriptor is never named here: pre-transaction disclosure of it
 * is a compliance breach that can get a merchant's processing deactivated (client 2026-08-21). What
 * this shows instead is the client's Billing Notice — the statement name will differ, it is given
 * after payment, and support questions go to the merchant. A merchant who wants that notice at
 * checkout turns this on.
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Ack {

	public static function settings(): array {
		return wp_parse_args(
			(array) get_option( 'mps_monitor_ack_settings', array() ),
			array( 'enabled' => 'no' )
		);
	}

	public static function is_enabled(): bool {
		return 'yes' === self::settings()['enabled'];
	}

	public static function init(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_display' ) );
	}

	/**
	 * The disclosure markup, used by BOTH checkouts so the wording can never drift between them.
	 *
	 * @param object|null $gateway The MPS gateway being rendered. Its portal descriptor decides
	 *                              whether the Billing Notice is shown — it is never printed.
	 */
	public static function disclosure_html( $gateway = null ): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		/*
		 * 🛑 COMPLIANCE — the descriptor is NEVER named before the charge (client 2026-08-21:
		 * "for compliance we cannot put the name of the descriptor before the transaction …
		 * only after the transaction takes place"). It is read here ONLY to decide whether there
		 * is a different statement name to warn about; the value itself must not reach the page.
		 * Naming it belongs to the post-purchase surfaces — thank-you page, order emails and
		 * MPS_Billing_Notice_Email — which all fire after the payment completes.
		 *
		 * No order exists yet at checkout, so it comes off the live gateway config rather than
		 * _mps_descriptor.
		 */
		$descriptor = '';
		if ( is_object( $gateway ) && isset( $gateway->portal_descriptor ) ) {
			$descriptor = trim( (string) $gateway->portal_descriptor );
		}

		/*
		 * 🛑 The first line used to read "Your payment is processed through an overseas banking
		 * partner." Two problems: it describes our processing arrangements to the customer at the
		 * moment they are most likely to go looking (2026-08-20 — an Nxtstate customer googled the
		 * descriptor and phoned the MID holder), and it is not even true on every processor — the
		 * A-Processor stack settles to the merchant's own domestic Authorize.Net. The disclosure
		 * keeps its dispute-defence job: warn about a first-attempt decline, and warn that the
		 * statement name will differ — without saying what it is.
		 */
		$items = array(
			'Some banks may <strong>decline the first attempt</strong> as a fraud protection measure. If that happens, a quick call to your bank to authorize the purchase will normally allow it to go through.',
		);

		// Only warn about the statement name when this merchant actually has a descriptor assigned:
		// without one there is nothing for the statement to differ from, and the sentence would
		// puzzle the customer rather than prepare them. Checked, never printed — see above.
		if ( '' !== $descriptor ) {
			$merchant = class_exists( 'MPS_Merchant_Contact' )
				? ( MPS_Merchant_Contact::name() ?: get_bloginfo( 'name' ) )
				: get_bloginfo( 'name' );

			// The client's own wording, verbatim (2026-08-21), with the merchant name substituted.
			$items[] = sprintf(
				'<strong>Billing Notice:</strong> Your bank statement may show a different name than %1$s. The exact billing name will be provided after your payment is completed. For any order, refund, or support questions, contact %1$s only.',
				esc_html( $merchant )
			);
		}

		$html = '<div class="mps-monitor-disclosure"><p class="mps-monitor-disclosure__intro">'
			. esc_html__( 'Before you pay, please note:', 'mps-gateway' ) . '</p><ul>';
		foreach ( $items as $item ) {
			$html .= '<li>' . $item . '</li>';
		}
		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * Show the acknowledgment already captured by the gateway on the order screen.
	 * Reads MPS's own meta — this class records nothing of its own.
	 */
	public static function admin_display( $order ): void {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_mps_charge_ack_accepted' ) ) {
			return;
		}

		printf(
			'<p><strong>%s</strong><br>%s<br><span style="color:#666;">%s &middot; IP %s</span></p>',
			esc_html__( 'Charge acknowledgment', 'mps-gateway' ),
			esc_html__( 'Accepted by the customer at checkout', 'mps-gateway' ),
			esc_html( (string) $order->get_meta( '_mps_charge_ack_at' ) ),
			esc_html( (string) $order->get_meta( '_mps_charge_ack_ip' ) )
		);
	}
}
