<?php
/**
 * Runs queued AI jobs. A browser poll claims a pending job and runs it inside
 * that request, but only while fewer than AIHAT_HIRE_MAX_CONCURRENT jobs are
 * running, so a full room never holds dozens of PHP workers or API calls open.
 *
 * Job types: industry, recommend, prompt.
 */

namespace AIHat\Hire;

final class Worker {

	private static ?Anthropic $client = null;

	public static function set_client( ?Anthropic $client ): void {
		self::$client = $client;
	}

	private static function client(): Anthropic {
		return self::$client ?? new Anthropic();
	}

	/** Seconds after which a running job is treated as crashed. */
	public static function stale_after(): int {
		return 2 * Config::int( 'AIHAT_HIRE_API_TIMEOUT' ) + 60;
	}

	/**
	 * Returns the job's state, running it first if it is pending and a slot is free.
	 *
	 * @return array{status:string, position?:int, result?:mixed, error?:string}
	 */
	public static function poll( array $job, int $now ): array {
		if ( 'pending' === $job['status'] ) {
			$running = Store::count_running( $now - self::stale_after() );
			if ( $running < Config::int( 'AIHAT_HIRE_MAX_CONCURRENT' ) && Store::claim_job( (int) $job['id'], $now ) ) {
				self::run( Store::job( (int) $job['id'] ), $now );
			}
			$job = Store::job( (int) $job['id'] );
		}
		switch ( $job['status'] ) {
			case 'done':
				return array(
					'status' => 'done',
					'result' => json_col( $job['result'] ),
				);
			case 'failed':
				return array(
					'status' => 'failed',
					'error'  => (string) $job['error'],
				);
			case 'pending':
				return array(
					'status'   => 'queued',
					'position' => Store::queue_position( (int) $job['id'] ),
				);
			default:
				return array( 'status' => 'running' );
		}
	}

