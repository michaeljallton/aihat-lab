<?php

namespace AIHat\Hire\Tests;

use AIHat\Hire\Anthropic;
use AIHat\Hire\Auth;
use AIHat\Hire\Config;
use AIHat\Hire\Installer;
use AIHat\Hire\Mailer;
use AIHat\Hire\Pushes;
use AIHat\Hire\Store;
use AIHat\Hire\Worker;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** Emails "sent" during the test: [to, subject, body]. */
	protected array $mail = array();
	/** Requests the fake Anthropic transport received. */
	protected array $api_calls = array();
	/** Queue of fake API responses (arrays for transport results). */
	protected array $api_queue = array();
	/** Webhook calls. */
	protected array $hooks = array();
	protected array $hook_queue = array();

	protected int $now = 1798761600; // 2027-01-01 00:00 UTC.

	protected function setUp(): void {
		global $wpdb;
		$wpdb->reset();
		$wpdb->create_tables( Installer::schema() );
		Config::reset();
		Config::set( 'AIHAT_HIRE_ANTHROPIC_KEY', 'test-key' );
		Config::set( 'AIHAT_HIRE_CTCT_WEBHOOK', 'https://hook.example.test/abc' );
		Auth::set_token( null );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$this->mail = array();
		Mailer::set_sender(
			function ( $to, $subject, $body ) {
				$this->mail[] = array( $to, $subject, $body );
				return true;
			}
		);
		$this->api_calls = array();
		$this->api_queue = array();
		Worker::set_client(
			new Anthropic(
				function ( $url, $args ) {
					$this->api_calls[] = array(
						'url'  => $url,
						'args' => $args,
						'body' => json_decode( $args['body'], true ),
					);
					return array_shift( $this->api_queue ) ?? array(
						'code'    => 500,
						'body'    => '{"type":"error","error":{"type":"api_error"}}',
						'headers' => array(),
						'error'   => '',
					);
				}
			)
		);
		$this->hooks      = array();
		$this->hook_queue = array();
		Pushes::set_transport(
			function ( $url, $args ) {
				$this->hooks[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array_shift( $this->hook_queue ) ?? array(
					'code'  => 200,
					'error' => '',
				);
			}
		);
	}

	/**
	 * Queues a successful model reply whose text is the JSON of $data.
	 */
	protected function api_reply( $data, string $stop = 'end_turn' ): void {
		$this->api_queue[] = array(
			'code'    => 200,
			'body'    => json_encode(
				array(
					'type'        => 'message',
					'stop_reason' => $stop,
					'content'     => array(
						array(
							'type' => 'text',
							'text' => is_string( $data ) ? $data : json_encode( $data ),
						),
					),
					'usage'       => array(
						'input_tokens'  => 10,
						'output_tokens' => 10,
					),
				)
			),
			'headers' => array(),
			'error'   => '',
		);
	}

	protected function api_error( int $code, string $type, array $headers = array() ): void {
		$this->api_queue[] = array(
			'code'    => $code,
			'body'    => json_encode(
				array(
					'type'  => 'error',
					'error' => array( 'type' => $type ),
				)
			),
			'headers' => $headers,
			'error'   => '',
		);
	}

	protected function workshop( string $code = 'acme-kickoff-2027-01', string $status = 'open' ): int {
		$id = Store::create_workshop( $code, 'Acme kickoff', $this->now );
		if ( 'open' !== $status ) {
			Store::set_workshop_status( $id, $status );
		}
		return $id;
	}

	/**
	 * Signs a new participant up through the API and returns the state.
	 */
	protected function signup( string $email = 'pat@example.com', bool $consent = true, string $event = 'acme-kickoff-2027-01' ): array {
		$res = \AIHat\Hire\Api::request_code(
			array(
				'mode'       => 'signup',
				'email'      => $email,
				'first_name' => 'Pat',
				'last_name'  => 'Rivera',
				'title'      => 'Operations Manager',
				'company'    => 'Northwind Logistics',
				'consent'    => $consent ? '1' : '',
				'event'      => $event,
			),
			$this->now
		);
		$this->assertTrue( $res['ok'], 'request_code failed: ' . ( $res['error'] ?? '' ) );
		$code = $this->last_code();
		$res  = \AIHat\Hire\Api::verify_code(
			array(
				'email' => $email,
				'code'  => $code,
				'event' => $event,
			),
			$this->now
		);
		$this->assertTrue( $res['ok'], 'verify_code failed: ' . ( $res['error'] ?? '' ) );
		return $res;
	}

	protected function last_code(): string {
		$last = end( $this->mail );
		preg_match( '/\b(\d{6})\b/', $last[2], $m );
		return $m[1];
	}

	/**
	 * Five valid cards over the given task ids.
	 */
	protected function cards( array $ids ): array {
		$names = array( 'The Notes Scribe', 'The Lead Scout', 'The Policy Coach', 'The Report Desk', 'The Follow-Up Clerk' );
		$tags  = array( array( 'skill', 'scribe' ), array( 'project', 'scout' ), array( 'project', 'coach' ), array( 'skill', 'scribe' ), array( 'skill', 'scribe' ) );
		$cards = array();
		foreach ( $names as $i => $name ) {
			$cards[] = array(
				'name'        => $name,
				'build_tag'   => $tags[ $i ][0],
				'role_tag'    => $tags[ $i ][1],
				'drag_name'   => 'Ends the Hunt ' . ( $i + 1 ),
				'drag_line'   => 'Time lost looking for things.',
				'description' => 'It does the work.',
				'produces'    => array( 'A summary', 'A draft' ),
				'task_ids'    => array( $ids[ $i % count( $ids ) ] ),
				'suitability' => 5 - ( $i % 3 ),
				'preflight'   => array( 'One example' ),
			);
		}
		return array( 'cards' => $cards );
	}

	protected function prompt_parts(): array {
		return array(
			'specialist_role' => 'logistics operations specialist',
			'job_paragraph'   => 'It takes my raw shipment notes and turns them into a clean daily report for the team lead.',
			'drag_line'       => 'The hour lost every afternoon rebuilding the same report.',
			'not_do_line'     => 'It never contacts carriers or changes a booking; those calls stay with me.',
			'territories'     => array( 'Where the notes come from', 'What a complete report contains', 'Who reads it', 'The formats it must fit', 'Rules it must follow', 'The best report I have', 'How often it runs' ),
			'structural_rule' => 'a one-page report with a two-sentence summary up top',
			'compliance_line' => 'Company data-handling policy applies to shipment records.',
		);
	}
}
