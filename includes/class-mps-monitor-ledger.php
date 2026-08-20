<?php
/**
 * The payment-attempt ledger.
 *
 * Why a separate table rather than reading orders: WooCommerce reuses the same order when a
 * customer retries after a decline, so an order's final status tells you the outcome of the LAST
 * attempt only. Counting wc-failed orders therefore under-reports declines and makes recovery
 * invisible — on gxresearch.shop 133 orders carried a decline in 30 days and 65 of them went on to
 * pay, which the order table alone cannot show. One row per attempt is the only way the dashboard
 * can answer "who was declined, did they recover, were they emailed".
 *
 * @package MPS_Gateway
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Ledger {

	const VERSION = 1;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mps_payment_attempts';
	}

	/**
	 * The table this shipped under while it was the standalone GX Payment Monitor.
	 * A store that ran that build already has real history in it.
	 */
	private static function legacy_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gx_payment_attempts';
	}

	/**
	 * Carry a standalone-build table over to the merged name.
	 *
	 * RENAME rather than create-and-copy: it is atomic, keeps the indexes, and cannot half-finish.
	 * Only runs when the legacy table exists and the new one does not — so it is a no-op on every
	 * store that never ran the standalone plugin, and it can never clobber a populated new table.
	 */
	private static function migrate_legacy_table(): void {
		global $wpdb;
		$new    = self::table();
		$legacy = self::legacy_table();

		$have_new    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
		$have_legacy = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) );

		if ( ! $have_new && $have_legacy ) {
			$wpdb->query( "RENAME TABLE `{$legacy}` TO `{$new}`" );
		}
	}

	public static function install(): void {
		global $wpdb;

		// Before dbDelta, or dbDelta creates an empty table and the history is stranded.
		self::migrate_legacy_table();

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// note_hash is the dedupe key: the same decline can be seen both by the live hook and by a
		// backfill sweep over order notes, and must only ever produce one row.
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				billing_email VARCHAR(190) NOT NULL DEFAULT '',
				billing_name VARCHAR(190) NOT NULL DEFAULT '',
				gateway VARCHAR(64) NOT NULL DEFAULT '',
				outcome VARCHAR(20) NOT NULL DEFAULT '',
				amount DECIMAL(12,2) NOT NULL DEFAULT 0,
				currency VARCHAR(8) NOT NULL DEFAULT 'USD',
				decline_code VARCHAR(16) NOT NULL DEFAULT '',
				iso_code VARCHAR(8) NOT NULL DEFAULT '',
				decline_detail VARCHAR(255) NOT NULL DEFAULT '',
				posture VARCHAR(24) NOT NULL DEFAULT '',
				card_brand VARCHAR(24) NOT NULL DEFAULT '',
				last_four VARCHAR(8) NOT NULL DEFAULT '',
				recovered TINYINT(1) NOT NULL DEFAULT 0,
				recovered_at DATETIME NULL DEFAULT NULL,
				notify_state VARCHAR(24) NOT NULL DEFAULT 'pending',
				notify_at DATETIME NULL DEFAULT NULL,
				notify_note VARCHAR(255) NOT NULL DEFAULT '',
				note_hash CHAR(32) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY note_hash (note_hash),
				KEY order_id (order_id),
				KEY billing_email (billing_email),
				KEY created_at (created_at),
				KEY outcome (outcome),
				KEY notify_state (notify_state)
			) {$collate};"
		);

		// Settings from the standalone build, so mode/delay/cooldown survive the rename.
		if ( false === get_option( 'mps_monitor_settings', false ) ) {
			$legacy = get_option( 'gxpm_settings', false );
			if ( is_array( $legacy ) ) {
				update_option( 'mps_monitor_settings', $legacy );
			}
		}

		update_option( 'mps_monitor_ledger_version', self::VERSION );
	}

	/**
	 * Record one attempt. Returns the row id, or 0 if it was a duplicate we have already seen.
	 */
	public static function record( array $row ): int {
		global $wpdb;

		$row = wp_parse_args(
			$row,
			array(
				'order_id'       => 0,
				'customer_id'    => 0,
				'billing_email'  => '',
				'billing_name'   => '',
				'gateway'        => '',
				'outcome'        => 'declined',
				'amount'         => 0,
				'currency'       => 'USD',
				'decline_code'   => '',
				'iso_code'       => '',
				'decline_detail' => '',
				'posture'        => '',
				'card_brand'     => '',
				'last_four'      => '',
				'recovered'      => 0,
				'recovered_at'   => null,
				'notify_state'   => 'pending',
				'notify_at'      => null,
				'notify_note'    => '',
				'note_hash'      => '',
				'created_at'     => current_time( 'mysql' ),
			)
		);

		if ( '' === $row['note_hash'] ) {
			$row['note_hash'] = md5( $row['order_id'] . '|' . $row['decline_code'] . '|' . $row['created_at'] );
		}

		// INSERT IGNORE via a pre-check keeps $wpdb->insert's escaping while staying idempotent.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE note_hash = %s', $row['note_hash'] )
		);
		if ( $exists ) {
			return 0;
		}

		$wpdb->insert( self::table(), $row );

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $r ?: null;
	}

	/**
	 * Mark every outstanding declined attempt on an order as recovered.
	 * Called when the order reaches a paid status — this is what separates "declined then paid"
	 * from "declined and lost", and it is also what stops a pending email going out.
	 */
	public static function mark_order_recovered( int $order_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET recovered = 1, recovered_at = %s
				 WHERE order_id = %d AND outcome = 'declined' AND recovered = 0",
				current_time( 'mysql' ),
				$order_id
			)
		);
	}

	/** When this email address was last sent a decline notice, as a unix timestamp. 0 = never. */
	public static function last_notified( string $email ): int {
		global $wpdb;
		$when = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(notify_at) FROM ' . self::table() . " WHERE billing_email = %s AND notify_state = 'sent'",
				$email
			)
		);
		return $when ? (int) strtotime( $when ) : 0;
	}

	/**
	 * SQL fragment limiting a query to card gateways.
	 *
	 * Without this the decline rate is meaningless: Zelle, ACH and crypto approvals swamp the
	 * denominator and turn a ~25% card decline rate into ~5%. Scope 'all' returns every gateway,
	 * which is the right view for "did this customer's payment go through" but not for a rate.
	 */
	private static function scope_clause( string $scope ): string {
		if ( 'all' === $scope ) {
			return '';
		}

		global $wpdb;
		$clauses = array();
		foreach ( MPS_Monitor_Capture::card_gateways() as $prefix ) {
			$clauses[] = $wpdb->prepare( 'gateway LIKE %s', $wpdb->esc_like( $prefix ) . '%' );
		}

		return $clauses ? '(' . implode( ' OR ', $clauses ) . ')' : '';
	}

	/** The same clause ready to append to an existing WHERE. */
	private static function scope_sql( string $scope ): string {
		$clause = self::scope_clause( $scope );
		return '' === $clause ? '' : ' AND ' . $clause;
	}

	/** Headline counts for the dashboard, over the last $days days. */
	public static function stats( int $days = 30, string $scope = 'card' ): array {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", (int) current_time( 'timestamp' ) ) );
		$sc    = self::scope_sql( $scope );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(outcome = 'approved') AS approved,
					SUM(outcome = 'declined') AS declined,
					SUM(outcome = 'declined' AND recovered = 1) AS recovered,
					SUM(outcome = 'declined' AND recovered = 0) AS lost,
					SUM(CASE WHEN outcome = 'declined' AND recovered = 0 THEN amount ELSE 0 END) AS lost_value,
					SUM(CASE WHEN outcome = 'approved' THEN amount ELSE 0 END) AS paid_value,
					SUM(outcome = 'blocked') AS blocked,
					SUM(CASE WHEN outcome = 'blocked' THEN amount ELSE 0 END) AS blocked_value,
					SUM(notify_state = 'sent') AS notified,
					COUNT(DISTINCT CASE WHEN outcome = 'declined' THEN billing_email END) AS customers
				FROM {$table} WHERE created_at >= %s{$sc}",
				$since
			),
			ARRAY_A
		);

		$row = array_map( 'floatval', (array) $row );

		/*
		 * Blocked attempts are deliberately NOT in the denominator. They never reached a processor,
		 * so they are neither an approval nor a decline — counting them as attempts would make the
		 * decline rate fall simply because the feature is working, which flatters the number
		 * without telling anyone anything true. They are reported on their own instead.
		 */
		$attempts         = $row['approved'] + $row['declined'];
		$row['attempts']  = $attempts;
		$row['fail_rate'] = $attempts > 0 ? round( 100 * $row['declined'] / $attempts, 1 ) : 0.0;
		$row['recovery_rate'] = $row['declined'] > 0 ? round( 100 * $row['recovered'] / $row['declined'], 1 ) : 0.0;

		return $row;
	}

	/** Decline reasons ranked, for the dashboard breakdown. */
	public static function reason_breakdown( int $days = 30, string $scope = 'card' ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", (int) current_time( 'timestamp' ) ) );
		$sc    = self::scope_sql( $scope );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT decline_code, iso_code, decline_detail, posture, COUNT(*) n,
					SUM(recovered) recovered, SUM(amount) value
				 FROM ' . self::table() . " WHERE outcome = 'declined' AND created_at >= %s{$sc}
				 GROUP BY decline_code ORDER BY n DESC",
				$since
			)
		);
	}

	/**
	 * Row counts behind each dashboard tab, so the tabs can carry numbers the way WordPress
	 * list tables do. One query rather than one per tab.
	 */
	public static function view_counts( int $days = 30, string $scope = 'card' ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", (int) current_time( 'timestamp' ) ) );
		$sc    = self::scope_sql( $scope );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(outcome = 'declined') AS declined,
					SUM(outcome = 'declined' AND recovered = 0) AS lost,
					SUM(outcome = 'declined' AND recovered = 1) AS recovered,
					SUM(notify_state = 'sent') AS emailed,
					SUM(outcome = 'approved') AS approved,
					SUM(outcome = 'blocked') AS blocked,
					COUNT(*) AS everything
				FROM " . self::table() . " WHERE created_at >= %s{$sc}",
				$since
			),
			ARRAY_A
		);

		return array_map( 'intval', (array) $row );
	}

	/** Paged attempt list for the dashboard table. */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array( 'outcome' => 'declined', 'days' => 30, 'search' => '', 'notify_state' => '', 'recovered' => null, 'scope' => 'card', 'per_page' => 50, 'page' => 1 )
		);

		$where  = array( '1=1' );
		$params = array();

		$scope_clause = self::scope_clause( (string) $args['scope'] );
		if ( '' !== $scope_clause ) {
			$where[] = $scope_clause;
		}

		if ( '' !== $args['outcome'] && 'all' !== $args['outcome'] ) {
			$where[]  = 'outcome = %s';
			$params[] = $args['outcome'];
		}
		if ( $args['days'] > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $args['days'] . ' days', (int) current_time( 'timestamp' ) ) );
		}
		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			// 🛑 The order-id clause only applies to a NUMERIC search. It used to cast whatever was
			// typed, so searching an email address gave order_id = 0 — which every blocked attempt
			// carries (they are refused before an order exists), so a name search returned the whole
			// blocked list as if it matched.
			if ( ctype_digit( (string) $args['search'] ) ) {
				$where[]  = '(billing_email LIKE %s OR billing_name LIKE %s OR order_id = %d)';
				$params[] = $like;
				$params[] = $like;
				$params[] = (int) $args['search'];
			} else {
				$where[]  = '(billing_email LIKE %s OR billing_name LIKE %s)';
				$params[] = $like;
				$params[] = $like;
			}
		}
		if ( '' !== $args['notify_state'] ) {
			$where[]  = 'notify_state = %s';
			$params[] = $args['notify_state'];
		}
		if ( null !== $args['recovered'] && '' !== $args['recovered'] ) {
			$where[]  = 'recovered = %d';
			$params[] = (int) $args['recovered'];
		}

		$sql    = 'FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$total  = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) {$sql}", $params ) : "SELECT COUNT(*) {$sql}" );
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];

		$list_params   = $params;
		$list_params[] = (int) $args['per_page'];
		$list_params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * {$sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $list_params ) );

		return array( 'rows' => (array) $rows, 'total' => $total );
	}
}
