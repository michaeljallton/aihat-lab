<?php
/**
 * WordPress admin: workshop codes, per-workshop results, CSV export and a
 * configuration check. manage_options only.
 */

namespace AIHat\Hire;

final class Admin {

	public const SLUG = 'aihat-hire';
	public const CAP  = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_aihat_hire_workshop', array( self::class, 'save_workshop' ) );
		add_action( 'admin_post_aihat_hire_export', array( self::class, 'export' ) );
		add_action( 'admin_post_aihat_hire_retry_push', array( self::class, 'retry_push' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Hire Tool', 'Hire Tool', self::CAP, self::SLUG, array( self::class, 'page' ), 'dashicons-groups', 58 );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'aihat-hire' ) );
		}
		$workshop_id = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( $workshop_id ) {
			self::workshop_page( $workshop_id );
		} else {
			self::list_page();
		}
		echo '</div>';
	}

	private static function notice(): void {
		$msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'created' => 'Workshop code created.',
			'exists'  => 'That code already exists.',
			'invalid' => 'Codes use lowercase letters, numbers and hyphens, for example acme-kickoff-2027-01.',
			'updated' => 'Workshop updated.',
			'retried' => 'Push queued to send again.',
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $map[ $msg ] ) );
		}
	}

	private static function list_page(): void {
		echo '<h1>Hire Your First Crew Member</h1>';
		self::notice();
		self::config_panel();

		echo '<h2>New workshop code</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aihat_hire_workshop' );
		echo '<input type="hidden" name="action" value="aihat_hire_workshop"><input type="hidden" name="op" value="create">';
		echo '<p><label>Code <input type="text" name="code" required pattern="[a-z0-9\-]+" placeholder="acme-kickoff-2027-01" class="regular-text"></label> ';
		echo '<label>Label <input type="text" name="label" placeholder="Acme sales kickoff, Jan 2027" class="regular-text"></label> ';
		submit_button( 'Create', 'primary', 'submit', false );
		echo '</p><p class="description">Participants join with <code>' . esc_html( home_url( '/hire/' ) ) . '?event=CODE</code>. <code>event</code> is already on Yoast\'s parameter allow-list.</p></form>';

		echo '<h2>Workshops</h2><table class="widefat striped"><thead><tr><th>Code</th><th>Label</th><th>Status</th><th>Signups</th><th>Created</th><th></th></tr></thead><tbody>';
		$rows = Store::workshops_with_counts();
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No workshops yet.</td></tr>';
		}
		foreach ( $rows as $w ) {
			$toggle = 'open' === $w['status'] ? 'close' : 'open';
			echo '<tr><td><a href="' . esc_url( self::url( array( 'workshop' => (int) $w['id'] ) ) ) . '"><code>' . esc_html( $w['code'] ) . '</code></a></td>';
			echo '<td>' . esc_html( $w['label'] ) . '</td><td>' . esc_html( $w['status'] ) . '</td><td>' . (int) $w['signups'] . '</td>';
			echo '<td>' . esc_html( wp_date( 'Y-m-d', (int) $w['created_at'] ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'aihat_hire_workshop' );
			echo '<input type="hidden" name="action" value="aihat_hire_workshop"><input type="hidden" name="op" value="' . esc_attr( $toggle ) . '"><input type="hidden" name="id" value="' . (int) $w['id'] . '">';
			submit_button( 'open' === $toggle ? 'Reopen' : 'Close', 'small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function config_panel(): void {
		$checks = array(
			'Anthropic API key (AIHAT_HIRE_ANTHROPIC_KEY)' => Anthropic::configured(),
			'SMTP mailbox (AIHAT_HIRE_SMTP_HOST)'          => Mailer::smtp_configured(),
			'Make webhook (AIHAT_HIRE_CTCT_WEBHOOK)'       => '' !== Config::str( 'AIHAT_HIRE_CTCT_WEBHOOK' ),
		);
		echo '<h2>Configuration</h2><ul>';
		foreach ( $checks as $label => $ok ) {
			printf( '<li>%s %s</li>', $ok ? '&#10003;' : '&#10007;', esc_html( $label . ( $ok ? '' : ' - not set' ) ) );
		}
		printf( '<li>Model: <code>%s</code>, effort <code>%s</code>, %d jobs at once</li>', esc_html( Config::str( 'AIHAT_HIRE_MODEL' ) ), esc_html( Config::str( 'AIHAT_HIRE_EFFORT' ) ), (int) Config::int( 'AIHAT_HIRE_MAX_CONCURRENT' ) );
		echo '</ul>';
	}

	private static function workshop_page( int $id ): void {
		$w = Store::workshop( $id );
		if ( ! $w ) {
			echo '<h1>Workshop not found</h1>';
			return;
		}
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; All workshops</a></p>';
		echo '<h1><code>' . esc_html( $w['code'] ) . '</code> ' . esc_html( $w['label'] ) . '</h1>';
		self::notice();
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=aihat_hire_export&workshop=' . $id ), 'aihat_hire_export' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">Export CSV</a></p>';

		$rows = Store::participants_for_workshop( $id );
		if ( ! $rows ) {
			echo '<p>No signups yet.</p>';
			return;
		}
		foreach ( $rows as $p ) {
			$eid   = (int) $p['enrollment_id'];
			$tasks = Worker::task_view( $eid );
			$run   = Store::latest_run( $eid );
			$pick  = (int) $p['picked_prompt_id'] ? Store::prompt( (int) $p['picked_prompt_id'] ) : null;

			echo '<div class="card" style="max-width:none;margin-bottom:16px">';
			printf(
				'<h2 style="margin-top:0">%s %s</h2><p>%s, %s &middot; %s &middot; industry: %s &middot; consent: %s &middot; Constant Contact: %s</p>',
				esc_html( $p['first_name'] ),
				esc_html( $p['last_name'] ),
				esc_html( $p['title'] ),
				esc_html( $p['company'] ),
				esc_html( $p['email'] ),
				esc_html( $p['industry'] ?: '(not confirmed)' ),
				$p['consent'] ? 'yes' : 'no',
				esc_html( $p['ctct_status'] )
			);
			echo '<h3>Tasks</h3><ul>';
			foreach ( $tasks as $t ) {
				printf( '<li>%s (%s) - %s a week</li>', esc_html( $t['name'] ), esc_html( $t['tab'] ), esc_html( Time_Math::label( $t['weekly_minutes'] ) ) );
			}
			echo '</ul>';
			if ( $run ) {
				echo '<h3>Recommendations</h3><ol>';
				foreach ( json_col( $run['cards'] ) as $c ) {
					printf( '<li><strong>%s</strong> [%s, %s] - %s</li>', esc_html( $c['name'] ), esc_html( $c['build_tag'] ), esc_html( $c['role_tag'] ), esc_html( $c['drag_name'] ) );
				}
				echo '</ol>';
			}
			echo '<p><strong>Picked:</strong> ' . esc_html( $pick ? $pick['crew_member'] : '(none yet)' ) . '</p>';
			foreach ( Store::pushes_for( (int) $p['id'] ) as $push ) {
				printf( '<p><small>Push %s: %s, %d attempt(s)</small></p>', esc_html( $push['event'] ), esc_html( $push['status'] ), (int) $push['attempts'] );
				if ( 'failed' === $push['status'] ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( 'aihat_hire_retry_push' );
					echo '<input type="hidden" name="action" value="aihat_hire_retry_push"><input type="hidden" name="id" value="' . (int) $push['id'] . '"><input type="hidden" name="workshop" value="' . (int) $id . '">';
					submit_button( 'Retry push', 'small', 'submit', false );
					echo '</form>';
				}
				if ( '' !== (string) $push['log'] ) {
					echo '<pre style="white-space:pre-wrap;font-size:11px">' . esc_html( $push['log'] ) . '</pre>';
				}
			}
			echo '</div>';
		}
	}

	public static function save_workshop(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'aihat_hire_workshop' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'create' === $op ) {
			$raw   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$code  = Api::normalize_event( $raw );
			$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			if ( '' === $code || strtolower( $raw ) !== $code ) {
				$msg = 'invalid';
			} elseif ( Store::workshop_by_code( $code ) ) {
				$msg = 'exists';
			} else {
				Store::create_workshop( $code, $label, time() );
				$msg = 'created';
			}
		} else {
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			if ( $id && in_array( $op, array( 'open', 'close' ), true ) ) {
				Store::set_workshop_status( $id, 'open' === $op ? 'open' : 'closed' );
			}
			$msg = 'updated';
		}
		wp_safe_redirect( self::url( array( 'msg' => $msg ) ) );
		exit;
	}

	public static function retry_push(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'aihat_hire_retry_push' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$push = Store::push( $id );
		if ( $push && 'failed' === $push['status'] ) {
			Store::update_push(
				$id,
				array(
					'status'          => 'queued',
					'attempts'        => 0,
					'next_attempt_at' => time(),
				)
			);
			Pushes::set_participant_status( (int) $push['participant_id'] );
		}
		$workshop = isset( $_POST['workshop'] ) ? absint( $_POST['workshop'] ) : 0;
		wp_safe_redirect(
			self::url(
				array(
					'workshop' => $workshop,
					'msg'      => 'retried',
				)
			)
		);
		exit;
	}

	/**
	 * Builds the CSV rows for one workshop: one row per participant.
	 */
	public static function csv_rows( int $workshop_id ): array {
		$out = array(
			array( 'first_name', 'last_name', 'email', 'title', 'company', 'industry', 'consent', 'consent_at', 'ctct_status', 'enrolled_at', 'tasks', 'weekly_minutes_total', 'recommendations', 'picked_crew_member', 'picked_build_tag', 'picked_role_tag' ),
		);
		foreach ( Store::participants_for_workshop( $workshop_id ) as $p ) {
			$eid   = (int) $p['enrollment_id'];
			$tasks = Worker::task_view( $eid );
			$run   = Store::latest_run( $eid );
			$pick  = (int) $p['picked_prompt_id'] ? Store::prompt( (int) $p['picked_prompt_id'] ) : null;
			$out[] = array(
				$p['first_name'],
				$p['last_name'],
				$p['email'],
				$p['title'],
				$p['company'],
				$p['industry'],
				$p['consent'] ? 'yes' : 'no',
				$p['consent_at'] ? gmdate( 'c', (int) $p['consent_at'] ) : '',
				$p['ctct_status'],
				gmdate( 'c', (int) $p['enrolled_at'] ),
				implode( '; ', array_map( static fn( $t ) => $t['name'] . ' (' . $t['tab'] . ', ' . $t['weekly_minutes'] . ' min/wk)', $tasks ) ),
				array_sum( array_column( $tasks, 'weekly_minutes' ) ),
				$run ? implode( '; ', array_map( static fn( $c ) => $c['name'] . ' [' . $c['build_tag'] . '/' . $c['role_tag'] . ']', json_col( $run['cards'] ) ) ) : '',
				$pick ? $pick['crew_member'] : '',
				$pick ? $pick['build_tag'] : '',
				$pick ? $pick['role_tag'] : '',
			);
		}
		return $out;
	}

	/**
	 * Neutralizes values a spreadsheet would run as a formula.
	 */
	public static function csv_safe( $value ): string {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}

	public static function export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'aihat_hire_export' );
		$id = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : 0;
		$w  = Store::workshop( $id );
		if ( ! $w ) {
			wp_die( 'Workshop not found', 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="hire-' . sanitize_file_name( $w['code'] ) . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $id ) as $i => $row ) {
			fputcsv( $fh, 0 === $i ? $row : array_map( array( self::class, 'csv_safe' ), $row ) );
		}
		fclose( $fh );
		exit;
	}
}
