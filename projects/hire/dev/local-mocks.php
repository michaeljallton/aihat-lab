<?php
/**
 * LOCAL TESTING ONLY - never install on a live site.
 * Must-use plugin for a local WordPress: answers Anthropic API calls and the
 * Make webhook with canned responses, and writes emails to a log instead of
 * sending them. Log files go to AIHAT_HIRE_MOCK_LOG_DIR (default /tmp/wp).
 */

if ( ! defined( 'AIHAT_HIRE_MOCK_LOG_DIR' ) ) {
	define( 'AIHAT_HIRE_MOCK_LOG_DIR', '/tmp/wp' );
}

function aihat_hire_mock_log( $file, $line ) {
	file_put_contents( AIHAT_HIRE_MOCK_LOG_DIR . '/' . $file, $line . "\n", FILE_APPEND );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( str_contains( $url, 'api.anthropic.com' ) ) {
			$body  = json_decode( $args['body'], true );
			aihat_hire_mock_log( 'api.log', wp_json_encode( array( 'headers' => array_diff_key( $args['headers'], array( 'x-api-key' => 1 ) ), 'body' => $body ) ) );
			$props = array_keys( $body['output_config']['format']['schema']['properties'] );
			sleep( 2 ); // Feel of a real call.
			if ( in_array( 'industry', $props, true ) ) {
				$data = array( 'industry' => 'Freight logistics' );
			} elseif ( in_array( 'cards', $props, true ) ) {
				preg_match_all( '/\[id (\d+)\]/', $body['messages'][0]['content'], $m );
				$ids   = array_map( 'intval', $m[1] );
				$specs = array(
					array( 'The Shipment Scribe', 'skill', 'scribe', 'Ends the Status Chase', 'Turns raw carrier updates into one clean status note for each customer.' ),
					array( 'The Lane Scout', 'project', 'scout', 'Opens the Someday File', 'Researches new lanes and shippers and returns a one-page brief.' ),
					array( 'The Rate Desk', 'project', 'coach', 'Retires the Rate Hunt', 'Answers rate and contract questions from your own rate sheets.' ),
					array( 'The Exception Clerk', 'skill', 'scribe', 'Ends the Exception Shuffle', 'Drafts the exception report and the customer notice from the day\'s alerts.' ),
					array( 'The Onboarding Coach', 'project', 'coach', 'Retires the Second Walkthrough', 'Briefs new team members from the playbook you already have.' ),
				);
				$cards = array();
				foreach ( $specs as $i => $s ) {
					$cards[] = array(
						'name'        => $s[0],
						'build_tag'   => $s[1],
						'role_tag'    => $s[2],
						'drag_name'   => $s[3],
						'drag_line'   => 'Time lost doing the same small job by hand.',
						'description' => $s[4],
						'produces'    => array( 'A ready-to-send draft', 'A short summary for your records' ),
						'task_ids'    => array( $ids[ $i % count( $ids ) ], $ids[ ( $i + 1 ) % count( $ids ) ] ),
						'suitability' => 5 - ( $i % 3 ),
						'preflight'   => array( 'One recent example of this work', 'Your current template, if you have one' ),
					);
				}
				$data = array( 'cards' => $cards );
			} else {
				$data = array(
					'specialist_role' => 'freight logistics operations specialist',
					'job_paragraph'   => 'It takes the carrier updates I paste in each morning and turns them into one status note per customer, ready for me to review and send from my email.',
					'drag_line'       => 'The first hour of every day spent retyping the same updates.',
					'not_do_line'     => 'It never sends anything to a customer or changes a booking; those decisions stay with me.',
					'territories'     => array(
						'Where the carrier updates come from and what they look like',
						'What a complete customer status note must contain',
						'Who receives each note and how they like it written',
						'The email and TMS formats the note has to fit',
						'The rules I follow about delays and what I can promise',
						'The best status note I have sent',
						'How I want exceptions flagged',
					),
					'structural_rule' => 'one short note per customer with the status in the first line',
					'compliance_line' => 'Company policy on sharing shipment and pricing details applies, and nothing confidential about one customer appears in another customer\'s note.',
				);
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'type'        => 'message',
						'stop_reason' => 'end_turn',
						'content'     => array( array( 'type' => 'text', 'text' => wp_json_encode( $data ) ) ),
						'usage'       => array( 'input_tokens' => 1, 'output_tokens' => 1 ),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		if ( str_contains( $url, 'hook.local.test' ) ) {
			aihat_hire_mock_log( 'hooks.log', wp_json_encode( array( 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ) ) ) );
			return array( 'headers' => array(), 'body' => 'Accepted', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	},
	10,
	3
);

add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		aihat_hire_mock_log( 'mail.log', wp_json_encode( $atts ) );
		return true;
	},
	10,
	2
);
