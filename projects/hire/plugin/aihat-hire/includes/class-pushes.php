<?php
/**
 * Sends confirmed, consenting participants to Constant Contact by POSTing JSON
 * to a Make webhook (AIHAT_HIRE_CTCT_WEBHOOK). Lists, tags and field mapping
 * live in the Make scenario, never here.
 *
 * Events: "signup" once per participant, "update" each time they pick a crew
 * member. Failed sends retry by WP-Cron, three attempts in all, and every
 * attempt is logged on the push row. A push never blocks the participant.
 */

namespace AIHat\Hire;

final class Pushes {

	public const MAX_ATTEMPTS = 3;
	/** Wait after attempt N fails (seconds). */
	public const BACKOFF = array( 1 => 300, 2 => 1800 );

	/** @var callable|null fn(string $url, array $args): array{code:int, error:string} */
	private static $transport = null;

	public static function set_transport( ?callable $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * Splits nothing and invents nothing: fields come straight from the row.
	 */
	public static function payload( string $event, array $p, string $workshop_code, ?array $card, int $now ): array {
		$payload = array(
			'event'           => $event,
			'source'          => 'aihat-hire',
			'first_name'      => $p['first_name'],
			'last_name'       => $p['last_name'],
			'email'           => $p['email'],
			'title'           => $p['title'],
			'company'         => $p['company'],
			'industry'        => $p['industry'],
			'workshop_code'   => $workshop_code,
			'consent'         => (bool) $p['consent'],
			'consent_at'      => $p['consent_at'] ? gmdate( 'c', (int) $p['consent_at'] ) : null,
			'consent_version' => $p['consent_version'],
			'signup_at'       => gmdate( 'c', (int) $p['created_at'] ),
			'sent_at'         => gmdate( 'c', $now ),
		);
		if ( $card ) {
			$payload['crew_member'] = array(
				'name'      => $card['name'],
				'build_tag' => $card['build_tag'],
				'role_tag'  => $card['role_tag'],
			);
		}
		return $payload;
	}

	private static function workshop_code_for( int $participant_id ): string {
		$enrollments = Store::enrollments_for( $participant_id );
		return $enrollments ? (string) $enrollments[0]['code'] : '';
	}

	/**
	 * Queues the signup event once, only with consent. Called when the
	 * participant confirms their industry, and again (as a no-op if already
	 * queued) when they first generate recommendations.
	 */
	public static function queue_signup( array $p, int $now ): bool {
		if ( empty( $p['consent'] ) || Store::has_push( (int) $p['id'], 'signup' ) ) {
			return false;
		}
		Store::create_push( (int) $p['id'], 'signup', self::payload( 'signup', $p, self::workshop_code_for( (int) $p['id'] ), null, $now ), $now );
		Store::update_participant( (int) $p['id'], array( 'ctct_status' => 'queued' ) );
		self::kick();
		return true;
	}

	public static function queue_update( array $p, array $card, int $now ): bool {
		if ( empty( $p['consent'] ) ) {
			return false;
		}
		self::queue_signup( $p, $now );
		Store::create_push( (int) $p['id'], 'update', self::payload( 'update', $p, self::workshop_code_for( (int) $p['id'] ), $card, $now ), $now );
		self::kick();
		return true;
	}

	/**
	 * Asks WP-Cron to run soon rather than waiting for the five-minute tick.
	 */
	private static function kick(): void {
		if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( Installer::CRON_HOOK . '_now' ) ) {
			wp_schedule_single_event( time(), Installer::CRON_HOOK . '_now' );
		}
	}

	private static function send( string $url, string $body ): array {
		$args = array(
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => $body,
			'timeout' => 10,
		);
		$secret = Config::str( 'AIHAT_HIRE_CTCT_SECRET' );
		if ( '' !== $secret ) {
			$args['headers']['x-aihat-signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}
		if ( self::$transport ) {
			return ( self::$transport )( $url, $args );
		}
		$res = wp_remote_post( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'code'  => 0,
				'error' => $res->get_error_message(),
			);
		}
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $res ),
			'error' => '',
		);
	}

	/**
	 * Attempts every push that is due. Returns the number attempted.
	 */
	public static function process( int $now ): int {
		$url = Config::str( 'AIHAT_HIRE_CTCT_WEBHOOK' );
		$n   = 0;
		foreach ( Store::due_pushes( $now ) as $push ) {
			++$n;
			$attempt = (int) $push['attempts'] + 1;
			$log     = (string) $push['log'];
			if ( '' === $url ) {
				$log .= gmdate( 'c', $now ) . " skipped: AIHAT_HIRE_CTCT_WEBHOOK is empty\n";
				Store::update_push(
					(int) $push['id'],
					array(
						'status'     => 'skipped',
						'log'        => $log,
						'updated_at' => $now,
					)
				);
				self::set_participant_status( (int) $push['participant_id'] );
				continue;
			}
			$res = self::send( $url, (string) $push['payload'] );
			$ok  = $res['code'] >= 200 && $res['code'] < 300;
			$log .= gmdate( 'c', $now ) . " attempt {$attempt}: " . ( $ok ? 'sent' : 'failed' ) . " (http {$res['code']}" . ( $res['error'] ? ', ' . $res['error'] : '' ) . ")\n";
			$data = array(
				'attempts'   => $attempt,
				'log'        => $log,
				'updated_at' => $now,
			);
			if ( $ok ) {
				$data['status'] = 'sent';
			} elseif ( $attempt >= self::MAX_ATTEMPTS ) {
				$data['status'] = 'failed';
			} else {
				$data['next_attempt_at'] = $now + self::BACKOFF[ $attempt ];
			}
			Store::update_push( (int) $push['id'], $data );
			self::set_participant_status( (int) $push['participant_id'] );
		}
		return $n;
	}

	/**
	 * Rolls push rows up to one status on the participant for the admin list.
	 */
	public static function set_participant_status( int $participant_id ): void {
		$statuses = array_column( Store::pushes_for( $participant_id ), 'status' );
		if ( ! $statuses ) {
			return;
		}
		foreach ( array( 'failed', 'queued', 'skipped' ) as $s ) {
			if ( in_array( $s, $statuses, true ) ) {
				Store::update_participant( $participant_id, array( 'ctct_status' => $s ) );
				return;
			}
		}
		Store::update_participant( $participant_id, array( 'ctct_status' => 'sent' ) );
	}
}
