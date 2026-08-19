<?php
/**
 * Turns WooCommerce order activity into ledger rows.
 *
 * The MPS Gateway records a decline only in the order-note text — "VP2D declined: [1556] Restricted
 * Card (Special Condition)" — and never writes the code to order meta. Parsing the note is therefore
 * the only way to get the code, and it has the useful side effect of working retroactively, so the
 * dashboard can be backfilled with real history instead of starting empty.
 *
 * @package MPS_Gateway
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Capture {

	/**
	 * Gateways whose declines are card declines — the ones the customer email is for, and the
	 * denominator of the headline decline rate.
	 *
	 * Deliberately just MPS. Bankful was retired on 2026-07-30 and its failures do not write a
	 * parseable decline note, so including it would count thousands of its approvals while missing
	 * its declines — which understated the card decline rate as 6% against a true ~25%. Its history
	 * is still visible under "All payment methods". Add a prefix here if another card gateway goes
	 * live, and make sure its declines are captured too.
	 */
	public static function card_gateways(): array {
		return apply_filters( 'mps_monitor_card_gateways', array( 'mps_' ) );
	}

	public static function is_card_gateway( string $gateway ): bool {
		foreach ( self::card_gateways() as $prefix ) {
			if ( 0 === strpos( $gateway, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public static function init(): void {
		// Master switch. A merchant who has not turned the monitor on gets no hooks at all — no
		// ledger rows, and therefore nothing the notifier could ever act on.
		if ( ! MPS_Monitor_Notifier::is_enabled() ) {
			return;
		}

		// WC_Order::status_transition() fires this action *before* add_status_transition_note()
		// writes the note, so the note can never be read back from here — no priority helps. The
		// gateway's decline text is handed over in the third argument instead, which is why this
		// takes three.
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_failed' ), 20, 3 );

		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 20, 1 );
	}

	/**
	 * Pull the decline code out of a gateway order note.
	 *
	 * Handles the "[code] detail" family used by every MPS processor (VP2D/VP3D/EP2D/EP3D/EP Hosted)
	 * and degrades gracefully for the redirect processors (K/A) which report a bare reason string.
	 * Also lifts the ISO 8583 code the processor tacks onto the end of its detail text.
	 */
	public static function parse_note( string $note ): ?array {
		$note = trim( preg_replace( '/\s+/', ' ', $note ) );

		// WooCommerce appends "Order status changed from X to Y." to the same note — drop it.
		$note = preg_replace( '/\s*Order status changed from .*$/i', '', $note );

		$is_decline = (bool) preg_match(
			'/^(VP2D|VP3D|EP2D|EP3D|EP Hosted|EP2D Callback|EP3D Callback|EP Hosted Callback|EP2D Return|EP3D Return|EP Hosted Return|VP3D Webhook|K-Processor|A-Processor)\b.*?(declined|failed|payment failed)/i',
			$note
		);
		if ( ! $is_decline ) {
			return null;
		}

		$code = '';
		$iso  = '';

		// "declined: [1556] Restricted Card (Special Condition) [62]"
		if ( preg_match( '/\[([0-9A-Z]{2,6})\]\s*(.*)$/i', $note, $m ) ) {
			$code   = $m[1];
			$detail = trim( $m[2] );

			// A trailing "[62]" inside the detail is the ISO code.
			if ( preg_match( '/\[([0-9A-Z]{2})\]\s*$/i', $detail, $m2 ) ) {
				$iso    = strtoupper( $m2[1] );
				$detail = trim( preg_replace( '/\[[0-9A-Z]{2}\]\s*$/i', '', $detail ) );
			}
		} else {
			/*
			 * Redirect processors: "K-Processor: Payment failed — <reason>".
			 *
			 * 🛑 The /u is load-bearing. Without it PCRE works in bytes, so an em-dash in a
			 * character class is three separate bytes and the match eats only the first — leaving
			 * \x80\x94 on the front of the detail. That is invalid UTF-8: it reaches the
			 * dashboard as "??" and can break json_encode on anything carrying it. Written as an
			 * explicit alternation of the separators rather than a class, so no byte of a
			 * multibyte character can be treated as a range endpoint either.
			 */
			$detail = trim( (string) preg_replace( '/^.*?(?:declined|failed)\s*(?::|—|–|-)?\s*/iu', '', $note ) );
		}

		return array(
			'code'   => $code,
			'iso'    => $iso,
			'detail' => mb_substr( $detail ?? '', 0, 250 ),
		);
	}

	/** The most recent decline note on an order, if any. */
	private static function latest_decline_note( WC_Order $order ): ?array {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'limit'    => 10,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			)
		);

		foreach ( (array) $notes as $note ) {
			$parsed = self::parse_note( (string) $note->content );
			if ( $parsed ) {
				$parsed['at'] = $note->date_created ? $note->date_created->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * @param array $status_transition WooCommerce's transition data. Its 'note' key holds the text
	 *                                 the gateway passed to update_status(), before WooCommerce
	 *                                 appends "Order status changed from X to Y." and saves it.
	 */
	public static function on_failed( $order_id, $order = null, $status_transition = array() ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Read the code out of the transition, which is the only copy that exists at this point.
		$parsed = ! empty( $status_transition['note'] )
			? self::parse_note( (string) $status_transition['note'] )
			: null;

		// Transitions made without a note — an admin changing the status by hand, or a gateway that
		// adds its note separately — still fall back to whatever is already on the order.
		if ( ! $parsed ) {
			$parsed = self::latest_decline_note( $order );
		}

		$id = self::write( $order, 'declined', $parsed );

		if ( $id > 0 ) {
			MPS_Monitor_Notifier::schedule( $id );
		}
	}

	public static function on_paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		MPS_Monitor_Ledger::mark_order_recovered( $order->get_id() );
		self::write( $order, 'approved', null );
	}

	/**
	 * Write one attempt row. Returns the new row id, or 0 when it was a duplicate.
	 *
	 * @param array|null $parsed Output of parse_note(), or null for an approval.
	 * @param string     $at     Override the event time (used by the backfill).
	 */
	public static function write( WC_Order $order, string $outcome, ?array $parsed, string $at = '' ): int {
		$gateway = $order->get_payment_method();
		$code    = $parsed['code'] ?? '';
		$iso     = $parsed['iso'] ?? '';
		$when    = $at ?: ( $parsed['at'] ?? current_time( 'mysql' ) );

		$posture = '';
		if ( 'declined' === $outcome ) {
			$posture = MPS_Monitor_Copy::resolve( $code, $iso )['posture'];
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		return MPS_Monitor_Ledger::record(
			array(
				'order_id'       => $order->get_id(),
				'customer_id'    => $order->get_customer_id(),
				'billing_email'  => $order->get_billing_email(),
				'billing_name'   => $name,
				'gateway'        => $gateway,
				'outcome'        => $outcome,
				'amount'         => (float) $order->get_total(),
				'currency'       => $order->get_currency(),
				'decline_code'   => $code,
				'iso_code'       => $iso,
				'decline_detail' => $parsed['detail'] ?? '',
				'posture'        => $posture,
				'card_brand'     => (string) $order->get_meta( '_mps_card_brand' ),
				'last_four'      => (string) $order->get_meta( '_mps_last_four' ),
				'created_at'     => $when,
				// An approval has no per-attempt note to key on, so the order id is the identity.
				'note_hash'      => 'approved' === $outcome
					? md5( 'paid|' . $order->get_id() )
					: md5( $order->get_id() . '|' . $code . '|' . $when ),
				// Backfilled rows must never trigger an email — the moment has long passed.
				'notify_state'   => ( 'declined' === $outcome && '' === $at ) ? 'pending' : 'na',
			)
		);
	}

	/**
	 * Order ids with the given statuses created since $since, asked of WooCommerce rather than of
	 * a table.
	 *
	 * 🛑 An earlier build queried {$wpdb->prefix}wc_orders directly. That table only exists when
	 * HPOS is switched on — on a store still using the legacy post storage the import died with a
	 * SQL error instead of simply finding nothing. wc_get_orders() reads whichever store is
	 * actually in use, so the import works on both.
	 */
	private static function order_ids_since( array $statuses, string $since ): array {
		$ids = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => '>=' . $since,
				'limit'        => -1,
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Rebuild the ledger from order notes so the dashboard opens with real history.
	 * Safe to run repeatedly — note_hash makes every row idempotent.
	 *
	 * @return array{declines:int,approvals:int,scanned:int}
	 */
	public static function backfill( int $days = 60 ): array {
		global $wpdb;

		$since   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$results = array( 'declines' => 0, 'approvals' => 0, 'scanned' => 0 );

		// Decline notes first — these carry the code and the real event time.
		$notes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID AS order_id, comment_content AS content, comment_date AS at
				 FROM {$wpdb->comments}
				 WHERE comment_type = 'order_note' AND comment_date >= %s
				   AND (comment_content LIKE '%%declined%%' OR comment_content LIKE '%%Payment failed%%'
				        OR comment_content LIKE '%%payment failed%%')
				 ORDER BY comment_date ASC",
				$since
			)
		);

		foreach ( (array) $notes as $note ) {
			++$results['scanned'];
			$parsed = self::parse_note( (string) $note->content );
			if ( ! $parsed ) {
				continue;
			}
			$order = wc_get_order( (int) $note->order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( self::write( $order, 'declined', $parsed, (string) $note->at ) > 0 ) {
				++$results['declines'];
			}
		}

		// Failed orders whose gateway did not leave a parseable note (Bankful, Link Money, DePay).
		// Without this they would be missing from the ledger entirely while their approvals were
		// counted, which is exactly what skews a decline rate.
		$already = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT order_id FROM ' . MPS_Monitor_Ledger::table() . " WHERE outcome = 'declined' AND created_at >= %s",
				$since
			)
		);
		$already = array_map( 'intval', (array) $already );
		$unnoted = array_diff( self::order_ids_since( array( 'failed' ), $since ), $already );

		foreach ( $unnoted as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$created = $order->get_date_created();
			if ( self::write( $order, 'declined', null, $created ? $created->date( 'Y-m-d H:i:s' ) : '' ) > 0 ) {
				++$results['declines'];
			}
		}

		// Then paid orders, so approval volume and the recovery flag are both correct.
		$paid = self::order_ids_since( array( 'completed', 'processing' ), $since );

		foreach ( $paid as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$created = $order->get_date_created();
			if ( self::write( $order, 'approved', null, $created ? $created->date( 'Y-m-d H:i:s' ) : '' ) > 0 ) {
				++$results['approvals'];
			}
			MPS_Monitor_Ledger::mark_order_recovered( (int) $order_id );
		}

		return $results;
	}
}
