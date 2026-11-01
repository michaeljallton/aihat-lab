<?php
/**
 * Sign-in: emailed 6-digit codes and per-device session cookies. Codes and
 * session tokens are stored only as SHA-256 hashes.
 */

namespace AIHat\Hire;

final class Auth {

	public const COOKIE = 'aihat_hire_s';

	/** Session token from the current request, set by tests or read from the cookie. */
	private static ?string $token = null;

	public static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	public static function new_code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	public static function normalize_code( string $code ): string {
		return preg_replace( '/\D/', '', $code );
	}

	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Records a new code for an email and returns the plain code to send.
	 *
	 * @param array|null $profile Sign-up details held until the code is used.
	 */
	public static function issue_code( string $email, ?array $profile, int $workshop_id, int $now ): string {
		$code = self::new_code();
		Store::create_code(
			array(
				'email'       => $email,
				'code_hash'   => self::hash( $email . '|' . $code ),
				'profile'     => $profile ? wp_json_encode_safe( $profile ) : null,
				'workshop_id' => $workshop_id,
				'attempts'    => 0,
				'expires_at'  => $now + Config::int( 'AIHAT_HIRE_CODE_TTL' ),
				'used_at'     => 0,
				'created_at'  => $now,
			)
		);
		return $code;
	}

	/**
	 * Checks a code against the newest one issued for the email.
	 *
	 * @return array{ok:bool, error?:string, row?:array}
	 */
	public static function check_code( string $email, string $code, int $now ): array {
		$row = Store::latest_code( $email );
		if ( ! $row || (int) $row['used_at'] > 0 ) {
			return array(
				'ok'    => false,
				'error' => 'no_code',
			);
		}
		if ( (int) $row['expires_at'] < $now ) {
			return array(
				'ok'    => false,
				'error' => 'expired',
			);
		}
		if ( (int) $row['attempts'] >= Config::int( 'AIHAT_HIRE_CODE_ATTEMPTS' ) ) {
			return array(
				'ok'    => false,
				'error' => 'too_many_attempts',
			);
		}
		$code = self::normalize_code( $code );
		if ( 6 !== strlen( $code ) || ! hash_equals( $row['code_hash'], self::hash( $email . '|' . $code ) ) ) {
			Store::bump_code_attempts( (int) $row['id'] );
			return array(
				'ok'    => false,
				'error' => 'wrong_code',
			);
		}
		if ( ! Store::use_code( (int) $row['id'], $now ) ) {
			return array(
				'ok'    => false,
				'error' => 'no_code',
			);
		}
		return array(
			'ok'  => true,
			'row' => $row,
		);
	}

	/**
	 * Starts a session for a participant and sets the cookie.
	 */
	public static function start_session( int $participant_id, int $now ): string {
		$token   = bin2hex( random_bytes( 32 ) );
		$expires = $now + Config::int( 'AIHAT_HIRE_SESSION_DAYS' ) * DAY_IN_SECONDS;
		Store::create_session( $participant_id, self::hash( $token ), $expires, $now );
		self::$token = $token;
		if ( ! headers_sent() && function_exists( 'is_ssl' ) ) {
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => $expires,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		return $token;
	}

	public static function set_token( ?string $token ): void {
		self::$token = $token;
	}

	private static function token(): string {
		if ( null !== self::$token ) {
			return self::$token;
		}
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		return preg_match( '/^[a-f0-9]{64}$/', $raw ) ? $raw : '';
	}

	/**
	 * The signed-in participant, or null.
	 */
	public static function participant( int $now ): ?array {
		$token = self::token();
		if ( '' === $token ) {
			return null;
		}
		$session = Store::session( self::hash( $token ) );
		if ( ! $session || (int) $session['expires_at'] < $now ) {
			return null;
		}
		return Store::participant( (int) $session['participant_id'] );
	}

	public static function end_session(): void {
		$token = self::token();
		if ( '' !== $token ) {
			Store::delete_session( self::hash( $token ) );
		}
		self::$token = '';
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => 1,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}
}
