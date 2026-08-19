<?php
/**
 * WooCommerce → Payment Monitor.
 *
 * One screen that answers the three questions the client asked for: who paid, who was declined
 * and why, and whether we have contacted them about it.
 *
 * @package MPS_Gateway
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'mps-gateway';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mps_monitor_action', array( __CLASS__, 'handle_post' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Payment Monitor', 'mps-gateway' ),
			__( 'Payment Monitor', 'mps-gateway' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The dashboard tabs. Each slices the transaction ledger — the question is always
	 * "which transactions", never "which gateway", because MPS is the only card processor here.
	 *
	 * 'count' names the key returned by MPS_Monitor_Ledger::view_counts().
	 */
	private static function views(): array {
		return array(
			'declined'  => array(
				'label' => 'Failed transactions',
				'count' => 'declined',
				'query' => array( 'outcome' => 'declined' ),
				'hint'  => 'Every card payment the processor declined, newest first.',
			),
			'lost'      => array(
				'label' => 'Still unpaid',
				'count' => 'lost',
				'query' => array( 'outcome' => 'declined', 'recovered' => 0 ),
				'hint'  => 'Declined and never paid since. This is the revenue actually at stake.',
			),
			'recovered' => array(
				'label' => 'Recovered',
				'count' => 'recovered',
				'query' => array( 'outcome' => 'declined', 'recovered' => 1 ),
				'hint'  => 'Declined at least once, then completed payment.',
			),
			'emailed'   => array(
				'label' => 'Emailed',
				'count' => 'emailed',
				'query' => array( 'outcome' => 'all', 'notify_state' => 'sent' ),
				'hint'  => 'Customers who have actually been sent a decline notice.',
			),
			'blocked'   => array(
				'label' => 'Blocked before processing',
				'count' => 'blocked',
				'query' => array( 'outcome' => 'blocked' ),
				'hint'  => 'Cards refused at checkout because the processor never approves that BIN. These never reached a processor, so they are not declines — this is the decline that did not happen.',
			),
			'approved'  => array(
				'label' => 'Approved',
				'count' => 'approved',
				'query' => array( 'outcome' => 'approved' ),
				'hint'  => 'Card payments that went through first time or after a retry.',
			),
			'all'       => array(
				'label' => 'All transactions',
				'count' => 'everything',
				'query' => array( 'outcome' => 'all' ),
				'hint'  => 'Every recorded card payment attempt, approved and declined.',
			),
		);
	}

	/** Form posts: settings, backfill, resend, preview. */
	public static function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mps-gateway' ) );
		}
		check_admin_referer( 'mps_monitor_action' );

		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$notice = '';

		switch ( $do ) {
			case 'save_settings':
				$mode = ( isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ) ? 'live' : 'dry_run';
				update_option(
					'mps_monitor_settings',
					array(
						// No reply-to or contact address is stored: both are read from the site at
						// send time, so a zip built on one store cannot carry another merchant's
						// address to a different merchant's customers.
						'enabled'       => empty( $_POST['monitor_enabled'] ) ? 'no' : 'yes',
						'mode'          => $mode,
						'delay_minutes' => max( 0, (int) ( $_POST['delay_minutes'] ?? 20 ) ),
						'cooldown_days' => max( 1, (int) ( $_POST['cooldown_days'] ?? 7 ) ),
					)
				);
				update_option(
					'mps_monitor_ack_settings',
					// No descriptor field: it comes from the portal's assignment for this merchant.
					array( 'enabled' => empty( $_POST['ack_enabled'] ) ? 'no' : 'yes' )
				);
				$notice = 'live' === $mode
					? 'Settings saved. Customer emails are now LIVE — WooCommerce\'s own failed-order email has been stood down.'
					: 'Settings saved. Customer emails remain in DRY RUN — nothing is being sent.';
				break;

			case 'backfill':
				$r      = MPS_Monitor_Capture::backfill( 60 );
				$notice = sprintf(
					'Backfill complete — %d decline(s) and %d payment(s) imported from %d order notes scanned.',
					$r['declines'],
					$r['approvals'],
					$r['scanned']
				);
				break;

			case 'resend':
				$id     = (int) ( $_POST['ledger_id'] ?? 0 );
				$result = MPS_Monitor_Notifier::run( $id, true );
				$notice = 'sent' === $result
					? 'Email sent.'
					: 'Could not send (' . $result . '). Check the FluentSMTP log.';
				break;
		}

		wp_safe_redirect( self::url( array( 'mps_monitor_notice' => rawurlencode( $notice ) ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Email preview opens in its own frame so the layout is seen as the customer sees it.
		if ( isset( $_GET['preview'] ) ) {
			self::render_preview( (int) $_GET['preview'] );
			return;
		}

		$days   = isset( $_GET['days'] ) ? max( 1, (int) $_GET['days'] ) : 30;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		/*
		 * Always card gateways. MPS is the only card processor on this store, and mixing Zelle,
		 * ACH and crypto approvals into the denominator turns a ~34% card decline rate into ~5%.
		 * The tabs slice transactions instead, which is what the figures are actually about.
		 */
		$scope = 'card';

		$views = self::views();
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'declined';
		if ( ! isset( $views[ $view ] ) ) {
			$view = 'declined';
		}

		$settings = MPS_Monitor_Notifier::settings();
		$ack      = MPS_Monitor_Ack::settings();
		$live     = MPS_Monitor_Notifier::is_live();
		$enabled  = MPS_Monitor_Notifier::is_enabled();

		/*
		 * With the monitor switched off there is no ledger table to read — it is not created until
		 * a merchant opts in, so that an update delivered to every store does not add a table to a
		 * database whose owner never asked for one. Query nothing, show the settings form, and let
		 * them turn it on. Reading first would print raw SQL errors across the screen.
		 */
		if ( ! $enabled ) {
			self::render_disabled( $settings, $ack );
			return;
		}

		$stats  = MPS_Monitor_Ledger::stats( $days, $scope );
		$counts = MPS_Monitor_Ledger::view_counts( $days, $scope );

		$result = MPS_Monitor_Ledger::query(
			array_merge(
				$views[ $view ]['query'],
				array( 'days' => $days, 'search' => $search, 'scope' => $scope, 'per_page' => 50, 'page' => $paged )
			)
		);
		?>
		<div class="wrap mps-monitor">
			<h1><?php esc_html_e( 'Payment Monitor', 'mps-gateway' ); ?></h1>

			<?php if ( ! empty( $_GET['mps_monitor_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['mps_monitor_notice'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="notice <?php echo $live ? 'notice-success' : 'notice-warning'; ?>">
				<p>
					<strong><?php echo $live ? 'Customer emails are LIVE.' : 'Customer emails are in DRY RUN.'; ?></strong>
					<?php if ( $live ) : ?>
						Declined customers are being emailed after <?php echo (int) $settings['delay_minutes']; ?> minutes,
						at most once every <?php echo (int) $settings['cooldown_days']; ?> days.
						WooCommerce&rsquo;s own &ldquo;order was unsuccessful&rdquo; email is stood down.
					<?php else : ?>
						Every decline is recorded and the email is rendered for preview, but nothing is sent.
						WooCommerce&rsquo;s own &ldquo;order was unsuccessful&rdquo; email is still the one reaching customers.
					<?php endif; ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'MPS card transactions', 'mps-gateway' ); ?></h2>

			<h2 class="nav-tab-wrapper mps-monitor-views">
				<?php foreach ( $views as $key => $v ) : ?>
					<a class="nav-tab <?php echo $view === $key ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( self::url( array( 'view' => $key, 'days' => $days, 's' => $search ) ) ); ?>">
						<?php echo esc_html( $v['label'] ); ?>
						<span class="mps-monitor-tabcount"><?php echo esc_html( number_format_i18n( $counts[ $v['count'] ] ?? 0 ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</h2>
			<p class="description mps-monitor-view-hint"><?php echo esc_html( $views[ $view ]['hint'] ); ?></p>

			<form method="get" class="mps-monitor-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
				<select name="days">
					<?php foreach ( array( 1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days' ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $days, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Email, name or order #" />
				<?php submit_button( __( 'Filter', 'mps-gateway' ), 'secondary', '', false ); ?>
				<span class="mps-monitor-count"><?php echo esc_html( number_format_i18n( $result['total'] ) ); ?> row(s)</span>
			</form>

			<table class="widefat striped mps-monitor-table">
				<thead>
					<tr>
						<th>When</th><th>Customer</th><th>Order</th><th class="num">Amount</th>
						<th>Outcome</th><th>Decline reason</th><th>Paid<br>since?</th>
						<th>Email sent?</th><th>Sent at</th><th>The email</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $result['rows'] ) : ?>
					<tr><td colspan="10"><em>Nothing to show for these filters.</em></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'j M, H:i', $row->created_at ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $row->billing_name ?: '—' ); ?></strong><br>
							<span class="mps-monitor-muted"><?php echo esc_html( $row->billing_email ); ?></span>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row->order_id . '&action=edit' ) ); ?>">#<?php echo (int) $row->order_id; ?></a><br>
							<span class="mps-monitor-muted"><?php echo esc_html( $row->card_brand ? ucfirst( $row->card_brand ) . ' ••••' . $row->last_four : $row->gateway ); ?></span>
						</td>
						<td class="num"><?php echo wp_kses_post( wc_price( $row->amount, array( 'currency' => $row->currency ) ) ); ?></td>
						<td>
							<?php
							$tone = 'fail';
							if ( 'approved' === $row->outcome ) { $tone = 'ok'; }
							elseif ( 'blocked' === $row->outcome ) { $tone = 'warn'; }
							?>
							<span class="mps-monitor-pill mps-monitor-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( ucfirst( $row->outcome ) ); ?></span>
						</td>
						<td>
							<?php if ( 'declined' === $row->outcome ) : ?>
								<code><?php echo esc_html( $row->decline_code ?: '—' ); ?></code>
								<?php echo esc_html( $row->decline_detail ); ?>
							<?php else : ?>—<?php endif; ?>
						</td>
						<td>
							<?php if ( 'declined' !== $row->outcome ) : ?>—
							<?php elseif ( $row->recovered ) : ?><span class="mps-monitor-pill mps-monitor-pill--ok">Yes</span>
							<?php else : ?><span class="mps-monitor-pill mps-monitor-pill--fail">No</span><?php endif; ?>
						</td>
						<td><?php self::notify_cell( $row ); ?></td>
						<td class="mps-monitor-nowrap">
							<?php if ( $row->notify_at ) : ?>
								<?php echo esc_html( mysql2date( 'j M Y', $row->notify_at ) ); ?><br>
								<span class="mps-monitor-muted"><?php echo esc_html( mysql2date( 'H:i', $row->notify_at ) ); ?>
									&middot; <?php echo esc_html( human_time_diff( strtotime( $row->notify_at ), (int) current_time( 'timestamp' ) ) ); ?> ago</span>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td class="mps-monitor-actions">
							<?php if ( 'declined' === $row->outcome ) : ?>
								<a class="button button-small" target="_blank" href="<?php echo esc_url( self::url( array( 'preview' => $row->id ) ) ); ?>">View email</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'mps_monitor_action' ); ?>
									<input type="hidden" name="action" value="mps_monitor_action" />
									<input type="hidden" name="do" value="resend" />
									<input type="hidden" name="ledger_id" value="<?php echo (int) $row->id; ?>" />
									<button type="submit" class="button button-small" onclick="return confirm('Send this email to <?php echo esc_attr( $row->billing_email ); ?> now?');"><?php echo 'sent' === $row->notify_state ? 'Resend' : 'Send now'; ?></button>
								</form>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $result['total'] / 50 );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => self::url( array( 'view' => $view, 'days' => $days, 's' => $search ) ) . '%_%',
							'format'  => '&paged=%#%',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>

			<?php // Now that transactions lead the page, the summary needs a real heading of its own. ?>
			<h2><?php printf( esc_html__( 'Summary — last %d days', 'mps-gateway' ), (int) $days ); ?></h2>

			<div class="mps-monitor-cards">
				<?php
				self::card( 'Card attempts', number_format_i18n( $stats['attempts'] ), $days . ' days' );
				self::card( 'Approved', number_format_i18n( $stats['approved'] ), wc_price( $stats['paid_value'] ), 'good' );
				self::card( 'Declined', number_format_i18n( $stats['declined'] ), $stats['fail_rate'] . '% of attempts', 'bad' );
				self::card( 'Recovered after decline', number_format_i18n( $stats['recovered'] ), $stats['recovery_rate'] . '% of declines', 'good' );
				self::card( 'Lost', number_format_i18n( $stats['lost'] ), wc_price( $stats['lost_value'] ) . ' not collected', 'bad' );
				self::card( 'Customers emailed', number_format_i18n( $stats['notified'] ), $stats['customers'] . ' distinct customers declined' );
				// Not part of the attempt figures above — see the note in MPS_Monitor_Ledger::stats().
				self::card( 'Blocked before processing', number_format_i18n( $stats['blocked'] ), 'declines prevented', 'good' );
				?>
			</div>

			<h2><?php esc_html_e( 'Why payments are being declined', 'mps-gateway' ); ?></h2>
			<table class="widefat striped mps-monitor-reasons">
				<thead>
					<tr>
						<th>Code</th><th>Reason reported by the processor</th><th>What we tell the customer to do</th>
						<th class="num">Declines</th><th class="num">Recovered</th><th class="num">Value</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$reasons = MPS_Monitor_Ledger::reason_breakdown( $days, $scope );
				if ( ! $reasons ) :
					?>
					<tr><td colspan="6"><em>No declines recorded in this period. If this store has history, run the backfill below.</em></td></tr>
				<?php endif; ?>
				<?php foreach ( $reasons as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->decline_code ?: '—' ); ?></code><?php echo $r->iso_code ? ' <span class="mps-monitor-iso">ISO ' . esc_html( $r->iso_code ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( $r->decline_detail ?: '—' ); ?></td>
						<td><span class="mps-monitor-pill mps-monitor-pill--<?php echo esc_attr( $r->posture ); ?>"><?php echo esc_html( MPS_Monitor_Copy::posture_label( (string) $r->posture ) ); ?></span></td>
						<td class="num"><strong><?php echo esc_html( number_format_i18n( $r->n ) ); ?></strong></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $r->recovered ) ); ?></td>
						<td class="num"><?php echo wp_kses_post( wc_price( $r->value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>


			<h2><?php esc_html_e( 'Settings', 'mps-gateway' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mps_monitor_action' ); ?>
				<input type="hidden" name="action" value="mps_monitor_action" />
				<input type="hidden" name="do" value="save_settings" />
				<table class="form-table">
					<tr>
						<th scope="row">Transaction monitoring</th>
						<td>
							<label><input type="checkbox" name="monitor_enabled" value="1" <?php checked( 'yes', $settings['enabled'] ); ?> /> Record every card payment attempt on this store</label>
							<p class="description">
								Off by default. While off, nothing is recorded and no email can be sent, whatever the
								settings below say. Turning it on starts the ledger from that moment &mdash; use
								<em>Import history</em> to fill in the past.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Customer decline emails</th>
						<td>
							<label><input type="radio" name="mode" value="dry_run" <?php checked( ! $live ); ?> /> <strong>Dry run</strong> — record and preview only, send nothing</label><br>
							<label><input type="radio" name="mode" value="live" <?php checked( $live ); ?> /> <strong>Live</strong> — send to customers, and stand down WooCommerce&rsquo;s failed-order email</label>
							<p class="description">WooCommerce&rsquo;s built-in &ldquo;Your order was unsuccessful&rdquo; email is currently reaching customers on every decline with no throttle. Switching to Live replaces it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mps-monitor-delay">Wait before emailing</label></th>
						<td>
							<input id="mps-monitor-delay" type="number" name="delay_minutes" min="0" max="1440" value="<?php echo esc_attr( $settings['delay_minutes'] ); ?>" class="small-text" /> minutes
							<p class="description">Around half of declined customers retry successfully within minutes. Waiting avoids telling a paying customer their order failed.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mps-monitor-cooldown">One email per customer every</label></th>
						<td>
							<input id="mps-monitor-cooldown" type="number" name="cooldown_days" min="1" max="90" value="<?php echo esc_attr( $settings['cooldown_days'] ); ?>" class="small-text" /> days
						</td>
					</tr>
					<tr>
						<th scope="row">Contact address</th>
						<td>
							<code><?php echo esc_html( MPS_Monitor_Notifier::contact_email() ?: 'not set' ); ?></code>
							<p class="description">
								Taken from this store &mdash; WooCommerce&rsquo;s &ldquo;from&rdquo; address, or the site admin email.
								Customer emails are sent with this as the reply-to and print it as the contact address.
								Change it in WooCommerce &rarr; Settings &rarr; Emails.
							</p>
						</td>
					</tr>
<?php /* "Support phone in email" field removed 2026-08-05 at James's request — no phone
	         number or opening hours on customer emails, so there is nothing for it to set. */ ?>
					<tr>
						<th scope="row">Checkout acknowledgment</th>
						<td>
							<label><input type="checkbox" name="ack_enabled" value="1" <?php checked( 'yes', $ack['enabled'] ); ?> /> Require customers to acknowledge the card payment notice before placing a card order</label>
							<p class="description">Server-enforced. Acknowledgment is stored on the order with timestamp and IP.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Statement descriptor</th>
						<td>
							<p class="description">
								Not editable here &mdash; the descriptor comes from the MPS portal&rsquo;s assignment for this
								merchant, and each email states the one that was in force for that charge.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'mps-gateway' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Import history', 'mps-gateway' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mps_monitor_action' ); ?>
				<input type="hidden" name="action" value="mps_monitor_action" />
				<input type="hidden" name="do" value="backfill" />
				<p class="description" style="max-width:640px;">
					Reads the last 60 days of order notes and payments into the ledger so the figures above reflect real history.
					Safe to run more than once — existing rows are not duplicated, and imported declines never trigger an email.
				</p>
				<?php submit_button( __( 'Import last 60 days', 'mps-gateway' ), 'secondary' ); ?>
			</form>
		</div>

		<style>
			.mps-monitor-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 18px 0 26px; }
			.mps-monitor-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 18px; }
			.mps-monitor-card__label { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #646970; margin-bottom: 6px; }
			.mps-monitor-card__value { font-size: 28px; font-weight: 700; line-height: 1.1; color: #1d2327; }
			.mps-monitor-card__sub { font-size: 13px; color: #646970; margin-top: 4px; }
			.mps-monitor-card--good .mps-monitor-card__value { color: #007017; }
			.mps-monitor-card--bad .mps-monitor-card__value { color: #b32d2e; }
			.mps-monitor-table td, .mps-monitor-reasons td, .mps-monitor-reasons th { vertical-align: top; }
			.mps-monitor-table .num, .mps-monitor-reasons .num { text-align: right; white-space: nowrap; }
			.mps-monitor-muted { color: #646970; font-size: 12px; }
			.mps-monitor-iso { color: #646970; font-size: 11px; }
			.mps-monitor-pill { display: inline-block; padding: 2px 9px; border-radius: 11px; font-size: 11.5px; font-weight: 600; background: #f0f0f1; color: #3c434a; white-space: nowrap; }
			.mps-monitor-pill--ok { background: #d6f2dc; color: #005c12; }
			.mps-monitor-pill--fail { background: #fce4e4; color: #8a1f20; }
			.mps-monitor-pill--contact_issuer { background: #dbeafe; color: #1e40af; }
			.mps-monitor-pill--verify_card { background: #fef3c7; color: #78350f; }
			.mps-monitor-pill--retry_other { background: #f0f0f1; color: #3c434a; }
			.mps-monitor-pill--do_not_retry { background: #fce4e4; color: #8a1f20; }
			.mps-monitor-views { margin: 18px 0 0; }
			.mps-monitor-view-hint { margin: 10px 0 0; }
			.mps-monitor-tabcount { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 9px; background: #dcdcde; color: #2c3338; font-size: 11px; font-weight: 600; }
			.nav-tab-active .mps-monitor-tabcount { background: #2271b1; color: #fff; }
			.mps-monitor-nowrap { white-space: nowrap; }
			.mps-monitor-pill--warn { background: #fcf0d3; color: #7a5000; }
			.mps-monitor-filters { display: flex; gap: 8px; align-items: center; margin: 12px 0; flex-wrap: wrap; }
			.mps-monitor-count { color: #646970; }
			.mps-monitor-actions { white-space: nowrap; }
			.mps-monitor-actions .button { margin-right: 4px; }
		</style>
		<?php
	}

	/** The screen shown before a merchant has switched the monitor on. */
	private static function render_disabled( array $settings, array $ack ): void {
		?>
		<div class="wrap mps-monitor">
			<h1><?php esc_html_e( 'Payment Monitor', 'mps-gateway' ); ?></h1>

			<?php if ( ! empty( $_GET['mps_monitor_notice'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['mps_monitor_notice'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'Transaction monitoring is off.', 'mps-gateway' ); ?></strong>
					<?php esc_html_e( 'Nothing is being recorded and no customer email can be sent. Turn it on below to start logging every card payment attempt, with the reason for each decline.', 'mps-gateway' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mps_monitor_action' ); ?>
				<input type="hidden" name="action" value="mps_monitor_action" />
				<input type="hidden" name="do" value="save_settings" />
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Transaction monitoring', 'mps-gateway' ); ?></th>
						<td>
							<label><input type="checkbox" name="monitor_enabled" value="1" /> <?php esc_html_e( 'Record every card payment attempt on this store', 'mps-gateway' ); ?></label>
							<p class="description"><?php esc_html_e( 'Customer emails stay in dry run until you switch them to live, so turning this on sends nothing.', 'mps-gateway' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				// Carry the rest of the settings through untouched, or saving from here would
				// reset them to defaults.
				printf( '<input type="hidden" name="mode" value="%s" />', esc_attr( $settings['mode'] ) );
				printf( '<input type="hidden" name="delay_minutes" value="%d" />', (int) $settings['delay_minutes'] );
				printf( '<input type="hidden" name="cooldown_days" value="%d" />', (int) $settings['cooldown_days'] );
				if ( 'yes' === $ack['enabled'] ) {
					echo '<input type="hidden" name="ack_enabled" value="1" />';
				}
				submit_button( __( 'Turn on monitoring', 'mps-gateway' ) );
				?>
			</form>
		</div>
		<?php
	}

	private static function card( string $label, string $value, string $sub = '', string $tone = '' ): void {
		printf(
			'<div class="mps-monitor-card %s"><div class="mps-monitor-card__label">%s</div><div class="mps-monitor-card__value">%s</div><div class="mps-monitor-card__sub">%s</div></div>',
			$tone ? 'mps-monitor-card--' . esc_attr( $tone ) : '',
			esc_html( $label ),
			esc_html( $value ),
			wp_kses_post( $sub )
		);
	}

	private static function notify_cell( object $row ): void {
		if ( 'approved' === $row->outcome ) {
			echo '&mdash;';
			return;
		}

		$states = array(
			'sent'                 => array( 'ok', 'Yes — sent' ),
			'would_send'           => array( 'warn', 'No — dry run' ),
			'suppressed_cooldown'  => array( 'warn', 'No — weekly limit' ),
			'suppressed_recovered' => array( 'ok', 'No — they paid' ),
			'send_failed'          => array( 'fail', 'No — send failed' ),
			'pending'              => array( 'warn', 'Not yet — queued' ),
			'na'                   => array( '', 'No — not applicable' ),
		);
		list( $tone, $label ) = $states[ $row->notify_state ] ?? array( '', (string) $row->notify_state );

		printf( '<span class="mps-monitor-pill mps-monitor-pill--%s">%s</span>', esc_attr( $tone ), esc_html( $label ) );

		if ( $row->notify_note ) {
			printf( '<br><span class="mps-monitor-muted">%s</span>', esc_html( $row->notify_note ) );
		}
	}

	private static function render_preview( int $id ): void {
		$row = MPS_Monitor_Ledger::get( $id );
		if ( ! $row ) {
			wp_die( esc_html__( 'Not found.', 'mps-gateway' ) );
		}
		$order = wc_get_order( (int) $row->order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'The order for this attempt no longer exists.', 'mps-gateway' ) );
		}

		$copy = MPS_Monitor_Copy::resolve( (string) $row->decline_code, (string) $row->iso_code );

		echo '<div style="padding:14px 18px;background:#1d2327;color:#fff;font:14px -apple-system,Segoe UI,sans-serif;">';
		printf(
			'Preview &mdash; to %s &middot; code %s &middot; matched by %s &middot; subject: <strong>%s</strong>',
			esc_html( $row->billing_email ),
			esc_html( $row->decline_code ?: 'none' ),
			esc_html( $copy['matched'] ),
			esc_html( MPS_Monitor_Notifier::subject( $copy ) )
		);
		echo '</div>';

		echo MPS_Monitor_Notifier::render( $row, $order, $copy ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
