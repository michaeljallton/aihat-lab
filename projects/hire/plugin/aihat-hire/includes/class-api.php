<?php
/**
 * Request handlers behind the AJAX endpoints. Each takes the posted fields and
 * returns an array; Ajax turns that into JSON. Kept free of HTTP details so
 * tests call them directly.
 *
 * Every handler returns ['ok' => true, ...] or ['ok' => false, 'error' => code].
 */

namespace AIHat\Hire;

final class Api {

	private static function fail( string $error, array $extra = array() ): array {
		return array_merge(
			array(
				'ok'    => false,
				'error' => $error,
			),
			$extra
		);
	}

	private static function text( array $in, string $key, int $max = 150 ): string {
		$value = isset( $in[ $key ] ) ? (string) $in[ $key ] : '';
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
		return mb_substr( $value, 0, $max );
	}

	private static function event_code( array $in ): string {
		return self::normalize_event( (string) ( $in['event'] ?? '' ) );
	}

	/**
	 * Workshop codes are lowercase letters, digits and hyphens, matching the
	 * Workshop Prep convention <org>-<event>-<yyyy-mm>.
	 */
	public static function normalize_event( string $code ): string {
		return substr( strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $code ) ), 0, 64 );
	}

	/**
	 * Open workshop for an event code, or null.
	 */
	public static function open_workshop( string $code ): ?array {
		if ( '' === $code ) {
			return null;
		}
		$w = Store::workshop_by_code( $code );
		return ( $w && 'open' === $w['status'] ) ? $w : null;
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	}

	/**
	 * The participant's current enrollment: the one for the event in the URL
	 * if they are enrolled there, else their newest.
	 */
	private static function current_enrollment( array $p, string $event_code ): ?array {
		$enrollments = Store::enrollments_for( (int) $p['id'] );
		if ( ! $enrollments ) {
			return null;
		}
		if ( '' !== $event_code ) {
			foreach ( $enrollments as $e ) {
				if ( $e['code'] === $event_code ) {
					return $e;
				}
			}
		}
		return $enrollments[0];
	}

	// Sign-up and sign-in.

	public static function request_code( array $in, int $now ): array {
		$email = Auth::normalize_email( (string) ( $in['email'] ?? '' ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 191 ) {
			return self::fail( 'bad_email' );
		}
		$mode     = 'signup' === ( $in['mode'] ?? '' ) ? 'signup' : 'signin';
		$workshop = self::open_workshop( self::event_code( $in ) );
		$existing = Store::participant_by_email( $email );

		if ( 'signup' === $mode && ! $workshop ) {
			return self::fail( 'event_closed' );
		}
		if ( 'signin' === $mode && ! $existing ) {
			return self::fail( 'no_account' );
		}

		if ( Store::hit( 'ip:' . Auth::hash( self::client_ip() ), HOUR_IN_SECONDS, $now ) > Config::int( 'AIHAT_HIRE_IP_CODE_LIMIT' ) ) {
			return self::fail( 'rate' );
		}
		if ( Store::count_codes_since( $email, $now - HOUR_IN_SECONDS ) >= Config::int( 'AIHAT_HIRE_EMAIL_CODE_LIMIT' ) ) {
			return self::fail( 'rate' );
		}

		$profile = null;
		if ( 'signup' === $mode ) {
			$profile = array(
				'first_name' => self::text( $in, 'first_name', 100 ),
				'last_name'  => self::text( $in, 'last_name', 100 ),
				'title'      => self::text( $in, 'title', 150 ),
				'company'    => self::text( $in, 'company', 150 ),
				'consent'    => ! empty( $in['consent'] ) && 'false' !== $in['consent'] && '0' !== $in['consent'],
			);
			if ( in_array( '', array( $profile['first_name'], $profile['last_name'], $profile['title'], $profile['company'] ), true ) ) {
				return self::fail( 'required' );
			}
		}

		$code = Auth::issue_code( $email, $profile, $workshop ? (int) $workshop['id'] : 0, $now );
		if ( ! Mailer::send_code( $email, $code ) ) {
			return self::fail( 'mail' );
		}
		return array(
			'ok'    => true,
			'email' => $email,
		);
	}

	public static function verify_code( array $in, int $now ): array {
		$email = Auth::normalize_email( (string) ( $in['email'] ?? '' ) );
		$check = Auth::check_code( $email, (string) ( $in['code'] ?? '' ), $now );
		if ( ! $check['ok'] ) {
			return self::fail( $check['error'] );
		}
		$row      = $check['row'];
		$profile  = json_col( $row['profile'] );
		$p        = Store::participant_by_email( $email );
		$is_new   = false;
		$version  = Config::str( 'AIHAT_HIRE_CONSENT_VERSION' );

		if ( ! $p ) {
			if ( ! $profile ) {
				return self::fail( 'no_account' );
			}
			$pid = Store::create_participant(
				array(
					'email'           => $email,
					'first_name'      => $profile['first_name'],
					'last_name'       => $profile['last_name'],
					'title'           => $profile['title'],
					'company'         => $profile['company'],
					'consent'         => $profile['consent'] ? 1 : 0,
					'consent_at'      => $profile['consent'] ? $now : 0,
					'consent_version' => $profile['consent'] ? $version : '',
					'created_at'      => $now,
					'last_login_at'   => $now,
				)
			);
			if ( ! $pid ) {
				// A parallel verify created the row; use it.
				$p = Store::participant_by_email( $email );
			} else {
				$p      = Store::participant( $pid );
				$is_new = true;
			}
		} else {
			$update = array( 'last_login_at' => $now );
			if ( $profile && ! empty( $profile['consent'] ) && empty( $p['consent'] ) ) {
				$update += array(
					'consent'         => 1,
					'consent_at'      => $now,
					'consent_version' => $version,
				);
			}
			Store::update_participant( (int) $p['id'], $update );
			$p = Store::participant( (int) $p['id'] );
		}

		$workshop_id = (int) $row['workshop_id'];
		if ( ! $workshop_id ) {
			$w           = self::open_workshop( self::event_code( $in ) );
			$workshop_id = $w ? (int) $w['id'] : 0;
		}
		if ( $workshop_id ) {
			Store::enroll( (int) $p['id'], $workshop_id, $now );
		}
		if ( $is_new || ! $p['industry_confirmed'] ) {
			if ( '' === $p['industry_suggested'] && ! Store::open_job( (int) $p['id'], 'industry' ) ) {
				Store::create_job( (int) $p['id'], 0, 'industry', array(), $now );
			}
		}
		Auth::start_session( (int) $p['id'], $now );
		return array( 'ok' => true ) + self::state_for( $p, self::event_code( $in ), $now );
	}

	public static function sign_out(): array {
		Auth::end_session();
		return array( 'ok' => true );
	}

	public static function delete_account( int $now ): array {
		$p = Auth::participant( $now );
		if ( ! $p ) {
			return self::fail( 'session' );
		}
		Auth::end_session();
		Store::delete_participant( (int) $p['id'] );
		return array( 'ok' => true );
	}

	// State.

	public static function state( array $in, int $now ): array {
		$p = Auth::participant( $now );
		if ( ! $p ) {
			$code = self::event_code( $in );
			$w    = '' !== $code ? Store::workshop_by_code( $code ) : null;
			return array(
				'ok'       => true,
				'signed_in'=> false,
				'event'    => $w ? array(
					'code'   => $w['code'],
					'label'  => $w['label'],
					'open'   => 'open' === $w['status'],
				) : null,
			);
		}
		return array( 'ok' => true ) + self::state_for( $p, self::event_code( $in ), $now );
	}

	private static function state_for( array $p, string $event_code, int $now ): array {
		// Joining a new open workshop while signed in.
		if ( '' !== $event_code ) {
			$w = self::open_workshop( $event_code );
			if ( $w ) {
				Store::enroll( (int) $p['id'], (int) $w['id'], $now );
			}
		}
		$enrollment  = self::current_enrollment( $p, $event_code );
		$enrollments = Store::enrollments_for( (int) $p['id'] );
		$eid         = $enrollment ? (int) $enrollment['id'] : 0;

		$industry_job = null;
		if ( ! $p['industry_confirmed'] ) {
			$job = Store::open_job( (int) $p['id'], 'industry' );
			if ( $job ) {
				$industry_job = (int) $job['id'];
			}
		}

		$run    = $eid ? Store::latest_run( $eid ) : null;
		$prompt = ( $enrollment && (int) $enrollment['picked_prompt_id'] ) ? Store::prompt( (int) $enrollment['picked_prompt_id'] ) : null;
		$copy_from = 0;
		foreach ( $enrollments as $e ) {
			if ( (int) $e['id'] !== $eid && Store::count_tasks( (int) $e['id'] ) > 0 ) {
				$copy_from = (int) $e['id'];
				break;
			}
		}

		return array(
			'signed_in'   => true,
			'participant' => array(
				'first_name'         => $p['first_name'],
				'last_name'          => $p['last_name'],
				'email'              => $p['email'],
				'title'              => $p['title'],
				'company'            => $p['company'],
				'industry'           => $p['industry'],
				'industry_suggested' => $p['industry_suggested'],
				'industry_confirmed' => (bool) $p['industry_confirmed'],
			),
			'industry_job' => $industry_job,
			'enrollment'  => $enrollment ? array(
				'id'    => $eid,
				'code'  => $enrollment['code'],
				'label' => $enrollment['label'],
			) : null,
			'tasks'       => $eid ? self::task_rows( $eid ) : array(),
			'copy_from'   => $copy_from,
			'run'         => $run ? self::run_view( $run, $eid ) : null,
			'prompt'      => $prompt ? self::prompt_view( $prompt ) : null,
			'open_jobs'   => array(
				'recommend' => self::open_job_id( (int) $p['id'], 'recommend' ),
				'prompt'    => self::open_job_id( (int) $p['id'], 'prompt' ),
			),
			'settings'    => array(
				'min_tasks' => Config::int( 'AIHAT_HIRE_MIN_TASKS' ),
				'workdays'  => Config::int( 'AIHAT_HIRE_WORKDAYS' ),
			),
		);
	}

	private static function open_job_id( int $pid, string $type ): ?int {
		$job = Store::open_job( $pid, $type );
		return $job ? (int) $job['id'] : null;
	}

	private static function task_rows( int $eid ): array {
		$workdays = Config::int( 'AIHAT_HIRE_WORKDAYS' );
		$rows     = array();
		foreach ( Store::tasks( $eid ) as $t ) {
			$rows[] = self::task_out( $t, $workdays );
		}
		return $rows;
	}

	private static function task_out( array $t, int $workdays ): array {
		$minutes = (int) $t['minutes'];
		$amount  = 'hours' === $t['unit'] ? round( $minutes / 60, 2 ) : $minutes;
		return array(
			'id'             => (int) $t['id'],
			'tab'            => $t['tab'],
			'name'           => $t['name'],
			'description'    => $t['description'],
			'amount'         => $amount,
			'unit'           => $t['unit'],
			'frequency'      => (float) $t['frequency'],
			'weekly_minutes' => Time_Math::weekly_minutes( $t['tab'], $minutes, (float) $t['frequency'], $workdays ),
		);
	}

	public static function run_view( array $run, int $eid ): array {
		$names = array();
		foreach ( Store::tasks( $eid ) as $t ) {
			$names[ (int) $t['id'] ] = $t['name'];
		}
		$cards = array();
		foreach ( json_col( $run['cards'] ) as $i => $card ) {
			$card['index'] = $i;
			$card['tasks'] = array_values( array_filter( array_map( static fn( $id ) => $names[ $id ] ?? null, $card['task_ids'] ) ) );
			unset( $card['score'] );
			$cards[] = $card;
		}
		return array(
			'id'    => (int) $run['id'],
			'cards' => $cards,
		);
	}

	private static function prompt_view( array $prompt ): array {
		return array(
			'id'          => (int) $prompt['id'],
			'crew_member' => $prompt['crew_member'],
			'build_tag'   => $prompt['build_tag'],
			'role_tag'    => $prompt['role_tag'],
			'text'        => $prompt['prompt'],
		);
	}

	/**
	 * Signed-in participant plus current enrollment, or an error array.
	 */
	private static function context( array $in, int $now ): array {
		$p = Auth::participant( $now );
		if ( ! $p ) {
			return self::fail( 'session' );
		}
		$e = self::current_enrollment( $p, self::event_code( $in ) );
		if ( ! $e ) {
			return self::fail( 'no_event' );
		}
		return array(
			'ok' => true,
			'p'  => $p,
			'e'  => $e,
		);
	}

	// Industry.

	public static function confirm_industry( array $in, int $now ): array {
		$p = Auth::participant( $now );
		if ( ! $p ) {
			return self::fail( 'session' );
		}
		$industry = Schemas::clean( self::text( $in, 'industry', 100 ), 100 );
		if ( '' === $industry ) {
			return self::fail( 'required' );
		}
		Store::update_participant(
			(int) $p['id'],
			array(
				'industry'           => $industry,
				'industry_confirmed' => 1,
			)
		);
		Pushes::queue_signup( Store::participant( (int) $p['id'] ), $now );
		return array(
			'ok'       => true,
			'industry' => $industry,
		);
	}

	// Tasks.

	public static function save_task( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		$eid       = (int) $ctx['e']['id'];
		$tab       = (string) ( $in['tab'] ?? '' );
		$name      = self::text( $in, 'name', 150 );
		$desc      = self::text( $in, 'description', 255 );
		$amount    = (float) ( $in['amount'] ?? 0 );
		$unit      = (string) ( $in['unit'] ?? 'minutes' );
		$frequency = round( (float) ( $in['frequency'] ?? 0 ), 2 );
		$error     = Time_Math::validate_row( $tab, $name, $amount, $unit, $frequency );
		if ( '' !== $error ) {
			return self::fail( 'task', array( 'detail' => $error ) );
		}
		$data = array(
			'tab'         => $tab,
			'name'        => $name,
			'description' => $desc,
			'minutes'     => Time_Math::to_minutes( $amount, $unit ),
			'unit'        => $unit,
			'frequency'   => $frequency,
			'updated_at'  => $now,
		);
		$id = (int) ( $in['id'] ?? 0 );
		if ( $id ) {
			if ( ! Store::task( $eid, $id ) ) {
				return self::fail( 'not_found' );
			}
			Store::update_task( $eid, $id, $data );
		} else {
			if ( Store::count_tasks( $eid ) >= Config::int( 'AIHAT_HIRE_MAX_TASKS' ) ) {
				return self::fail( 'max_tasks' );
			}
			$id = Store::create_task( $eid, $data );
		}
		return array(
			'ok'   => true,
			'task' => self::task_out( Store::task( $eid, $id ), Config::int( 'AIHAT_HIRE_WORKDAYS' ) ),
		);
	}

	public static function delete_task( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		Store::delete_task( (int) $ctx['e']['id'], (int) ( $in['id'] ?? 0 ) );
		return array( 'ok' => true );
	}

	public static function copy_tasks( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		$from = (int) ( $in['from'] ?? 0 );
		$src  = Store::enrollment( $from );
		if ( ! $src || (int) $src['participant_id'] !== (int) $ctx['p']['id'] || $from === (int) $ctx['e']['id'] ) {
			return self::fail( 'not_found' );
		}
		$room = Config::int( 'AIHAT_HIRE_MAX_TASKS' ) - Store::count_tasks( (int) $ctx['e']['id'] );
		if ( Store::count_tasks( $from ) > $room ) {
			return self::fail( 'max_tasks' );
		}
		Store::copy_tasks( $from, (int) $ctx['e']['id'], $now );
		return array(
			'ok'    => true,
			'tasks' => self::task_rows( (int) $ctx['e']['id'] ),
		);
	}

	// Generation.

	public static function generate( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		$pid = (int) $ctx['p']['id'];
		$eid = (int) $ctx['e']['id'];
		if ( Store::count_tasks( $eid ) < Config::int( 'AIHAT_HIRE_MIN_TASKS' ) ) {
			return self::fail( 'min_tasks' );
		}
		$open = Store::open_job( $pid, 'recommend' );
		if ( $open ) {
			return array(
				'ok'     => true,
				'job_id' => (int) $open['id'],
			);
		}
		if ( Store::count_jobs_since( $pid, 'recommend', $now - DAY_IN_SECONDS ) >= Config::int( 'AIHAT_HIRE_DAILY_RUNS' ) ) {
			return self::fail( 'daily_cap' );
		}
		Pushes::queue_signup( $ctx['p'], $now );
		return array(
			'ok'     => true,
			'job_id' => Store::create_job( $pid, $eid, 'recommend', array(), $now ),
		);
	}

	public static function pick( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		$pid = (int) $ctx['p']['id'];
		$eid = (int) $ctx['e']['id'];
		$run = Store::run( (int) ( $in['run_id'] ?? 0 ) );
		$idx = (int) ( $in['index'] ?? -1 );
		if ( ! $run || (int) $run['enrollment_id'] !== $eid || ! isset( json_col( $run['cards'] )[ $idx ] ) ) {
			return self::fail( 'not_found' );
		}
		$open = Store::open_job( $pid, 'prompt' );
		if ( $open ) {
			return array(
				'ok'     => true,
				'job_id' => (int) $open['id'],
			);
		}
		if ( Store::count_jobs_since( $pid, 'prompt', $now - DAY_IN_SECONDS ) >= Config::int( 'AIHAT_HIRE_DAILY_PROMPTS' ) ) {
			return self::fail( 'daily_cap' );
		}
		return array(
			'ok'     => true,
			'job_id' => Store::create_job(
				$pid,
				$eid,
				'prompt',
				array(
					'run_id' => (int) $run['id'],
					'index'  => $idx,
				),
				$now
			),
		);
	}

	public static function poll( array $in, int $now ): array {
		$p = Auth::participant( $now );
		if ( ! $p ) {
			return self::fail( 'session' );
		}
		$job = Store::job( (int) ( $in['job_id'] ?? 0 ) );
		if ( ! $job || (int) $job['participant_id'] !== (int) $p['id'] ) {
			return self::fail( 'not_found' );
		}
		$state = Worker::poll( $job, $now );
		$out   = array(
			'ok'     => true,
			'type'   => $job['type'],
			'status' => $state['status'],
		);
		if ( isset( $state['position'] ) ) {
			$out['position'] = $state['position'];
		}
		if ( 'failed' === $state['status'] ) {
			$out['error'] = self::public_error( $state['error'] ?? '' );
		}
		if ( 'done' === $state['status'] ) {
			$result = $state['result'];
			if ( 'industry' === $job['type'] ) {
				$out['industry'] = $result['industry'] ?? '';
			} elseif ( 'recommend' === $job['type'] ) {
				$run        = Store::run( (int) $result['run_id'] );
				$out['run'] = $run ? self::run_view( $run, (int) $job['enrollment_id'] ) : null;
			} elseif ( 'prompt' === $job['type'] ) {
				$prompt        = Store::prompt( (int) $result['prompt_id'] );
				$out['prompt'] = $prompt ? self::prompt_view( $prompt ) : null;
			}
		}
		return $out;
	}

	/**
	 * Maps an internal job error to a participant-facing error code.
	 */
	public static function public_error( string $error ): string {
		if ( preg_match( '/http (429|529)|overloaded|rate_limit/', $error ) ) {
			return 'busy';
		}
		if ( str_starts_with( $error, 'network' ) || 'timed out' === $error ) {
			return 'busy';
		}
		return 'ai_failed';
	}

	public static function email_prompt( array $in, int $now ): array {
		$ctx = self::context( $in, $now );
		if ( ! $ctx['ok'] ) {
			return $ctx;
		}
		$prompt = Store::prompt( (int) ( $in['prompt_id'] ?? 0 ) );
		if ( ! $prompt ) {
			return self::fail( 'not_found' );
		}
		$owner = Store::enrollment( (int) $prompt['enrollment_id'] );
		if ( ! $owner || (int) $owner['participant_id'] !== (int) $ctx['p']['id'] ) {
			return self::fail( 'not_found' );
		}
		if ( Store::hit( 'mail:' . $ctx['p']['id'], DAY_IN_SECONDS, $now ) > Config::int( 'AIHAT_HIRE_DAILY_PROMPT_EMAILS' ) ) {
			return self::fail( 'daily_cap' );
		}
		if ( ! Mailer::send_prompt( $ctx['p']['email'], $prompt['crew_member'], $prompt['prompt'] ) ) {
			return self::fail( 'mail' );
		}
		return array( 'ok' => true );
	}
}
