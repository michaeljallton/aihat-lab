<?php

namespace AIHat\Hire\Tests;

use AIHat\Hire\Admin;
use AIHat\Hire\Anthropic;
use AIHat\Hire\Config;
use AIHat\Hire\Ranker;
use AIHat\Hire\Schemas;
use AIHat\Hire\Template;
use AIHat\Hire\Time_Math;

/**
 * Pure logic: time maths, ranking, schema validation, template fill, API
 * request and response handling.
 */
final class UnitTest extends TestCase {

	// Weekly-time maths.

	public function test_minutes_conversion(): void {
		$this->assertSame( 90, Time_Math::to_minutes( 1.5, 'hours' ) );
		$this->assertSame( 20, Time_Math::to_minutes( 20, 'minutes' ) );
		$this->assertSame( 8, Time_Math::to_minutes( 7.6, 'minutes' ) );
	}

	public function test_daily_rows_count_workdays(): void {
		// 15 min, 4 times a day, 5-day week = 300.
		$this->assertSame( 300, Time_Math::weekly_minutes( 'daily', 15, 4, 5 ) );
		$this->assertSame( 420, Time_Math::weekly_minutes( 'daily', 15, 4, 7 ) );
	}

	public function test_weekly_rows_ignore_workdays(): void {
		$this->assertSame( 180, Time_Math::weekly_minutes( 'weekly', 60, 3, 5 ) );
		$this->assertSame( 45, Time_Math::weekly_minutes( 'weekly', 90, 0.5, 5 ) );
	}

	public function test_zero_or_negative_gives_zero(): void {
		$this->assertSame( 0, Time_Math::weekly_minutes( 'daily', 0, 4, 5 ) );
		$this->assertSame( 0, Time_Math::weekly_minutes( 'weekly', 30, -1, 5 ) );
	}

	public function test_totals_per_tab(): void {
		$rows   = array(
			array( 'tab' => 'daily', 'minutes' => 10, 'frequency' => 2 ),
			array( 'tab' => 'daily', 'minutes' => 30, 'frequency' => 1 ),
			array( 'tab' => 'weekly', 'minutes' => 120, 'frequency' => 1 ),
		);
		$totals = Time_Math::totals( $rows, 5 );
		$this->assertSame( 250, $totals['daily'] );
		$this->assertSame( 120, $totals['weekly'] );
		$this->assertSame( 370, $totals['all'] );
	}

	public function test_labels(): void {
		$this->assertSame( '45 min', Time_Math::label( 45 ) );
		$this->assertSame( '2 hr', Time_Math::label( 120 ) );
		$this->assertSame( '3 hr 20 min', Time_Math::label( 200 ) );
	}

	public function test_row_validation(): void {
		$this->assertSame( '', Time_Math::validate_row( 'daily', 'Inbox', 15, 'minutes', 3 ) );
		$this->assertSame( 'name_required', Time_Math::validate_row( 'daily', '  ', 15, 'minutes', 3 ) );
		$this->assertSame( 'bad_tab', Time_Math::validate_row( 'monthly', 'X', 15, 'minutes', 3 ) );
		$this->assertSame( 'bad_time', Time_Math::validate_row( 'daily', 'X', 0, 'minutes', 3 ) );
		$this->assertSame( 'bad_time', Time_Math::validate_row( 'daily', 'X', 11, 'hours', 1 ) );
		$this->assertSame( 'bad_frequency', Time_Math::validate_row( 'weekly', 'X', 15, 'minutes', 0 ) );
		$this->assertSame( 'bad_unit', Time_Math::validate_row( 'weekly', 'X', 15, 'days', 1 ) );
	}

	// Ranking.

	public function test_ranking_weights_time_by_suitability(): void {
		$cards = array(
			array( 'name' => 'A', 'task_ids' => array( 1 ), 'suitability' => 2 ),      // 600 * 0.4 = 240
			array( 'name' => 'B', 'task_ids' => array( 2, 3 ), 'suitability' => 5 ),   // 300 = 300
			array( 'name' => 'C', 'task_ids' => array( 3 ), 'suitability' => 5 ),      // 100
		);
		$ranked = Ranker::rank( $cards, array( 1 => 600, 2 => 200, 3 => 100 ) );
		$this->assertSame( array( 'B', 'A', 'C' ), array_column( $ranked, 'name' ) );
		$this->assertSame( 300, $ranked[0]['weekly_minutes'] );
		$this->assertSame( 600, $ranked[1]['weekly_minutes'] );
	}

