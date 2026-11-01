<?php
/**
 * Fills templates/interview-prompt.md. Pure PHP, no WordPress.
 *
 * Syntax:
 *   {{name}}                 replaced with a value (single pass, so values that
 *                            happen to contain braces are never re-expanded)
 *   {{#skill}}...{{/skill}}  kept only for the Skill tag
 *   {{#project}}...{{/project}} kept only for the Project/Notebook tag
 */

namespace AIHat\Hire;

final class Template {

	public const REQUIRED_VARS = array(
		'specialist_role',
		'crew_member_name',
		'participant_name',
		'participant_title',
		'participant_company',
		'participant_industry',
		'job_paragraph',
		'drag_name',
		'drag_line',
		'not_do_line',
		'task_list',
		'territories',
		'structural_rule',
		'compliance_line',
	);

	public static function path(): string {
		return dirname( __DIR__ ) . '/templates/interview-prompt.md';
	}

	public static function load( ?string $path = null ): string {
		$text = file_get_contents( $path ?? self::path() );
		if ( false === $text ) {
			throw new \RuntimeException( 'Interview prompt template is missing.' );
		}
		return str_replace( "\r\n", "\n", $text );
	}

	/**
	 * @param string               $template  Template text.
	 * @param array<string,string> $vars      Values for {{name}} placeholders.
	 * @param string               $build_tag skill|project.
	 */
	public static function fill( string $template, array $vars, string $build_tag ): string {
		$keep = 'skill' === $build_tag ? 'skill' : 'project';
		$drop = 'skill' === $build_tag ? 'project' : 'skill';

		$text = preg_replace( '/\{\{#' . $drop . '\}\}.*?\{\{\/' . $drop . '\}\}\n?/s', '', $template );
		$text = preg_replace( '/\{\{#' . $keep . '\}\}\n?(.*?)\{\{\/' . $keep . '\}\}\n?/s', '$1', (string) $text );

		// Every placeholder the template asks for must have a value. Checked
		// before filling, so braces inside participant text are left alone.
		preg_match_all( '/\{\{([#\/]?[a-z_]+)\}\}/', (string) $text, $m );
		foreach ( array_unique( $m[1] ) as $name ) {
			if ( ! array_key_exists( $name, $vars ) ) {
				throw new \RuntimeException( 'Unfilled template placeholder: {{' . $name . '}}' );
			}
		}

		$pairs = array();
		foreach ( $vars as $name => $value ) {
			$pairs[ '{{' . $name . '}}' ] = (string) $value;
		}
		$text = strtr( (string) $text, $pairs );

		return preg_replace( "/\n{3,}/", "\n\n", trim( $text ) ) . "\n";
	}

	/**
	 * One line per task: "- Name (2 hr a week)".
	 *
	 * @param array<int, array{name:string, weekly_minutes:int}> $tasks
	 */
	public static function task_list( array $tasks ): string {
		$lines = array();
		foreach ( $tasks as $task ) {
			$lines[] = '- ' . $task['name'] . ' (' . Time_Math::label( (int) $task['weekly_minutes'] ) . ' a week)';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Numbered territory lines.
	 *
	 * @param string[] $territories
	 */
	public static function territories( array $territories ): string {
		$lines = array();
		foreach ( array_values( $territories ) as $i => $t ) {
			$lines[] = ( $i + 1 ) . '. ' . $t;
		}
		return implode( "\n", $lines );
	}
}
