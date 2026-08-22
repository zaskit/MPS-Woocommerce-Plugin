<?php
/**
 * Decides whether a declined customer gets an email, and sends it.
 *
 * Three rules, in order:
 *   1. Wait. Roughly half the customers who are declined retry and succeed within minutes
 *      (65 of 133 on gxresearch.shop in the 30 days to 2026-08-02). Emailing immediately tells
 *      a paying customer their order failed. So the decision is deferred.
 *   2. Never mail someone who has since paid.
 *   3. One email per customer per cooldown window, however many times they are declined —
 *      the client's requirement, and the difference between helpful and harassment.
 *
 * Until the "live" switch is thrown the notifier runs in dry-run: every decision is recorded and
 * the email is rendered and stored for preview, but nothing is handed to wp_mail().
 *
 * @package MPS_Gateway
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Notifier {

	const HOOK = 'mps_monitor_send_decline_notice';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );

		// When our email is live, WooCommerce's own "your order was unsuccessful" must stand down,
		// or the customer gets both. Filtering is reversible; changing WC's saved setting is not.
		add_filter(
			'woocommerce_email_enabled_customer_failed_order',
			static function ( $enabled ) {
				return self::is_live() ? false : $enabled;
			},
			99
		);
	}

	/**
	 * 🛑 No store-specific address is hardcoded here, and none is a saved setting.
	 *
	 * This plugin ships to every merchant on the portal. A default address baked into the code, or
	 * one saved into an option on one store and carried into a zip, would email another merchant's
	 * customers a support address that is not theirs. So the contact details are READ FROM THE SITE
	 * on every send, through MPS_Merchant_Contact: what the merchant typed on the MPS settings page,
	 * else WooCommerce's own "from" address, else the WordPress admin email — and never a
	 * placeholder address.
	 */
	public static function contact_email(): string {
		// Delegates to MPS_Merchant_Contact, which applies the same resolution AND rejects the
		// placeholder addresses a default install ships with (dev-email@wpengine.local and friends).
		// This used to accept anything is_email() liked, so a store that had never set a real
		// address told declined customers to write to a black hole — the surest way to lose an
		// order we were trying to recover.
		return class_exists( 'MPS_Merchant_Contact' ) ? MPS_Merchant_Contact::email() : '';
	}

	/**
	 * Payment methods this store ACTUALLY offers besides the card gateways, as [label, ...].
	 *
	 * 🛑 The email used to name Zelle, ACH and cryptocurrency outright. Those are one merchant's
	 * methods, hardcoded into a plugin that ships to every merchant on the portal — so a store
	 * offering none of them promised a declined customer three ways to pay that do not exist. What
	 * is offered is read from WooCommerce at send time instead, and the block is omitted when the
	 * store has nothing else enabled.
	 */
	public static function alternative_methods(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$labels = array();
		foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
			if ( 0 === strpos( $gateway->id, 'mps_' ) ) {
				continue;   // another card attempt is not an alternative to a card decline
			}
			$title = wp_strip_all_tags( $gateway->get_title() );
			if ( '' !== $title ) {
				$labels[] = $title;
			}
		}

		return array_slice( array_unique( $labels ), 0, 4 );
	}

	/**
	 * The billing descriptor for this order, as assigned to the merchant by the MPS portal.
	 *
	 * Read off the order (every processor writes _mps_descriptor through store_order_meta), so the
	 * email states the descriptor that was actually in force for THAT charge — not whatever the
	 * portal happens to return today. Falls back to the live gateway config for an order that
	 * predates the meta, and returns '' if neither knows, in which case the descriptor lines are
	 * omitted rather than printed empty.
	 */
	public static function descriptor( WC_Order $order ): string {
		$descriptor = trim( (string) $order->get_meta( '_mps_descriptor' ) );
		if ( '' !== $descriptor ) {
			return $descriptor;
		}

		if ( class_exists( 'MPS_Portal_Client' ) ) {
			$method = $order->get_payment_method();
			foreach ( (array) MPS_Portal_Client::get_gateways() as $gateway ) {
				$id = 'mps_' . strtolower( (string) ( $gateway['processor_code'] ?? '' ) );
				if ( ! empty( $gateway['descriptor'] ) && 0 === strpos( (string) $method, $id ) ) {
					return trim( (string) $gateway['descriptor'] );
				}
			}
		}

		return '';
	}

	public static function settings(): array {
		return wp_parse_args(
			(array) get_option( 'mps_monitor_settings', array() ),
			array(
				'enabled'       => 'no',     // master switch; off until the merchant turns it on
				'mode'          => 'dry_run', // dry_run | live
				'delay_minutes' => 20,
				'cooldown_days' => 7,
			)
		);
	}

	/** The whole monitor is off until a merchant deliberately enables it. */
	public static function is_enabled(): bool {
		return 'yes' === self::settings()['enabled'];
	}

	public static function is_live(): bool {
		return 'live' === self::settings()['mode'];
	}

	/** Queue the decision for later. Action Scheduler if WooCommerce provides it, else WP-Cron. */
	public static function schedule( int $ledger_id ): void {
		$row = MPS_Monitor_Ledger::get( $ledger_id );
		if ( ! $row || 'declined' !== $row->outcome ) {
			return;
		}
		if ( ! MPS_Monitor_Capture::is_card_gateway( $row->gateway ) ) {
			MPS_Monitor_Ledger::update( $ledger_id, array( 'notify_state' => 'na', 'notify_note' => 'Not a card gateway' ) );
			return;
		}
		if ( ! is_email( $row->billing_email ) ) {
			MPS_Monitor_Ledger::update( $ledger_id, array( 'notify_state' => 'na', 'notify_note' => 'No usable email address' ) );
			return;
		}

		$when = time() + ( (int) self::settings()['delay_minutes'] * MINUTE_IN_SECONDS );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::HOOK, array( $ledger_id ), 'mps-gateway' );
		} else {
			wp_schedule_single_event( $when, self::HOOK, array( $ledger_id ) );
		}
	}

	/**
	 * Make the call for one ledger row and act on it.
	 *
	 * @param bool $force Skip the cooldown and recovery checks (admin "resend" button).
	 */
	public static function run( $ledger_id, bool $force = false ): string {
		$ledger_id = (int) $ledger_id;
		$row       = MPS_Monitor_Ledger::get( $ledger_id );

		if ( ! $row ) {
			return 'missing';
		}
		// A scheduled action outlives a settings change: an email queued before the monitor was
		// switched off must not still land twenty minutes later.
		if ( ! self::is_enabled() ) {
			return 'disabled';
		}
		if ( 'pending' !== $row->notify_state && ! $force ) {
			return 'already_decided';
		}

		$order = wc_get_order( (int) $row->order_id );
		if ( ! $order instanceof WC_Order ) {
			MPS_Monitor_Ledger::update( $ledger_id, array( 'notify_state' => 'na', 'notify_note' => 'Order no longer exists' ) );
			return 'na';
		}

		// Rule 2 — they got through in the meantime.
		if ( ! $force && ( $order->is_paid() || $row->recovered ) ) {
			MPS_Monitor_Ledger::update(
				$ledger_id,
				array( 'notify_state' => 'suppressed_recovered', 'notify_note' => 'Customer completed payment before the notice was due' )
			);
			return 'suppressed_recovered';
		}

		// Rule 3 — one per customer per window.
		$settings = self::settings();
		$cooldown = (int) $settings['cooldown_days'] * DAY_IN_SECONDS;
		$last     = MPS_Monitor_Ledger::last_notified( $row->billing_email );

		if ( ! $force && $last > 0 && ( time() - $last ) < $cooldown ) {
			$days = max( 1, (int) ceil( ( $cooldown - ( time() - $last ) ) / DAY_IN_SECONDS ) );
			MPS_Monitor_Ledger::update(
				$ledger_id,
				array(
					'notify_state' => 'suppressed_cooldown',
					'notify_note'  => sprintf( 'Already emailed %s ago; next eligible in %d day(s)', human_time_diff( $last ), $days ),
				)
			);
			return 'suppressed_cooldown';
		}

		$copy    = MPS_Monitor_Copy::resolve( (string) $row->decline_code, (string) $row->iso_code );
		$subject = self::subject( $copy );
		$body    = self::render( $row, $order, $copy );

		// Dry run — decide and record, but send nothing.
		if ( ! self::is_live() && ! $force ) {
			MPS_Monitor_Ledger::update(
				$ledger_id,
				array(
					'notify_state' => 'would_send',
					'notify_at'    => current_time( 'mysql' ),
					'notify_note'  => 'DRY RUN — not sent. Reason matched: ' . $copy['matched'],
				)
			);
			return 'would_send';
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Reply-To only if the site has a usable address; wp_mail's own default is fine otherwise.
		$contact = self::contact_email();
		if ( '' !== $contact ) {
			$headers[] = 'Reply-To: ' . $contact;
		}

		$sent = wp_mail( $row->billing_email, $subject, $body, $headers );

		MPS_Monitor_Ledger::update(
			$ledger_id,
			array(
				'notify_state' => $sent ? 'sent' : 'send_failed',
				'notify_at'    => current_time( 'mysql' ),
				'notify_note'  => $sent
					? 'Reason matched: ' . $copy['matched']
					: 'wp_mail() returned false — check FluentSMTP logs',
			)
		);

		if ( $sent ) {
			$order->add_order_note(
				sprintf( 'Payment Monitor: decline notice emailed to the customer (%s).', $row->billing_email )
			);
		}

		return $sent ? 'sent' : 'send_failed';
	}

	/**
	 * The store's wording, 2026-08-03 — one subject line for every decline posture.
	 * $copy is still accepted so the signature survives if they later want it split back
	 * out per posture (the terminal "do not retry" case reads oddly as "Action Needed").
	 */
	public static function subject( array $copy ): string {
		return 'Action Needed: Payment for Your Order Could Not Be Processed';
	}

	/**
	 * The email body. Built on the client's 2026-08-01 draft, with three changes:
	 * the specific decline reason leads (his draft gave every customer the overseas-bank
	 * explanation, which is wrong for a mistyped CVV), a one-click link back to the exact
	 * failed order is included (his draft had no link at all), and the alternative payment
	 * methods are only offered where they would actually help.
	 */
	public static function render( object $row, WC_Order $order, array $copy ): string {
		$store      = get_bloginfo( 'name' );
		// The merchant as the customer knows them, plus a support address we are willing to print.
		// Both come from MPS_Merchant_Contact so this email says exactly what the thank-you page and
		// the billing notice say, and never prints a placeholder address (2026-08-20).
		$merchant   = class_exists( 'MPS_Merchant_Contact' ) ? ( MPS_Merchant_Contact::name() ?: $store ) : $store;
		$phone      = class_exists( 'MPS_Merchant_Contact' ) ? MPS_Merchant_Contact::phone() : '';
		$contact    = self::contact_email();
		$descriptor = self::descriptor( $order );
		$pay_url    = $order->get_checkout_payment_url();
		$name     = $row->billing_name ? explode( ' ', trim( $row->billing_name ) )[0] : '';
		$greeting = $name ? 'Hello ' . esc_html( $name ) . ',' : 'Hello,';
		$terminal = MPS_Monitor_Copy::DO_NOT_RETRY === $copy['posture'];

		$accent = $terminal ? '#b91c1c' : '#047857';
		$tint   = $terminal ? '#fef2f2' : '#ecfdf5';
		$border = $terminal ? '#fecaca' : '#a7f3d0';

		ob_start();
		?>
<div style="margin:0;padding:24px 12px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">

	<div style="padding:28px 32px 8px;">
		<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#111827;"><?php echo $greeting; ?></p>
		<p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#374151;">
			We were not able to complete the payment for your recent order
			<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
			(<?php echo wp_kses_post( wc_price( $row->amount, array( 'currency' => $row->currency ) ) ); ?>).
			Your order is being held and nothing has been charged.
		</p>
		<?php /* 🛑 The descriptor used to be named here, and again in the payments note below
		         (client request 2026-08-04, so a customer would recognise it on a statement).
		         Both are gone as of 2026-08-21: naming the descriptor before the transaction has
		         taken place is a compliance breach that can get the merchant's processing
		         deactivated — and on THIS email it was not even true, because the payment failed
		         and nothing reached the customer's statement. What is left is the billing notice
		         below, which warns that the name will differ without saying what it is. */ ?>
	</div>

	<div style="margin:0 32px 22px;padding:18px 20px;background:<?php echo esc_attr( $tint ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-left:5px solid <?php echo esc_attr( $accent ); ?>;border-radius:8px;">
		<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $accent ); ?>;margin-bottom:8px;">What happened</div>
		<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#1f2937;"><?php echo esc_html( $copy['reason'] ); ?></p>
		<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $accent ); ?>;margin-bottom:8px;">What to do next</div>
		<p style="margin:0;font-size:15px;line-height:1.6;color:#1f2937;"><?php echo esc_html( $copy['action'] ); ?></p>
	</div>

	<?php if ( ! $terminal ) : ?>
	<div style="padding:0 32px 26px;text-align:center;">
		<a href="<?php echo esc_url( $pay_url ); ?>" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-size:16px;font-weight:600;padding:14px 32px;border-radius:8px;">Complete your order</a>
		<p style="margin:10px 0 0;font-size:13px;color:#6b7280;">Your basket is saved — this link takes you straight to payment.</p>
	</div>
	<?php endif; ?>

	<?php /* Why the bank may have refused, without describing our processing arrangements. The
	         paragraph that used to sit here explained that payments "are handled through an overseas
	         banking partner" — one merchant's wording, sent to every merchant's customers, and it
	         invited the customer to go looking into who really takes the money. That is exactly how
	         a customer ended up phoning the descriptor holder (2026-08-20). */ ?>
	<div style="margin:0 32px 24px;padding:18px 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
		<p style="margin:0<?php echo '' !== $descriptor ? ' 0 12px' : ''; ?>;font-size:14px;line-height:1.6;color:#374151;">
			<strong>Why this can happen.</strong>
			Card issuers sometimes refuse a first attempt as a routine fraud check, especially on a
			purchase they have not seen before. A quick call to your bank to approve the purchase
			normally clears it, and a different card usually works too.
		</p>
		<?php /* The client's own Billing Notice wording (2026-08-21), written for the moment
		         before a payment completes. $descriptor is read only to know that a different
		         statement name exists — it is never printed on this email. */ ?>
		<?php if ( '' !== $descriptor ) : ?>
		<p style="margin:0;padding:12px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:14px;line-height:1.6;color:#451a03;">
			<strong>Billing Notice:</strong> Your bank statement may show a different name than
			<?php echo esc_html( $merchant ); ?>. The exact billing name will be provided after your
			payment is completed. For any order, refund, or support questions, contact
			<?php echo esc_html( $merchant ); ?> only.
		</p>
		<?php endif; ?>
	</div>

	<?php
	/* Only offered when the STORE actually has another method enabled — read from WooCommerce at
	   send time. @see alternative_methods() */
	$alts = ! empty( $copy['alt'] ) ? self::alternative_methods() : array();
	?>
	<?php if ( $alts ) : ?>
	<div style="padding:0 32px 24px;">
		<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:10px;">Prefer not to use a card?</div>
		<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#374151;">
			This order can also be paid by
			<strong><?php echo implode( '</strong>, <strong>', array_map( 'esc_html', $alts ) ); ?></strong>,
			which is not subject to card-issuer declines.
		</p>
		<a href="<?php echo esc_url( $pay_url ); ?>" style="font-size:15px;color:#111827;font-weight:600;">Choose a different payment method &rarr;</a>
	</div>
	<?php endif; ?>

	<div style="padding:20px 32px 28px;border-top:1px solid #e5e7eb;">
		<?php /* One contact sentence, not two — this absorbed the "help switching to one of these"
		         line that used to sit in the alternative-payments block. Client request 2026-08-04.
		         The middle clause only appears when that block was actually shown, so "one of the
		         methods above" always has something to refer to. */ ?>
		<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#374151;">
			If you have any trouble at all<?php echo $alts ? ', or would like help switching to one of the payment methods above' : ''; ?>,
			contact <?php echo esc_html( $merchant ); ?><?php if ( '' !== $contact ) : ?>
			at <a href="mailto:<?php echo esc_attr( $contact ); ?>" style="color:#111827;font-weight:600;"><?php echo esc_html( $contact ); ?></a><?php endif; ?><?php if ( '' !== $phone ) : ?>
			or <a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ); ?>" style="color:#111827;font-weight:600;text-decoration:none;"><?php echo esc_html( $phone ); ?></a><?php endif; ?>
			and our team will help you finish your order.
		</p>
		<?php /* The "You can also reach us on <phone>, Monday to Friday, 9am-5pm CST" line was
		         removed 2026-08-05 at James's request — no phone number or opening hours on any
		         customer email. Email is the only contact route printed here now. The
		         support_phone setting and its admin field were removed with it so the value
		         cannot quietly reappear. */ ?>
		<p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#6b7280;">
			Thank you for choosing <?php echo esc_html( $merchant ); ?>.<br>
			<span style="color:#9ca3af;">The <?php echo esc_html( $merchant ); ?> Team</span>
		</p>
		<?php /* No "unmonitored address / do not reply" line any more: Reply-To is now the store's
		         own WooCommerce address, so a reply reaches the merchant. Telling a customer not to
		         reply to an address that works is how a recoverable order gets abandoned. */ ?>
		<?php if ( '' !== $contact ) : ?>
		<p style="margin:0;padding-top:14px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.6;color:#9ca3af;">
			Reply to this email, or write to
			<a href="mailto:<?php echo esc_attr( $contact ); ?>" style="color:#6b7280;"><?php echo esc_html( $contact ); ?></a>, and we will help you finish your order.
		</p>
		<?php endif; ?>
	</div>

</div>
</div>
		<?php
		return (string) ob_get_clean();
	}
}