	public function test_ranking_counts_a_task_once_per_card_and_is_stable(): void {
		$cards  = array(
			array( 'name' => 'A', 'task_ids' => array( 1, 1 ), 'suitability' => 5 ),
			array( 'name' => 'B', 'task_ids' => array( 1 ), 'suitability' => 5 ),
		);
		$ranked = Ranker::rank( $cards, array( 1 => 60 ) );
		$this->assertSame( 60, $ranked[0]['weekly_minutes'] );
		$this->assertSame( array( 'A', 'B' ), array_column( $ranked, 'name' ) );
	}

	// Schema validation.

	public function test_valid_recommendations_pass_with_both_tag_sets(): void {
		$res = Schemas::validate_recommendations( $this->cards( array( 1, 2, 3 ) ), array( 1, 2, 3 ) );
		$this->assertTrue( $res['ok'], implode( ', ', $res['errors'] ?? array() ) );
		$this->assertCount( 5, $res['cards'] );
		$this->assertEqualsCanonicalizing( array( 'skill', 'project' ), array_values( array_unique( array_column( $res['cards'], 'build_tag' ) ) ) );
		$this->assertEqualsCanonicalizing( array( 'scribe', 'scout', 'coach' ), array_values( array_unique( array_column( $res['cards'], 'role_tag' ) ) ) );
	}

