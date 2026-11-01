<?php
/**
 * JSON schemas sent to the API as structured outputs, and the code-side
 * validation of what comes back. The API guarantees shape; this class checks
 * the rules the schema language can't express (counts, ranges, known task
 * ids, the naming rules) and cleans text. Pure PHP, no WordPress.
 */

namespace AIHat\Hire;

final class Schemas {

	public const BUILD_TAGS = array( 'skill', 'project' );
	public const ROLE_TAGS  = array( 'scout', 'scribe', 'coach' );
	public const CARD_COUNT = 5;

	/** Published and internal terms a model-made name must not use. */
	public const BANNED_IN_NAMES = array(
		'admin drag',
		'toggle tax',
		'pilot purgatory',
		'digital crew',
		'patient architect',
	);

	/** Never allowed anywhere in participant-facing text. */
	public const BANNED_ANYWHERE = array( 'patient architect' );

	public static function industry(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'industry' => array( 'type' => 'string' ),
			),
			'required'             => array( 'industry' ),
			'additionalProperties' => false,
		);
	}

	public static function recommendations(): array {
		$string_list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'cards' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'        => array( 'type' => 'string' ),
							'build_tag'   => array(
								'type' => 'string',
								'enum' => self::BUILD_TAGS,
							),
							'role_tag'    => array(
								'type' => 'string',
								'enum' => self::ROLE_TAGS,
							),
							'drag_name'   => array( 'type' => 'string' ),
							'drag_line'   => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'produces'    => $string_list,
							'task_ids'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'suitability' => array( 'type' => 'integer' ),
							'preflight'   => $string_list,
						),
						'required'             => array( 'name', 'build_tag', 'role_tag', 'drag_name', 'drag_line', 'description', 'produces', 'task_ids', 'suitability', 'preflight' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'cards' ),
			'additionalProperties' => false,
		);
	}

	public static function prompt_parts(): array {
		$s = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'specialist_role' => $s,
				'job_paragraph'   => $s,
				'drag_line'       => $s,
				'not_do_line'     => $s,
				'territories'     => array(
					'type'  => 'array',
					'items' => $s,
				),
				'structural_rule' => $s,
				'compliance_line' => $s,
			),
			'required'             => array( 'specialist_role', 'job_paragraph', 'drag_line', 'not_do_line', 'territories', 'structural_rule', 'compliance_line' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Plain-text cleanup for anything a participant will see: em and en
	 * dashes become " - ", whitespace collapses, length is capped.
	 */
	public static function clean( string $text, int $max = 600 ): string {
		$text = str_replace( array( "\u{2014}", "\u{2013}" ), ' - ', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ - +- /', ' - ', $text );
		$text = trim( (string) $text );
		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	private static function contains_any( string $text, array $terms ): bool {
		$lower = mb_strtolower( $text );
		foreach ( $terms as $term ) {
			if ( str_contains( $lower, $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks a model-made name against the naming rules.
	 */
	public static function name_ok( string $name ): bool {
		if ( '' === trim( $name ) ) {
			return false;
		}
		if ( preg_match( '/\btax\W*$/i', trim( $name ) ) ) {
			return false;
		}
		return ! self::contains_any( $name, self::BANNED_IN_NAMES );
	}

	/**
	 * Validates and cleans the industry result.
	 *
	 * @return array{ok:bool, industry?:string, error?:string}
	 */
	public static function validate_industry( $data ): array {
		if ( ! is_array( $data ) || ! isset( $data['industry'] ) || ! is_string( $data['industry'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'shape',
			);
		}
		$industry = self::clean( $data['industry'], 80 );
		if ( '' === $industry ) {
			return array(
				'ok'    => false,
				'error' => 'empty',
			);
		}
		return array(
			'ok'       => true,
			'industry' => $industry,
		);
	}

	/**
	 * Validates the recommendation cards against the participant's tasks.
	 *
	 * @param mixed           $data      Decoded model output.
	 * @param array<int, int> $known_ids Task ids that belong to this participant.
	 * @return array{ok:bool, cards?:array, errors?:array}
	 */
	public static function validate_recommendations( $data, array $known_ids ): array {
		$errors = array();
		if ( ! is_array( $data ) || ! isset( $data['cards'] ) || ! is_array( $data['cards'] ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'shape' ),
			);
		}
		$known = array_flip( array_map( 'intval', $known_ids ) );
		$cards = array();
		foreach ( $data['cards'] as $n => $card ) {
			$err = self::card_error( $card, $known );
			if ( '' !== $err ) {
				$errors[] = "card {$n}: {$err}";
				continue;
			}
			$cards[] = array(
				'name'        => self::clean( $card['name'], 60 ),
				'build_tag'   => $card['build_tag'],
				'role_tag'    => $card['role_tag'],
				'drag_name'   => self::clean( $card['drag_name'], 60 ),
				'drag_line'   => self::clean( $card['drag_line'], 200 ),
				'description' => self::clean( $card['description'], 400 ),
				'produces'    => array_values( array_slice( array_map( static fn( $p ) => self::clean( (string) $p, 160 ), $card['produces'] ), 0, 5 ) ),
				'task_ids'    => array_values( array_unique( array_map( 'intval', $card['task_ids'] ) ) ),
				'suitability' => (int) $card['suitability'],
				'preflight'   => array_values( array_slice( array_map( static fn( $p ) => self::clean( (string) $p, 160 ), $card['preflight'] ), 0, 3 ) ),
			);
		}
		if ( count( $cards ) < self::CARD_COUNT ) {
			$errors[] = 'need ' . self::CARD_COUNT . ' valid cards, got ' . count( $cards );
			return array(
				'ok'     => false,
				'errors' => $errors,
			);
		}
		return array(
			'ok'     => true,
			'cards'  => $cards,
			'errors' => $errors,
		);
	}

	private static function card_error( $card, array $known ): string {
		if ( ! is_array( $card ) ) {
			return 'not an object';
		}
		foreach ( array( 'name', 'build_tag', 'role_tag', 'drag_name', 'drag_line', 'description' ) as $key ) {
			if ( ! isset( $card[ $key ] ) || ! is_string( $card[ $key ] ) || '' === trim( $card[ $key ] ) ) {
				return "missing {$key}";
			}
		}
		if ( ! in_array( $card['build_tag'], self::BUILD_TAGS, true ) ) {
			return 'bad build_tag';
		}
		if ( ! in_array( $card['role_tag'], self::ROLE_TAGS, true ) ) {
			return 'bad role_tag';
		}
		if ( ! self::name_ok( $card['name'] ) ) {
			return 'name breaks naming rules';
		}
		if ( ! self::name_ok( $card['drag_name'] ) ) {
			return 'drag_name breaks naming rules';
		}
		foreach ( array( 'description', 'drag_line' ) as $key ) {
			if ( self::contains_any( $card[ $key ], self::BANNED_ANYWHERE ) ) {
				return "{$key} uses an internal term";
			}
		}
		foreach ( array( 'produces', 'preflight', 'task_ids' ) as $key ) {
			if ( ! isset( $card[ $key ] ) || ! is_array( $card[ $key ] ) || ! $card[ $key ] ) {
				return "missing {$key}";
			}
		}
		// More than three preflight items are trimmed to three later, not rejected.
		foreach ( $card['task_ids'] as $id ) {
			if ( ! is_int( $id ) || ! isset( $known[ $id ] ) ) {
				return 'unknown task id';
			}
		}
		if ( ! isset( $card['suitability'] ) || ! is_int( $card['suitability'] ) || $card['suitability'] < 1 || $card['suitability'] > 5 ) {
			return 'suitability out of range';
		}
		return '';
	}

	/**
	 * Validates the drafted parts of the interview prompt.
	 *
	 * @return array{ok:bool, parts?:array, errors?:array}
	 */
	public static function validate_prompt_parts( $data ): array {
		if ( ! is_array( $data ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'shape' ),
			);
		}
		$parts  = array();
		$limits = array(
			'specialist_role' => 120,
			'job_paragraph'   => 900,
			'drag_line'       => 240,
			'not_do_line'     => 300,
			'structural_rule' => 240,
			'compliance_line' => 400,
		);
		foreach ( $limits as $key => $max ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === trim( $data[ $key ] ) ) {
				return array(
					'ok'     => false,
					'errors' => array( "missing {$key}" ),
				);
			}
			if ( self::contains_any( $data[ $key ], self::BANNED_ANYWHERE ) ) {
				return array(
					'ok'     => false,
					'errors' => array( "{$key} uses an internal term" ),
				);
			}
			$parts[ $key ] = self::clean( $data[ $key ], $max );
		}
		if ( ! isset( $data['territories'] ) || ! is_array( $data['territories'] ) ) {
			return array(
				'ok'     => false,
				'errors' => array( 'missing territories' ),
			);
		}
		$territories = array_values(
			array_filter(
				array_map( static fn( $t ) => is_string( $t ) ? self::clean( $t, 240 ) : '', $data['territories'] ),
				static fn( $t ) => '' !== $t
			)
		);
		if ( count( $territories ) < 6 ) {
			return array(
				'ok'     => false,
				'errors' => array( 'need 6 to 8 territories, got ' . count( $territories ) ),
			);
		}
		$parts['territories'] = array_slice( $territories, 0, 8 );
		return array(
			'ok'    => true,
			'parts' => $parts,
		);
	}
}