	/**
	 * Runs a claimed job to done or failed. Each job gets one retry on a
	 * validation or parse failure, per the brief.
	 */
	public static function run( array $job, int $now ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 2 * Config::int( 'AIHAT_HIRE_API_TIMEOUT' ) + 30 ); // phpcs:ignore
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$input = json_col( $job['input'] );
		try {
			switch ( $job['type'] ) {
				case 'industry':
					$out = self::run_industry( $job, $input );
					break;
				case 'recommend':
					$out = self::run_recommend( $job, $input, $now );
					break;
				case 'prompt':
					$out = self::run_prompt( $job, $input, $now );
					break;
				default:
					$out = array(
						'ok'    => false,
						'error' => 'unknown job type',
					);
			}
		} catch ( \Throwable $e ) {
			$out = array(
				'ok'    => false,
				'error' => 'exception: ' . $e->getMessage(),
			);
		}
		if ( $out['ok'] ) {
			Store::finish_job( (int) $job['id'], $out['result'], time() );
		} else {
			Store::fail_job( (int) $job['id'], (string) $out['error'], time() );
		}
	}

	/**
	 * Calls the model, validates, and retries once on a retryable failure.
	 *
	 * @param callable $validate fn(mixed $data): array{ok:bool, ...}
	 */
	private static function call_twice( array $prompt, array $schema, int $max_tokens, ?string $effort, callable $validate ): array {
		$last = 'no attempt';
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$res = self::client()->json( $prompt['system'], $prompt['user'], $schema, $max_tokens, $effort );
			if ( ! $res['ok'] ) {
				$last = $res['error'];
				if ( empty( $res['retryable'] ) ) {
					break;
				}
				if ( ! empty( $res['retry_after'] ) && $res['retry_after'] <= 10 && 1 === $attempt ) {
					sleep( (int) $res['retry_after'] );
				}
				continue;
			}
			$checked = $validate( $res['data'] );
			if ( $checked['ok'] ) {
				return $checked;
			}
			$last = 'validation: ' . implode( '; ', (array) ( $checked['errors'] ?? array( $checked['error'] ?? 'invalid' ) ) );
		}
		return array(
			'ok'    => false,
			'error' => $last,
		);
	}

	private static function run_industry( array $job, array $input ): array {
		$p = Store::participant( (int) $job['participant_id'] );
		if ( ! $p ) {
			return array(
				'ok'    => false,
				'error' => 'participant gone',
			);
		}
		$out = self::call_twice(
			Prompts::industry( $p['title'], $p['company'] ),
			Schemas::industry(),
			1000,
			'low',
			array( Schemas::class, 'validate_industry' )
		);
		if ( ! $out['ok'] ) {
			return $out;
		}
		Store::update_participant( (int) $p['id'], array( 'industry_suggested' => $out['industry'] ) );
		return array(
			'ok'     => true,
			'result' => array( 'industry' => $out['industry'] ),
		);
	}

	/**
	 * Task rows with weekly minutes, as the model and template see them.
	 */
	public static function task_view( int $enrollment_id ): array {
		$workdays = Config::int( 'AIHAT_HIRE_WORKDAYS' );
		$out      = array();
		foreach ( Store::tasks( $enrollment_id ) as $t ) {
			$out[] = array(
				'id'             => (int) $t['id'],
				'tab'            => $t['tab'],
				'name'           => $t['name'],
				'description'    => $t['description'],
				'weekly_minutes' => Time_Math::weekly_minutes( $t['tab'], (int) $t['minutes'], (float) $t['frequency'], $workdays ),
			);
		}
		return $out;
	}

	public static function person( array $p ): array {
		return array(
			'name'     => trim( $p['first_name'] . ' ' . $p['last_name'] ),
			'title'    => $p['title'],
			'company'  => $p['company'],
			'industry' => '' !== $p['industry'] ? $p['industry'] : 'an unspecified industry',
		);
	}

	private static function run_recommend( array $job, array $input, int $now ): array {
		$p     = Store::participant( (int) $job['participant_id'] );
		$eid   = (int) $job['enrollment_id'];
		$tasks = self::task_view( $eid );
		if ( ! $p || ! $tasks ) {
			return array(
				'ok'    => false,
				'error' => 'nothing to work from',
			);
		}
		$ids = array_column( $tasks, 'id' );
		$out = self::call_twice(
			Prompts::recommendations( self::person( $p ), $tasks ),
			Schemas::recommendations(),
			16000,
			null,
			static fn( $data ) => Schemas::validate_recommendations( $data, $ids )
		);
		if ( ! $out['ok'] ) {
			return $out;
		}
		$minutes = array_column( $tasks, 'weekly_minutes', 'id' );
		$cards   = array_slice( Ranker::rank( $out['cards'], $minutes ), 0, Schemas::CARD_COUNT );
		$run_id  = Store::create_run( $eid, $cards, $now );
		return array(
			'ok'     => true,
			'result' => array( 'run_id' => $run_id ),
		);
	}

	private static function run_prompt( array $job, array $input, int $now ): array {
		$p   = Store::participant( (int) $job['participant_id'] );
		$run = Store::run( (int) ( $input['run_id'] ?? 0 ) );
		$eid = (int) $job['enrollment_id'];
		if ( ! $p || ! $run || (int) $run['enrollment_id'] !== $eid ) {
			return array(
				'ok'    => false,
				'error' => 'run not found',
			);
		}
		$cards = json_col( $run['cards'] );
		$index = (int) ( $input['index'] ?? -1 );
		if ( ! isset( $cards[ $index ] ) ) {
			return array(
				'ok'    => false,
				'error' => 'card not found',
			);
		}
		$card    = $cards[ $index ];
		$covered = array_values( array_filter( self::task_view( $eid ), static fn( $t ) => in_array( $t['id'], $card['task_ids'], true ) ) );
		$person  = self::person( $p );
		$out     = self::call_twice(
			Prompts::prompt_parts( $person, $card, $covered ),
			Schemas::prompt_parts(),
			8000,
			null,
			array( Schemas::class, 'validate_prompt_parts' )
		);
		if ( ! $out['ok'] ) {
			return $out;
		}
		$parts = $out['parts'];
		$text  = Template::fill(
			Template::load(),
			array(
				'specialist_role'      => $parts['specialist_role'],
				'crew_member_name'     => $card['name'],
				'participant_name'     => $person['name'],
				'participant_title'    => $person['title'],
				'participant_company'  => $person['company'],
				'participant_industry' => $person['industry'],
				'job_paragraph'        => $parts['job_paragraph'],
				'drag_name'            => $card['drag_name'],
				'drag_line'            => $parts['drag_line'],
				'not_do_line'          => $parts['not_do_line'],
				'task_list'            => Template::task_list( $covered ),
				'territories'          => Template::territories( $parts['territories'] ),
				'structural_rule'      => $parts['structural_rule'],
				'compliance_line'      => $parts['compliance_line'],
			),
			$card['build_tag']
		);
		$prompt_id = Store::create_prompt(
			array(
				'enrollment_id' => $eid,
				'run_id'        => (int) $run['id'],
				'card_index'    => $index,
				'crew_member'   => $card['name'],
				'build_tag'     => $card['build_tag'],
				'role_tag'      => $card['role_tag'],
				'prompt'        => $text,
				'created_at'    => $now,
			)
		);
		Store::set_picked_prompt( $eid, $prompt_id );
		Pushes::queue_update( $p, $card, $now );
		return array(
			'ok'     => true,
			'result' => array( 'prompt_id' => $prompt_id ),
		);
	}
}
