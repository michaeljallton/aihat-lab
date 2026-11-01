<?php
/**
 * Data access. Every query is prepared. SQL avoids engine-specific features so
 * it runs on MySQL and on SQLite (local testing).
 */

namespace AIHat\Hire;

final class Store {

	private static function db() {
		global $wpdb;
		return $wpdb;
	}

	private static function t( string $name ): string {
		return Installer::table( $name );
	}

	private static function row( string $sql, ...$args ): ?array {
		$db  = self::db();
		$row = $db->get_row( $args ? $db->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		return $row ?: null;
	}

	private static function rows( string $sql, ...$args ): array {
		$db = self::db();
		return (array) $db->get_results( $args ? $db->prepare( $sql, ...$args ) : $sql, ARRAY_A );
	}

	private static function val( string $sql, ...$args ) {
		$db = self::db();
		return $db->get_var( $args ? $db->prepare( $sql, ...$args ) : $sql );
	}

	private static function exec( string $sql, ...$args ): int {
		$db = self::db();
		return (int) $db->query( $args ? $db->prepare( $sql, ...$args ) : $sql );
	}

	private static function insert( string $table, array $data ): int {
		$db = self::db();
		if ( false === $db->insert( self::t( $table ), $data ) ) {
			return 0;
		}
		return (int) $db->insert_id;
	}

	private static function update( string $table, array $data, array $where ): void {
		self::db()->update( self::t( $table ), $data, $where );
	}

	// Workshops.

	public static function workshop_by_code( string $code ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'workshops' ) . ' WHERE code = %s', $code );
	}

	public static function workshop( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'workshops' ) . ' WHERE id = %d', $id );
	}

	public static function create_workshop( string $code, string $label, int $now ): int {
		return self::insert(
			'workshops',
			array(
				'code'       => $code,
				'label'      => $label,
				'status'     => 'open',
				'created_at' => $now,
			)
		);
	}

	public static function set_workshop_status( int $id, string $status ): void {
		self::update( 'workshops', array( 'status' => $status ), array( 'id' => $id ) );
	}

	public static function workshops_with_counts(): array {
		return self::rows(
			'SELECT w.*, (SELECT COUNT(*) FROM ' . self::t( 'enrollments' ) . ' e WHERE e.workshop_id = w.id) AS signups FROM '
			. self::t( 'workshops' ) . ' w ORDER BY w.created_at DESC'
		);
	}

	// Participants.

	public static function participant_by_email( string $email ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'participants' ) . ' WHERE email = %s', $email );
	}

	public static function participant( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'participants' ) . ' WHERE id = %d', $id );
	}

	public static function create_participant( array $data ): int {
		return self::insert( 'participants', $data );
	}

	public static function update_participant( int $id, array $data ): void {
		self::update( 'participants', $data, array( 'id' => $id ) );
	}

	// Enrollments.

	public static function enroll( int $participant_id, int $workshop_id, int $now ): int {
		$existing = self::row(
			'SELECT id FROM ' . self::t( 'enrollments' ) . ' WHERE participant_id = %d AND workshop_id = %d',
			$participant_id,
			$workshop_id
		);
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$id = self::insert(
			'enrollments',
			array(
				'participant_id' => $participant_id,
				'workshop_id'    => $workshop_id,
				'created_at'     => $now,
			)
		);
		if ( ! $id ) {
			// Lost a race with a parallel request; read the winner.
			$existing = self::row(
				'SELECT id FROM ' . self::t( 'enrollments' ) . ' WHERE participant_id = %d AND workshop_id = %d',
				$participant_id,
				$workshop_id
			);
			$id = $existing ? (int) $existing['id'] : 0;
		}
		return $id;
	}

	public static function enrollment( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'enrollments' ) . ' WHERE id = %d', $id );
	}

	public static function enrollments_for( int $participant_id ): array {
		return self::rows(
			'SELECT e.*, w.code, w.label, w.status AS workshop_status FROM ' . self::t( 'enrollments' ) . ' e JOIN '
			. self::t( 'workshops' ) . ' w ON w.id = e.workshop_id WHERE e.participant_id = %d ORDER BY e.created_at DESC, e.id DESC',
			$participant_id
		);
	}

	public static function set_picked_prompt( int $enrollment_id, int $prompt_id ): void {
		self::update( 'enrollments', array( 'picked_prompt_id' => $prompt_id ), array( 'id' => $enrollment_id ) );
	}

	// Tasks.

	public static function tasks( int $enrollment_id ): array {
		return self::rows(
			'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE enrollment_id = %d ORDER BY sort ASC, id ASC',
			$enrollment_id
		);
	}

	public static function task( int $enrollment_id, int $id ): ?array {
		return self::row(
			'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE id = %d AND enrollment_id = %d',
			$id,
			$enrollment_id
		);
	}

	public static function count_tasks( int $enrollment_id ): int {
		return (int) self::val( 'SELECT COUNT(*) FROM ' . self::t( 'tasks' ) . ' WHERE enrollment_id = %d', $enrollment_id );
	}

	public static function create_task( int $enrollment_id, array $data ): int {
		$data['enrollment_id'] = $enrollment_id;
		$data['sort']          = (int) self::val( 'SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . self::t( 'tasks' ) . ' WHERE enrollment_id = %d', $enrollment_id );
		return self::insert( 'tasks', $data );
	}

	public static function update_task( int $enrollment_id, int $id, array $data ): void {
		self::update(
			'tasks',
			$data,
			array(
				'id'            => $id,
				'enrollment_id' => $enrollment_id,
			)
		);
	}

	public static function delete_task( int $enrollment_id, int $id ): void {
		self::exec( 'DELETE FROM ' . self::t( 'tasks' ) . ' WHERE id = %d AND enrollment_id = %d', $id, $enrollment_id );
	}

	public static function copy_tasks( int $from_enrollment, int $to_enrollment, int $now ): int {
		$n = 0;
		foreach ( self::tasks( $from_enrollment ) as $task ) {
			unset( $task['id'], $task['enrollment_id'], $task['sort'] );
			$task['updated_at'] = $now;
			if ( self::create_task( $to_enrollment, $task ) ) {
				++$n;
			}
		}
		return $n;
	}

	// Codes.

	public static function create_code( array $data ): int {
		return self::insert( 'codes', $data );
	}

	public static function latest_code( string $email ): ?array {
		return self::row(
			'SELECT * FROM ' . self::t( 'codes' ) . ' WHERE email = %s ORDER BY id DESC LIMIT 1',
			$email
		);
	}

	public static function bump_code_attempts( int $id ): void {
		self::exec( 'UPDATE ' . self::t( 'codes' ) . ' SET attempts = attempts + 1 WHERE id = %d', $id );
	}

	/**
	 * Marks a code used. Returns false when a parallel request used it first.
	 */
	public static function use_code( int $id, int $now ): bool {
		return 1 === self::exec( 'UPDATE ' . self::t( 'codes' ) . ' SET used_at = %d WHERE id = %d AND used_at = 0', $now, $id );
	}

	public static function count_codes_since( string $email, int $since ): int {
		return (int) self::val(
			'SELECT COUNT(*) FROM ' . self::t( 'codes' ) . ' WHERE email = %s AND created_at >= %d',
			$email,
			$since
		);
	}

	// Sessions.

	public static function create_session( int $participant_id, string $token_hash, int $expires_at, int $now ): int {
		return self::insert(
			'sessions',
			array(
				'participant_id' => $participant_id,
				'token_hash'     => $token_hash,
				'expires_at'     => $expires_at,
				'created_at'     => $now,
			)
		);
	}

	public static function session( string $token_hash ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'sessions' ) . ' WHERE token_hash = %s', $token_hash );
	}

	public static function delete_session( string $token_hash ): void {
		self::exec( 'DELETE FROM ' . self::t( 'sessions' ) . ' WHERE token_hash = %s', $token_hash );
	}

	// Jobs.

	public static function create_job( int $participant_id, int $enrollment_id, string $type, array $input, int $now ): int {
		return self::insert(
			'jobs',
			array(
				'participant_id' => $participant_id,
				'enrollment_id'  => $enrollment_id,
				'type'           => $type,
				'status'         => 'pending',
				'input'          => wp_json_encode_safe( $input ),
				'created_at'     => $now,
			)
		);
	}

	public static function job( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'jobs' ) . ' WHERE id = %d', $id );
	}

	/**
	 * Moves a job from pending to running. Returns false if another request
	 * claimed it first.
	 */
	public static function claim_job( int $id, int $now ): bool {
		return 1 === self::exec(
			'UPDATE ' . self::t( 'jobs' ) . " SET status = 'running', started_at = %d, attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
			$now,
			$id
		);
	}

	public static function finish_job( int $id, $result, int $now ): void {
		self::update(
			'jobs',
			array(
				'status'      => 'done',
				'result'      => wp_json_encode_safe( $result ),
				'finished_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	public static function fail_job( int $id, string $error, int $now ): void {
		self::update(
			'jobs',
			array(
				'status'      => 'failed',
				'error'       => substr( $error, 0, 250 ),
				'finished_at' => $now,
			),
			array( 'id' => $id )
		);
	}

	public static function release_job( int $id, string $error ): void {
		self::update(
			'jobs',
			array(
				'status' => 'pending',
				'error'  => substr( $error, 0, 250 ),
			),
			array( 'id' => $id )
		);
	}

	public static function count_running( int $since ): int {
		return (int) self::val(
			'SELECT COUNT(*) FROM ' . self::t( 'jobs' ) . " WHERE status = 'running' AND started_at >= %d",
			$since
		);
	}

	public static function queue_position( int $job_id ): int {
		return (int) self::val(
			'SELECT COUNT(*) FROM ' . self::t( 'jobs' ) . " WHERE status = 'pending' AND id < %d",
			$job_id
		);
	}

	public static function count_jobs_since( int $participant_id, string $type, int $since ): int {
		return (int) self::val(
			'SELECT COUNT(*) FROM ' . self::t( 'jobs' ) . " WHERE participant_id = %d AND type = %s AND created_at >= %d AND status <> 'failed'",
			$participant_id,
			$type,
			$since
		);
	}

	public static function open_job( int $participant_id, string $type ): ?array {
		return self::row(
			'SELECT * FROM ' . self::t( 'jobs' ) . " WHERE participant_id = %d AND type = %s AND status IN ('pending','running') ORDER BY id DESC LIMIT 1",
			$participant_id,
			$type
		);
	}

	/**
	 * Fails jobs stuck in running since before $cutoff (a crashed request).
	 */
	public static function reap_stale_jobs( int $cutoff, int $now ): int {
		return self::exec(
			'UPDATE ' . self::t( 'jobs' ) . " SET status = 'failed', error = 'timed out', finished_at = %d WHERE status = 'running' AND started_at < %d",
			$now,
			$cutoff
		);
	}

	// Runs and prompts.

	public static function create_run( int $enrollment_id, array $cards, int $now ): int {
		return self::insert(
			'runs',
			array(
				'enrollment_id' => $enrollment_id,
				'cards'         => wp_json_encode_safe( $cards ),
				'created_at'    => $now,
			)
		);
	}

	public static function run( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'runs' ) . ' WHERE id = %d', $id );
	}

	public static function latest_run( int $enrollment_id ): ?array {
		return self::row(
			'SELECT * FROM ' . self::t( 'runs' ) . ' WHERE enrollment_id = %d ORDER BY id DESC LIMIT 1',
			$enrollment_id
		);
	}

	public static function create_prompt( array $data ): int {
		return self::insert( 'prompts', $data );
	}

	public static function prompt( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'prompts' ) . ' WHERE id = %d', $id );
	}

	// Constant Contact pushes.

	public static function create_push( int $participant_id, string $event, array $payload, int $now ): int {
		return self::insert(
			'pushes',
			array(
				'participant_id'  => $participant_id,
				'event'           => $event,
				'payload'         => wp_json_encode_safe( $payload ),
				'status'          => 'queued',
				'next_attempt_at' => $now,
				'log'             => '',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
	}

	public static function has_push( int $participant_id, string $event ): bool {
		return (bool) self::val(
			'SELECT COUNT(*) FROM ' . self::t( 'pushes' ) . ' WHERE participant_id = %d AND event = %s',
			$participant_id,
			$event
		);
	}

	public static function due_pushes( int $now, int $limit = 20 ): array {
		return self::rows(
			'SELECT * FROM ' . self::t( 'pushes' ) . " WHERE status = 'queued' AND next_attempt_at <= %d ORDER BY id ASC LIMIT %d",
			$now,
			$limit
		);
	}

	public static function push( int $id ): ?array {
		return self::row( 'SELECT * FROM ' . self::t( 'pushes' ) . ' WHERE id = %d', $id );
	}

	public static function update_push( int $id, array $data ): void {
		self::update( 'pushes', $data, array( 'id' => $id ) );
	}

	public static function pushes_for( int $participant_id ): array {
		return self::rows(
			'SELECT * FROM ' . self::t( 'pushes' ) . ' WHERE participant_id = %d ORDER BY id ASC',
			$participant_id
		);
	}

	// Rate limits.

	/**
	 * Counts a hit on a fixed window and returns the total in that window.
	 */
	public static function hit( string $key, int $window, int $now ): int {
		$start = $now - ( $now % $window );
		$row   = self::row( 'SELECT * FROM ' . self::t( 'limits' ) . ' WHERE lkey = %s', $key );
		if ( ! $row ) {
			$ok = self::db()->insert(
				self::t( 'limits' ),
				array(
					'lkey'         => $key,
					'window_start' => $start,
					'hits'         => 1,
				)
			);
			if ( false !== $ok ) {
				return 1;
			}
			$row = self::row( 'SELECT * FROM ' . self::t( 'limits' ) . ' WHERE lkey = %s', $key );
		}
		if ( (int) $row['window_start'] !== $start ) {
			self::exec( 'UPDATE ' . self::t( 'limits' ) . ' SET window_start = %d, hits = 0 WHERE lkey = %s AND window_start = %d', $start, $key, (int) $row['window_start'] );
		}
		self::exec( 'UPDATE ' . self::t( 'limits' ) . ' SET hits = hits + 1 WHERE lkey = %s', $key );
		return (int) self::val( 'SELECT hits FROM ' . self::t( 'limits' ) . ' WHERE lkey = %s', $key );
	}

	// Admin reads.

	public static function participants_for_workshop( int $workshop_id ): array {
		return self::rows(
			'SELECT p.*, e.id AS enrollment_id, e.picked_prompt_id, e.created_at AS enrolled_at FROM ' . self::t( 'enrollments' ) . ' e JOIN '
			. self::t( 'participants' ) . ' p ON p.id = e.participant_id WHERE e.workshop_id = %d ORDER BY e.created_at ASC',
			$workshop_id
		);
	}

	// Deletion and retention.

	public static function stale_participant_ids( int $cutoff ): array {
		return array_map(
			'intval',
			array_column(
				self::rows( 'SELECT id FROM ' . self::t( 'participants' ) . ' WHERE last_login_at < %d', $cutoff ),
				'id'
			)
		);
	}

	/**
	 * Removes a participant and everything that belongs to them.
	 */
	public static function delete_participant( int $id ): void {
		$p = self::participant( $id );
		foreach ( self::rows( 'SELECT id FROM ' . self::t( 'enrollments' ) . ' WHERE participant_id = %d', $id ) as $e ) {
			$eid = (int) $e['id'];
			foreach ( array( 'tasks', 'runs', 'prompts' ) as $table ) {
				self::exec( 'DELETE FROM ' . self::t( $table ) . ' WHERE enrollment_id = %d', $eid );
			}
		}
		foreach ( array( 'enrollments', 'sessions', 'jobs', 'pushes' ) as $table ) {
			self::exec( 'DELETE FROM ' . self::t( $table ) . ' WHERE participant_id = %d', $id );
		}
		if ( $p ) {
			self::exec( 'DELETE FROM ' . self::t( 'codes' ) . ' WHERE email = %s', $p['email'] );
		}
		self::exec( 'DELETE FROM ' . self::t( 'participants' ) . ' WHERE id = %d', $id );
	}

	public static function purge_expired( int $now ): void {
		self::exec( 'DELETE FROM ' . self::t( 'sessions' ) . ' WHERE expires_at < %d', $now );
		self::exec( 'DELETE FROM ' . self::t( 'codes' ) . ' WHERE expires_at < %d', $now - DAY_IN_SECONDS );
		self::exec( 'DELETE FROM ' . self::t( 'limits' ) . ' WHERE window_start < %d', $now - DAY_IN_SECONDS );
	}
}