	public function test_unknown_build_or_role_tag_fails(): void {
		$data                          = $this->cards( array( 1 ) );
		$data['cards'][0]['build_tag'] = 'agent';
		$data['cards'][1]['role_tag']  = 'closer';
		$res                           = Schemas::validate_recommendations( $data, array( 1 ) );
		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'bad build_tag', implode( ' ', $res['errors'] ) );
		$this->assertStringContainsString( 'bad role_tag', implode( ' ', $res['errors'] ) );
	}

	public function test_task_ids_must_belong_to_participant(): void {
		$data                         = $this->cards( array( 1 ) );
		$data['cards'][0]['task_ids'] = array( 99 );
		$this->assertFalse( Schemas::validate_recommendations( $data, array( 1 ) )['ok'] );
	}

	public function test_suitability_range(): void {
		$data                            = $this->cards( array( 1 ) );
		$data['cards'][0]['suitability'] = 6;
		$this->assertFalse( Schemas::validate_recommendations( $data, array( 1 ) )['ok'] );
	}

	public function test_fewer_than_five_cards_fails(): void {
		$data = $this->cards( array( 1 ) );
		array_pop( $data['cards'] );
		$res = Schemas::validate_recommendations( $data, array( 1 ) );
		$this->assertFalse( $res['ok'] );
	}

	public function test_naming_rules(): void {
		$this->assertFalse( Schemas::name_ok( 'Kills the Logbook Tax' ) );
		$this->assertFalse( Schemas::name_ok( 'The Admin Drag Killer' ) );
		$this->assertFalse( Schemas::name_ok( 'The Digital Crew Desk' ) );
		$this->assertTrue( Schemas::name_ok( 'Ends the Spec Hunt' ) );
		$this->assertTrue( Schemas::name_ok( 'The Taxonomy Desk' ) );
	}

	public function test_text_is_cleaned_of_dashes(): void {
		$data                            = $this->cards( array( 1 ) );
		$data['cards'][0]['description'] = "It drafts \u{2014} then checks \u{2013} the notes.";
		$res                             = Schemas::validate_recommendations( $data, array( 1 ) );
		$this->assertSame( 'It drafts - then checks - the notes.', $res['cards'][0]['description'] );
	}

	public function test_internal_term_rejected_in_text(): void {
		$parts                  = $this->prompt_parts();
		$parts['job_paragraph'] = 'Built in the Patient Architect voice.';
		$this->assertFalse( Schemas::validate_prompt_parts( $parts )['ok'] );
	}

	public function test_prompt_parts_need_six_territories_and_cap_at_eight(): void {
		$parts                = $this->prompt_parts();
		$parts['territories'] = array( 'a', 'b', 'c' );
		$this->assertFalse( Schemas::validate_prompt_parts( $parts )['ok'] );
		$parts['territories'] = array_fill( 0, 10, 'x' );
		$res                  = Schemas::validate_prompt_parts( $parts );
		$this->assertTrue( $res['ok'] );
		$this->assertCount( 8, $res['parts']['territories'] );
	}

	public function test_industry_validation(): void {
		$this->assertSame( 'Logistics', Schemas::validate_industry( array( 'industry' => ' Logistics ' ) )['industry'] );
		$this->assertFalse( Schemas::validate_industry( array( 'industry' => '' ) )['ok'] );
		$this->assertFalse( Schemas::validate_industry( 'Logistics' )['ok'] );
	}

	public function test_api_schemas_meet_structured_output_rules(): void {
		foreach ( array( Schemas::industry(), Schemas::recommendations(), Schemas::prompt_parts() ) as $schema ) {
			$this->walk_schema( $schema );
		}
	}

	private function walk_schema( array $node ): void {
		if ( 'object' === ( $node['type'] ?? '' ) ) {
			$this->assertFalse( $node['additionalProperties'] ?? null, 'objects need additionalProperties false' );
			$this->assertSame( array_keys( $node['properties'] ), $node['required'] );
			foreach ( $node['properties'] as $child ) {
				$this->walk_schema( $child );
			}
		}
		if ( 'array' === ( $node['type'] ?? '' ) ) {
			$this->walk_schema( $node['items'] );
		}
		foreach ( array( 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems' ) as $unsupported ) {
			$this->assertArrayNotHasKey( $unsupported, $node );
		}
	}

	// Template fill.

	private function vars(): array {
		$vars = array();
		foreach ( Template::REQUIRED_VARS as $v ) {
			$vars[ $v ] = 'V_' . $v;
		}
		return $vars;
	}

	public function test_template_fills_skill_branch_only(): void {
		$text = Template::fill( Template::load(), $this->vars(), 'skill' );
		$this->assertStringContainsString( 'Produce the complete Skill', $text );
		$this->assertStringNotContainsString( 'step-by-step setup', $text );
		$this->assertStringNotContainsString( '{{', $text );
	}

	public function test_template_fills_project_branch_only(): void {
		$text = Template::fill( Template::load(), $this->vars(), 'project' );
		$this->assertStringContainsString( 'step-by-step setup', $text );
		$this->assertStringNotContainsString( 'Produce the complete Skill', $text );
	}

	public function test_template_keeps_fixed_structure(): void {
		$text = Template::fill( Template::load(), $this->vars(), 'skill' );
		foreach ( array( 'Why it exists: V_drag_name. The friction it ends: V_drag_line V_not_do_line', 'Ask one question at a time', 'ask me which AI tool', 'Persona:', 'Resources:', 'Instructions:', 'Constraints:', 'Examples:', 'Compliance:', 'Never guess what I meant', 'Never just agree with me', 'Start by asking me your first question. Nothing else.', 'Microsoft 365 Copilot without Copilot Studio, treat it as a free plan', 'On a free plan: give me the packet as one block' ) as $fixed ) {
			$this->assertStringContainsString( $fixed, $text );
		}
		$this->assertStringNotContainsString( 'Context:', $text );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $text );
		$this->assertStringNotContainsStringIgnoringCase( 'patient architect', $text );
	}

	public function test_values_are_not_re_expanded(): void {
		$vars                     = $this->vars();
		$vars['job_paragraph']    = 'Uses {{crew_member_name}} literally.';
		$text                     = Template::fill( Template::load(), $vars, 'skill' );
		$this->assertStringContainsString( 'Uses {{crew_member_name}} literally.', $text );
	}

	public function test_missing_value_throws(): void {
		$vars = $this->vars();
		unset( $vars['territories'] );
		$this->expectException( \RuntimeException::class );
		Template::fill( Template::load(), $vars, 'skill' );
	}

	public function test_task_list_and_territories_format(): void {
		$this->assertSame( "- Inbox (2 hr a week)\n- Reports (45 min a week)", Template::task_list( array( array( 'name' => 'Inbox', 'weekly_minutes' => 120 ), array( 'name' => 'Reports', 'weekly_minutes' => 45 ) ) ) );
		$this->assertSame( "1. a\n2. b", Template::territories( array( 'a', 'b' ) ) );
	}

	// Anthropic request and response handling.

	public function test_request_body_uses_structured_output_and_fallbacks(): void {
		$body = Anthropic::body( 'sys', 'user', Schemas::industry(), 1000, 'low' );
		$this->assertSame( 'claude-opus-5-5', $body['model'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( 'low', $body['output_config']['effort'] );
		$this->assertSame( 'default', $body['fallbacks'] );
		$this->assertArrayNotHasKey( 'thinking', $body );
		$this->assertArrayNotHasKey( 'temperature', $body );
		Config::set( 'AIHAT_HIRE_FALLBACKS', false );
		Config::set( 'AIHAT_HIRE_MODEL', 'claude-sonnet-5-5' );
		$body = Anthropic::body( 'sys', 'user', Schemas::industry(), 1000, 'low' );
		$this->assertArrayNotHasKey( 'fallbacks', $body );
		$this->assertSame( 'claude-sonnet-5-5', $body['model'] );
	}

	public function test_headers_and_timeout(): void {
		$this->api_reply( array( 'industry' => 'Logistics' ) );
		( new Anthropic( function ( $url, $args ) {
			$this->api_calls[] = $args;
			return array_shift( $this->api_queue );
		} ) )->json( 's', 'u', Schemas::industry() );
		$args = $this->api_calls[0];
		$this->assertSame( 'test-key', $args['headers']['x-api-key'] );
		$this->assertSame( '2026-10-21', $args['headers']['anthropic-version'] );
		$this->assertSame( 'server-side-fallback-2026-07-01', $args['headers']['anthropic-beta'] );
		$this->assertSame( 90, $args['timeout'] );
	}

	public function test_parse_success_and_errors(): void {
		$ok = Anthropic::parse( array( 'code' => 200, 'body' => json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '{"a":1}' ) ) ) ) ) );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( array( 'a' => 1 ), $ok['data'] );

		$rl = Anthropic::parse( array( 'code' => 429, 'body' => '{"error":{"type":"rate_limit_error"}}', 'headers' => array( 'Retry-After' => '7' ) ) );
		$this->assertFalse( $rl['ok'] );
		$this->assertTrue( $rl['retryable'] );
		$this->assertSame( 7, $rl['retry_after'] );

		$this->assertTrue( Anthropic::parse( array( 'code' => 529, 'body' => '{"error":{"type":"overloaded_error"}}' ) )['retryable'] );
		$this->assertFalse( Anthropic::parse( array( 'code' => 400, 'body' => '{"error":{"type":"invalid_request_error"}}' ) )['retryable'] );
		$this->assertTrue( Anthropic::parse( array( 'code' => 0, 'error' => 'timeout' ) )['retryable'] );

		$refusal = Anthropic::parse( array( 'code' => 200, 'body' => json_encode( array( 'stop_reason' => 'refusal', 'content' => array() ) ) ) );
		$this->assertSame( 'refusal', $refusal['error'] );
		$this->assertFalse( $refusal['retryable'] );

		$bad = Anthropic::parse( array( 'code' => 200, 'body' => json_encode( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => 'not json' ) ) ) ) ) );
		$this->assertSame( 'json_parse', $bad['error'] );
	}

	public function test_no_key_means_no_call(): void {
		Config::set( 'AIHAT_HIRE_ANTHROPIC_KEY', '' );
		$called = false;
		$res    = ( new Anthropic( function () use ( &$called ) {
			$called = true;
			return array();
		} ) )->json( 's', 'u', array() );
		$this->assertFalse( $called );
		$this->assertSame( 'not_configured', $res['error'] );
	}

	// CSV safety.

	public function test_csv_formula_injection_is_neutralized(): void {
		$this->assertSame( "'=HYPERLINK(1)", Admin::csv_safe( '=HYPERLINK(1)' ) );
		$this->assertSame( "'+1", Admin::csv_safe( '+1' ) );
		$this->assertSame( 'Pat', Admin::csv_safe( 'Pat' ) );
	}
}
