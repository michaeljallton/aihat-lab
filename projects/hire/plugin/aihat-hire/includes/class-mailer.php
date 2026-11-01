<?php
/**
 * Sends the plugin's own emails. When AIHAT_HIRE_SMTP_HOST is set, these
 * messages (and only these) go through that SMTP mailbox.
 */

namespace AIHat\Hire;

final class Mailer {

	private static bool $active = false;

	/** @var callable|null Test hook: fn(string $to, string $subject, string $body): bool */
	private static $sender = null;

	public static function set_sender( ?callable $sender ): void {
		self::$sender = $sender;
	}

	public static function smtp_configured(): bool {
		return '' !== Config::str( 'AIHAT_HIRE_SMTP_HOST' );
	}

	/**
	 * phpmailer_init hook. Applies only while this class is sending.
	 */
	public static function configure( $phpmailer ): void {
		if ( ! self::$active || ! self::smtp_configured() ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host       = Config::str( 'AIHAT_HIRE_SMTP_HOST' );
		$phpmailer->Port       = Config::int( 'AIHAT_HIRE_SMTP_PORT' );
		$phpmailer->SMTPSecure = Config::str( 'AIHAT_HIRE_SMTP_SECURE' );
		$phpmailer->SMTPAuth   = '' !== Config::str( 'AIHAT_HIRE_SMTP_USER' );
		$phpmailer->Username   = Config::str( 'AIHAT_HIRE_SMTP_USER' );
		$phpmailer->Password   = Config::str( 'AIHAT_HIRE_SMTP_PASS' );
		$from                  = Config::str( 'AIHAT_HIRE_MAIL_FROM' ) ?: Config::str( 'AIHAT_HIRE_SMTP_USER' );
		if ( $from ) {
			$phpmailer->setFrom( $from, Config::str( 'AIHAT_HIRE_MAIL_FROM_NAME' ), false );
		}
	}

	public static function send( string $to, string $subject, string $body ): bool {
		if ( self::$sender ) {
			return (bool) ( self::$sender )( $to, $subject, $body );
		}
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from    = Config::str( 'AIHAT_HIRE_MAIL_FROM' );
		if ( $from && ! self::smtp_configured() ) {
			$headers[] = 'From: ' . Config::str( 'AIHAT_HIRE_MAIL_FROM_NAME' ) . ' <' . $from . '>';
		}
		self::$active = true;
		try {
			return (bool) wp_mail( $to, $subject, $body, $headers );
		} finally {
			self::$active = false;
		}
	}

	public static function send_code( string $to, string $code ): bool {
		return self::send(
			$to,
			sprintf( Copy::get( 'mail_code_subject' ), $code ),
			sprintf( Copy::get( 'mail_code_body' ), $code )
		);
	}

	public static function send_prompt( string $to, string $crew_member, string $prompt ): bool {
		return self::send(
			$to,
			sprintf( Copy::get( 'mail_prompt_subject' ), $crew_member ),
			sprintf( Copy::get( 'mail_prompt_body' ), $crew_member, $prompt )
		);
	}
}
