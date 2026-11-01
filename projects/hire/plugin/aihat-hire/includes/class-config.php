<?php
/**
 * Settings. Every value can be set as a constant in wp-config.php; the
 * defaults below apply when it is not.
 */

namespace AIHat\Hire;

final class Config {

	/** Constant name => default value. */
	public const DEFAULTS = array(
		// Anthropic.
		'AIHAT_HIRE_ANTHROPIC_KEY'      => '',
		'AIHAT_HIRE_MODEL'              => 'claude-opus-5-5',
		'AIHAT_HIRE_EFFORT'             => 'medium',
		'AIHAT_HIRE_API_TIMEOUT'        => 90,   // Seconds per API request.
		'AIHAT_HIRE_FALLBACKS'          => true, // Server-side refusal fallback.
		// Queue.
		'AIHAT_HIRE_MAX_CONCURRENT'     => 4,    // Jobs running at once.
		// Limits.
		'AIHAT_HIRE_DAILY_RUNS'         => 5,    // Recommendation runs per participant per day.
		'AIHAT_HIRE_DAILY_PROMPTS'      => 10,   // Prompt generations per participant per day.
		'AIHAT_HIRE_DAILY_PROMPT_EMAILS'=> 5,    // "Email me this prompt" sends per participant per day.
		'AIHAT_HIRE_IP_CODE_LIMIT'      => 120,  // Code requests per IP per hour. A room on one wifi shares an IP.
		'AIHAT_HIRE_EMAIL_CODE_LIMIT'   => 5,    // Code requests per email per hour.
		'AIHAT_HIRE_CODE_TTL'           => 900,  // Seconds a code stays valid.
		'AIHAT_HIRE_CODE_ATTEMPTS'      => 5,    // Wrong guesses before a code is void.
		'AIHAT_HIRE_SESSION_DAYS'       => 30,
		'AIHAT_HIRE_MIN_TASKS'          => 5,
		'AIHAT_HIRE_MAX_TASKS'          => 40,
		'AIHAT_HIRE_WORKDAYS'           => 5,    // Days a week a daily task happens.
		'AIHAT_HIRE_RETENTION_DAYS'     => 365,  // Delete after this long since last sign-in.
		// Email (SMTP). Empty host means plain wp_mail.
		'AIHAT_HIRE_SMTP_HOST'          => '',
		'AIHAT_HIRE_SMTP_PORT'          => 465,
		'AIHAT_HIRE_SMTP_SECURE'        => 'ssl',
		'AIHAT_HIRE_SMTP_USER'          => '',
		'AIHAT_HIRE_SMTP_PASS'          => '',
		'AIHAT_HIRE_MAIL_FROM'          => '',
		'AIHAT_HIRE_MAIL_FROM_NAME'     => 'The AI Hat',
		// Constant Contact via Make.
		'AIHAT_HIRE_CTCT_WEBHOOK'       => '',
		'AIHAT_HIRE_CTCT_SECRET'        => '',   // Optional HMAC signing secret.
		// Front end.
		'AIHAT_HIRE_PRIVACY_URL'        => '',   // Defaults to the site's privacy page.
		'AIHAT_HIRE_LOAD_FONTS'         => true,
		'AIHAT_HIRE_CONSENT_VERSION'    => 'v1-2026-11',
	);

	/** Values set by tests. */
	private static array $overrides = array();

	public static function get( string $name ) {
		if ( array_key_exists( $name, self::$overrides ) ) {
			return self::$overrides[ $name ];
		}
		if ( defined( $name ) ) {
			return constant( $name );
		}
		return self::DEFAULTS[ $name ] ?? null;
	}

	public static function int( string $name ): int {
		return (int) self::get( $name );
	}

	public static function str( string $name ): string {
		return (string) self::get( $name );
	}

	public static function bool( string $name ): bool {
		return (bool) self::get( $name );
	}

	public static function set( string $name, $value ): void {
		self::$overrides[ $name ] = $value;
	}

	public static function reset(): void {
		self::$overrides = array();
	}
}
