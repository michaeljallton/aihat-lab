<?php

namespace AIHat\Hire\Tests;

use AIHat\Hire\Api;
use AIHat\Hire\Auth;
use AIHat\Hire\Config;
use AIHat\Hire\Installer;
use AIHat\Hire\Plugin;
use AIHat\Hire\Pushes;
use AIHat\Hire\Store;
use function AIHat\Hire\json_col;

/**
 * Database-backed flows: codes and sessions, rate limits, tasks, the job
 * queue, prompt generation, the Constant Contact push queue and retention.
 */
final class FlowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->workshop();
	}

	private function add_tasks( int $n = 5 ): array {
		$ids = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$res = Api::save_task(
				array(
					'tab'       => 0 === $i % 2 ? 'weekly' : 'daily',
					'name'      => "Task {$i}",
					'amount'    => 10 * $i,
					'unit'      => 'minutes',
					'frequency' => 1,
				),
				$this->now
			);
			$this->assertTrue( $res['ok'], $res['error'] ?? '' );
			$ids[] = $res['task']['id'];
		}
		return $ids;
	}

	private function poll( int $job_id ): array {
		return Api::poll( array( 'job_id' => $job_id ), $this->now );
	}

	// Codes and sessions.

	public function test_codes_are_hashed_single_use_and_expire(): void {
		$this->assertTrue( Api::request_code( array( 'mode' => 'signup', 'email' => 'A@Example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $this->now )['ok'] );
		$code = $this->last_code();
		$row  = Store::latest_code( 'a@example.com' );
		$this->assertNotSame( $code, $row['code_hash'] );
		$this->assertStringNotContainsString( $code, $row['code_hash'] );
		$this->assertSame( 64, strlen( $row['code_hash'] ) );

		// Expired.
		$late = Api::verify_code( array( 'email' => 'a@example.com', 'code' => $code ), $this->now + 901 );
		$this->assertSame( 'expired', $late['error'] );

		// Valid once, then gone.
		$this->assertTrue( Api::verify_code( array( 'email' => 'a@example.com', 'code' => $code ), $this->now + 60 )['ok'] );
		$this->assertSame( 'no_code', Api::verify_code( array( 'email' => 'a@example.com', 'code' => $code ), $this->now + 61 )['error'] );
	}

	public function test_wrong_code_attempts_are_capped(): void {
		Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $this->now );
		$code  = $this->last_code();
		$wrong = '000000' === $code ? '111111' : '000000';
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 'wrong_code', Api::verify_code( array( 'email' => 'a@example.com', 'code' => $wrong ), $this->now )['error'] );
		}
		// The right code no longer works after five misses.
		$this->assertSame( 'too_many_attempts', Api::verify_code( array( 'email' => 'a@example.com', 'code' => $code ), $this->now )['error'] );
	}

	public function test_code_with_spaces_is_accepted(): void {
		Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $this->now );
		$code = $this->last_code();
		$this->assertTrue( Api::verify_code( array( 'email' => 'a@example.com', 'code' => substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ) ), $this->now )['ok'] );
	}

	public function test_sessions_are_per_device_and_expire(): void {
		$this->signup();
		$p      = Store::participant_by_email( 'pat@example.com' );
		$phone  = Auth::start_session( (int) $p['id'], $this->now );
		$laptop = Auth::start_session( (int) $p['id'], $this->now );
		$this->assertNotSame( $phone, $laptop );
		Auth::set_token( $phone );
		$this->assertNotNull( Auth::participant( $this->now ) );
		Auth::set_token( $laptop );
		$this->assertNotNull( Auth::participant( $this->now ) );
		$this->assertNull( Auth::participant( $this->now + 31 * DAY_IN_SECONDS ) );
		// Tokens are stored hashed.
		$this->assertNull( Store::session( $laptop ) );
		$this->assertNotNull( Store::session( Auth::hash( $laptop ) ) );
		// Signing out on one device leaves the other signed in.
		Api::sign_out();
		Auth::set_token( $phone );
		$this->assertNotNull( Auth::participant( $this->now ) );
	}

	public function test_signup_needs_open_workshop_and_signin_needs_account(): void {
		$this->workshop( 'old-event-2026-01', 'closed' );
		$closed = Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'old-event-2026-01' ), $this->now );
		$this->assertSame( 'event_closed', $closed['error'] );
		$none = Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C' ), $this->now );
		$this->assertSame( 'event_closed', $none['error'] );
		$this->assertSame( 'no_account', Api::request_code( array( 'mode' => 'signin', 'email' => 'nobody@example.com' ), $this->now )['error'] );
		$this->assertSame( 'required', Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'event' => 'acme-kickoff-2027-01' ), $this->now )['error'] );
		$this->assertSame( 'bad_email', Api::request_code( array( 'mode' => 'signup', 'email' => 'nope' ), $this->now )['error'] );
		$this->assertCount( 0, $this->mail );
	}

	public function test_event_codes_are_case_insensitive(): void {
		$this->assertTrue( Api::request_code( array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'ACME-Kickoff-2027-01' ), $this->now )['ok'] );
	}

	// Rate limits.

	public function test_email_code_limit(): void {
		$in = array( 'mode' => 'signup', 'email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Api::request_code( $in, $this->now + $i )['ok'] );
		}
		$this->assertSame( 'rate', Api::request_code( $in, $this->now + 10 )['error'] );
		// An hour later it works again.
		$this->assertTrue( Api::request_code( $in, $this->now + 3700 )['ok'] );
	}

	public function test_ip_code_limit(): void {
		Config::set( 'AIHAT_HIRE_IP_CODE_LIMIT', 3 );
		$t = $this->now - ( $this->now % 3600 ) + 10;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( Api::request_code( array( 'mode' => 'signup', 'email' => "u{$i}@example.com", 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $t )['ok'] );
		}
		$this->assertSame( 'rate', Api::request_code( array( 'mode' => 'signup', 'email' => 'u9@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $t )['error'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->assertTrue( Api::request_code( array( 'mode' => 'signup', 'email' => 'u9@example.com', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'T', 'company' => 'C', 'event' => 'acme-kickoff-2027-01' ), $t )['ok'] );
	}

	// Tasks.

	public function test_tasks_save_update_delete_and_scope(): void {
		$this->signup();
		$ids = $this->add_tasks( 2 );
		$up  = Api::save_task( array( 'id' => $ids[0], 'tab' => 'daily', 'name' => 'Inbox', 'amount' => 1.5, 'unit' => 'hours', 'frequency' => 2 ), $this->now );
		$this->assertSame( 900, $up['task']['weekly_minutes'] ); // 90 min x 2 a day x 5 days.
		$this->assertSame( 1.5, $up['task']['amount'] );
		$bad = Api::save_task( array( 'tab' => 'daily', 'name' => '', 'amount' => 5, 'unit' => 'minutes', 'frequency' => 1 ), $this->now );
		$this->assertSame( 'task', $bad['error'] );
		Api::delete_task( array( 'id' => $ids[1] ), $this->now );
		$state = Api::state( array(), $this->now );
		$this->assertCount( 1, $state['tasks'] );

		// Another participant cannot touch these tasks.
		$this->signup( 'other@example.com' );
		$this->assertSame( 'not_found', Api::save_task( array( 'id' => $ids[0], 'tab' => 'daily', 'name' => 'Hijack', 'amount' => 5, 'unit' => 'minutes', 'frequency' => 1 ), $this->now )['error'] );
	}

	public function test_minimum_tasks_before_generating(): void {
		$this->signup();
		$this->add_tasks( 4 );
		$this->assertSame( 'min_tasks', Api::generate( array(), $this->now )['error'] );
	}

	// The whole flow.

	public function test_full_flow_from_signup_to_prompt(): void {
		$state = $this->signup( 'pat@example.com', true );
		$this->assertTrue( $state['signed_in'] );
		$this->assertSame( 'acme-kickoff-2027-01', $state['enrollment']['code'] );
		$this->assertNotNull( $state['industry_job'] );

		// Industry suggestion runs through the queue.
		$this->api_reply( array( 'industry' => "Freight \u{2014} logistics" ) );
		$res = $this->poll( $state['industry_job'] );
		$this->assertSame( 'done', $res['status'] );
		$this->assertSame( 'Freight - logistics', $res['industry'] );
		$this->assertSame( 'low', $this->api_calls[0]['body']['output_config']['effort'] );

		// Nothing goes to Constant Contact before the industry is confirmed.
		$this->assertFalse( Store::has_push( 1, 'signup' ) );
		$this->assertTrue( Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now )['ok'] );
		$this->assertTrue( Store::has_push( 1, 'signup' ) );

		$ids = $this->add_tasks( 5 );
		$gen = Api::generate( array(), $this->now );
		$this->assertTrue( $gen['ok'] );

		$this->api_reply( $this->cards( $ids ) );
		$res = $this->poll( $gen['job_id'] );
		$this->assertSame( 'done', $res['status'] );
		$cards = $res['run']['cards'];
		$this->assertCount( 5, $cards );
		// Ranked by weekly minutes x suitability, computed in code.
		$scores = array();
		foreach ( json_col( Store::run( $res['run']['id'] )['cards'] ) as $c ) {
			$scores[] = $c['score'];
		}
		$sorted = $scores;
		rsort( $sorted );
		$this->assertSame( $sorted, $scores );
		$this->assertArrayNotHasKey( 'score', $cards[0] );
		$this->assertNotEmpty( $cards[0]['tasks'] );

		// The model saw task ids and weekly minutes, never a number to repeat back.
		$user_msg = $this->api_calls[1]['body']['messages'][0]['content'];
		$this->assertStringContainsString( '[id ' . $ids[0] . ']', $user_msg );

		// Pick one and get the prompt.
		$pick = Api::pick( array( 'run_id' => $res['run']['id'], 'index' => 0 ), $this->now );
		$this->assertTrue( $pick['ok'] );
		$this->api_reply( $this->prompt_parts() );
		$res = $this->poll( $pick['job_id'] );
		$this->assertSame( 'done', $res['status'] );
		$text = $res['prompt']['text'];
		$this->assertStringContainsString( 'called ' . $cards[0]['name'], $text );
		$this->assertStringContainsString( 'Pat Rivera, Operations Manager at Northwind Logistics, working in Logistics', $text );
		$this->assertStringContainsString( 'logistics operations specialist', $text );
		$this->assertStringContainsString( '1. Where the notes come from', $text );
		$this->assertStringNotContainsString( '{{', $text );
		$this->assertMatchesRegularExpression( '/- Task \d \(\d+ (min|hr)/', $text );

		// The pick is recorded and an update push queued.
		$e = Store::enrollments_for( 1 )[0];
		$this->assertSame( (int) $res['prompt']['id'], (int) $e['picked_prompt_id'] );
		$this->assertTrue( Store::has_push( 1, 'update' ) );

		// State reload shows the prompt again.
		$this->assertSame( $text, Api::state( array(), $this->now )['prompt']['text'] );

		// Email me this prompt.
		$this->assertTrue( Api::email_prompt( array( 'prompt_id' => $res['prompt']['id'] ), $this->now )['ok'] );
		$this->assertStringContainsString( 'Start by asking me your first question', end( $this->mail )[2] );
	}

	public function test_no_consent_means_no_push(): void {
		$this->signup( 'pat@example.com', false );
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->assertCount( 0, Store::pushes_for( 1 ) );
		$p = Store::participant( 1 );
		$this->assertSame( 0, (int) $p['consent'] );
		$this->assertSame( 0, (int) $p['consent_at'] );
	}

	public function test_consent_is_recorded_with_time_and_version(): void {
		$this->signup( 'pat@example.com', true );
		$p = Store::participant( 1 );
		$this->assertSame( 1, (int) $p['consent'] );
		$this->assertSame( $this->now, (int) $p['consent_at'] );
		$this->assertSame( Config::str( 'AIHAT_HIRE_CONSENT_VERSION' ), $p['consent_version'] );
	}

	// Retry, failure and limits on generation.

	public function test_invalid_json_retries_once_then_succeeds(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$ids = $this->add_tasks( 5 );
		$gen = Api::generate( array(), $this->now );
		$this->api_reply( 'this is not json' );
		$this->api_reply( $this->cards( $ids ) );
		$this->assertSame( 'done', $this->poll( $gen['job_id'] )['status'] );
	}

	public function test_two_bad_answers_fail_with_a_friendly_error(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$ids  = $this->add_tasks( 5 );
		$gen  = Api::generate( array(), $this->now );
		$bad  = $this->cards( $ids );
		array_pop( $bad['cards'] );
		$this->api_reply( $bad );
		$this->api_reply( $bad );
		$res = $this->poll( $gen['job_id'] );
		$this->assertSame( 'failed', $res['status'] );
		$this->assertSame( 'ai_failed', $res['error'] );
		$this->assertCount( 2, $this->api_calls ); // One retry only.
	}

	public function test_overload_is_reported_as_busy(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$gen = Api::generate( array(), $this->now );
		$this->api_error( 529, 'overloaded_error' );
		$this->api_error( 529, 'overloaded_error' );
		$res = $this->poll( $gen['job_id'] );
		$this->assertSame( 'busy', $res['error'] );
	}

	public function test_refusal_is_not_retried(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$gen = Api::generate( array(), $this->now );
		$this->api_reply( '{}', 'refusal' );
		$res = $this->poll( $gen['job_id'] );
		$this->assertSame( 'failed', $res['status'] );
		$this->assertCount( 1, $this->api_calls );
	}

	public function test_daily_run_cap(): void {
		Config::set( 'AIHAT_HIRE_DAILY_RUNS', 2 );
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$ids = $this->add_tasks( 5 );
		for ( $i = 0; $i < 2; $i++ ) {
			$gen = Api::generate( array(), $this->now );
			$this->api_reply( $this->cards( $ids ) );
			$this->poll( $gen['job_id'] );
		}
		$this->assertSame( 'daily_cap', Api::generate( array(), $this->now )['error'] );
		$this->assertTrue( Api::generate( array(), $this->now + DAY_IN_SECONDS + 1 )['ok'] );
	}

	public function test_failed_runs_do_not_count_against_the_cap(): void {
		Config::set( 'AIHAT_HIRE_DAILY_RUNS', 1 );
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$gen = Api::generate( array(), $this->now );
		$this->api_error( 400, 'invalid_request_error' );
		$this->assertSame( 'failed', $this->poll( $gen['job_id'] )['status'] );
		$this->assertTrue( Api::generate( array(), $this->now )['ok'] );
	}

	public function test_double_click_reuses_the_open_job(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$a = Api::generate( array(), $this->now );
		$b = Api::generate( array(), $this->now );
		$this->assertSame( $a['job_id'], $b['job_id'] );
	}

	// Queue concurrency.

	public function test_queue_respects_the_concurrency_cap(): void {
		Config::set( 'AIHAT_HIRE_MAX_CONCURRENT', 1 );
		$this->signup( 'a@example.com' );
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$first = Api::generate( array(), $this->now );
		// Simulate another request already running the first job.
		$this->assertTrue( Store::claim_job( $first['job_id'], $this->now ) );

		$this->signup( 'b@example.com' );
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$second = Api::generate( array(), $this->now );
		$res    = $this->poll( $second['job_id'] );
		$this->assertSame( 'queued', $res['status'] );
		$this->assertCount( 0, $this->api_calls ); // Nothing called while the slot is taken.

		// A crashed running job stops holding the slot once stale.
		$later = $this->now + \AIHat\Hire\Worker::stale_after() + 1;
		Store::reap_stale_jobs( $later - \AIHat\Hire\Worker::stale_after(), $later );
		$this->assertSame( 'failed', Store::job( $first['job_id'] )['status'] );
	}

	public function test_a_job_is_claimed_only_once(): void {
		$this->signup();
		$job = Store::create_job( 1, 0, 'industry', array(), $this->now );
		$this->assertTrue( Store::claim_job( $job, $this->now ) );
		$this->assertFalse( Store::claim_job( $job, $this->now ) );
	}

	public function test_participants_cannot_poll_each_others_jobs(): void {
		$this->signup( 'a@example.com' );
		$job = Store::create_job( 1, 0, 'industry', array(), $this->now );
		$this->signup( 'b@example.com' );
		$this->assertSame( 'not_found', $this->poll( $job )['error'] );
	}

	// Multiple workshops.

	public function test_same_email_joins_a_second_workshop_and_copies_tasks(): void {
		$this->signup( 'pat@example.com', true, 'acme-kickoff-2027-01' );
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 3 );
		$this->workshop( 'globex-training-2027-03' );
		$state = Api::state( array( 'event' => 'globex-training-2027-03' ), $this->now );
		$this->assertSame( 'globex-training-2027-03', $state['enrollment']['code'] );
		$this->assertCount( 0, $state['tasks'] );
		$this->assertNotSame( 0, $state['copy_from'] );
		$copied = Api::copy_tasks( array( 'event' => 'globex-training-2027-03', 'from' => $state['copy_from'] ), $this->now );
		$this->assertCount( 3, $copied['tasks'] );
		$this->assertCount( 2, Store::enrollments_for( 1 ) );
		$this->assertCount( 1, Store::participants_for_workshop( 2 ) );
	}

	// Constant Contact pushes.

	public function test_payload_has_the_agreed_fields(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$push    = Store::pushes_for( 1 )[0];
		$payload = json_col( $push['payload'] );
		foreach ( array( 'event', 'first_name', 'last_name', 'email', 'title', 'company', 'industry', 'workshop_code', 'consent', 'consent_at', 'consent_version', 'signup_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $payload );
		}
		$this->assertSame( 'signup', $payload['event'] );
		$this->assertSame( 'Logistics', $payload['industry'] );
		$this->assertSame( 'acme-kickoff-2027-01', $payload['workshop_code'] );
		$this->assertArrayNotHasKey( 'list', $payload );
		$this->assertArrayNotHasKey( 'tags', $payload );
	}

	public function test_push_sends_and_signs(): void {
		Config::set( 'AIHAT_HIRE_CTCT_SECRET', 's3cret' );
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->assertSame( 1, Pushes::process( $this->now ) );
		$push = Store::pushes_for( 1 )[0];
		$this->assertSame( 'sent', $push['status'] );
		$this->assertSame( 'sent', Store::participant( 1 )['ctct_status'] );
		$sig = $this->hooks[0]['args']['headers']['x-aihat-signature'];
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $this->hooks[0]['args']['body'], 's3cret' ), $sig );
		$this->assertStringContainsString( 'attempt 1: sent', $push['log'] );
	}

	public function test_push_retries_three_times_then_fails(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->hook_queue = array(
			array( 'code' => 500, 'error' => '' ),
			array( 'code' => 0, 'error' => 'timeout' ),
			array( 'code' => 502, 'error' => '' ),
		);
		Pushes::process( $this->now );
		$push = Store::pushes_for( 1 )[0];
		$this->assertSame( 'queued', $push['status'] );
		$this->assertSame( $this->now + 300, (int) $push['next_attempt_at'] );
		// Not due yet: nothing sent.
		$this->assertSame( 0, Pushes::process( $this->now + 10 ) );
		Pushes::process( $this->now + 300 );
		Pushes::process( $this->now + 300 + 1800 );
		$push = Store::pushes_for( 1 )[0];
		$this->assertSame( 'failed', $push['status'] );
		$this->assertSame( 3, (int) $push['attempts'] );
		$this->assertSame( 3, substr_count( $push['log'], 'attempt' ) );
		$this->assertSame( 'failed', Store::participant( 1 )['ctct_status'] );
		$this->assertSame( 0, Pushes::process( $this->now + 99999 ) );
	}

	public function test_empty_webhook_skips_and_logs(): void {
		Config::set( 'AIHAT_HIRE_CTCT_WEBHOOK', '' );
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		Pushes::process( $this->now );
		$push = Store::pushes_for( 1 )[0];
		$this->assertSame( 'skipped', $push['status'] );
		$this->assertStringContainsString( 'AIHAT_HIRE_CTCT_WEBHOOK is empty', $push['log'] );
		$this->assertCount( 0, $this->hooks );
	}

	public function test_signup_push_queued_once(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		Api::confirm_industry( array( 'industry' => 'Freight' ), $this->now );
		$this->add_tasks( 5 );
		Api::generate( array(), $this->now );
		$this->assertCount( 1, Store::pushes_for( 1 ) );
	}

	// Deletion and retention.

	public function test_participant_can_delete_everything(): void {
		$this->signup();
		Api::confirm_industry( array( 'industry' => 'Logistics' ), $this->now );
		$this->add_tasks( 5 );
		$this->assertTrue( Api::delete_account( $this->now )['ok'] );
		global $wpdb;
		foreach ( array( 'participants', 'enrollments', 'tasks', 'sessions', 'jobs', 'pushes', 'codes' ) as $table ) {
			$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( $table ) ), $table );
		}
		$this->assertSame( 1, count( Store::workshops_with_counts() ) );
	}

	public function test_retention_purges_after_twelve_months_since_last_sign_in(): void {
		$this->signup( 'old@example.com' );
		$this->signup( 'new@example.com' );
		Store::update_participant( 1, array( 'last_login_at' => $this->now - 366 * DAY_IN_SECONDS ) );
		$this->assertSame( 1, Plugin::retention( $this->now ) );
		$this->assertNull( Store::participant_by_email( 'old@example.com' ) );
		$this->assertNotNull( Store::participant_by_email( 'new@example.com' ) );
	}

	public function test_a_new_sign_in_resets_the_retention_clock(): void {
		$this->signup();
		Store::update_participant( 1, array( 'last_login_at' => $this->now - 300 * DAY_IN_SECONDS ) );
		Api::request_code( array( 'mode' => 'signin', 'email' => 'pat@example.com', 'event' => 'acme-kickoff-2027-01' ), $this->now );
		Api::verify_code( array( 'email' => 'pat@example.com', 'code' => $this->last_code() ), $this->now );
		$this->assertSame( $this->now, (int) Store::participant( 1 )['last_login_at'] );
	}
}
