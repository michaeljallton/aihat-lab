<?php
/**
 * Minimal Messages API client over raw HTTP (wp_remote_post in production).
 * The transport is injectable so tests never touch the network.
 *
 * Every call asks for structured JSON output against a schema, and opts into
 * Anthropic's server-side refusal fallback (AIHAT_HIRE_FALLBACKS).
 */

namespace AIHat\Hire;

final class Anthropic {

	public const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION     = '2026-10-21';
	public const FALLBACK_BETA   = 'server-side-fallback-2026-07-01';

	/** @var callable(string $url, array $args): array{code:int, body:string, headers:array, error:string} */
	private $transport;

	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? array( self::class, 'wp_transport' );
	}

	/**
	 * Production transport.
	 */
	public static function wp_transport( string $url, array $args ): array {
		$res = wp_remote_post( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'code'    => 0,
				'body'    => '',
				'headers' => array(),
				'error'   => $res->get_error_message(),
			);
		}
		$headers = wp_remote_retrieve_headers( $res );
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
			'error'   => '',
		);
	}

	public static function configured(): bool {
		return '' !== Config::str( 'AIHAT_HIRE_ANTHROPIC_KEY' );
	}

	/**
	 * Builds the request body.
	 */
	public static function body( string $system, string $user, array $schema, int $max_tokens, string $effort ): array {
		$body = array(
			'model'         => Config::str( 'AIHAT_HIRE_MODEL' ),
			'max_tokens'    => $max_tokens,
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'output_config' => array(
				'effort' => $effort,
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			),
		);
		if ( Config::bool( 'AIHAT_HIRE_FALLBACKS' ) ) {
			$body['fallbacks'] = 'default';
		}
		return $body;
	}

	/**
	 * Sends one request and returns the decoded JSON object the model wrote.
	 *
	 * @return array{ok:bool, data?:mixed, error?:string, retryable?:bool, retry_after?:int, usage?:array}
	 */
	public function json( string $system, string $user, array $schema, int $max_tokens = 8000, ?string $effort = null ): array {
		if ( ! self::configured() ) {
			return array(
				'ok'        => false,
				'error'     => 'not_configured',
				'retryable' => false,
			);
		}
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => Config::str( 'AIHAT_HIRE_ANTHROPIC_KEY' ),
			'anthropic-version' => self::API_VERSION,
		);
		if ( Config::bool( 'AIHAT_HIRE_FALLBACKS' ) ) {
			$headers['anthropic-beta'] = self::FALLBACK_BETA;
		}
		$body = self::body( $system, $user, $schema, $max_tokens, $effort ?? Config::str( 'AIHAT_HIRE_EFFORT' ) );
		$res  = ( $this->transport )(
			self::ENDPOINT,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode_safe( $body ),
				'timeout' => Config::int( 'AIHAT_HIRE_API_TIMEOUT' ),
			)
		);
		return self::parse( $res );
	}

	/**
	 * Interprets a transport result.
	 */
	public static function parse( array $res ): array {
		$code = (int) ( $res['code'] ?? 0 );
		if ( 0 === $code ) {
			return array(
				'ok'        => false,
				'error'     => 'network: ' . ( $res['error'] ?? 'no response' ),
				'retryable' => true,
			);
		}
		$decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
		if ( 200 !== $code ) {
			$type = is_array( $decoded ) ? ( $decoded['error']['type'] ?? '' ) : '';
			$out  = array(
				'ok'        => false,
				'error'     => "http {$code}" . ( $type ? " {$type}" : '' ),
				'retryable' => 429 === $code || 408 === $code || 409 === $code || $code >= 500,
			);
			$headers = array_change_key_case( (array) ( $res['headers'] ?? array() ), CASE_LOWER );
			if ( isset( $headers['retry-after'] ) ) {
				$out['retry_after'] = (int) ( is_array( $headers['retry-after'] ) ? reset( $headers['retry-after'] ) : $headers['retry-after'] );
			}
			return $out;
		}
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'        => false,
				'error'     => 'unreadable response',
				'retryable' => true,
			);
		}
		$stop = $decoded['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return array(
				'ok'        => false,
				'error'     => 'refusal',
				'retryable' => false,
			);
		}
		if ( 'max_tokens' === $stop ) {
			return array(
				'ok'        => false,
				'error'     => 'max_tokens',
				'retryable' => true,
			);
		}
		$text = '';
		foreach ( (array) ( $decoded['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		$data = json_decode( $text, true );
		if ( null === $data && 'null' !== trim( $text ) ) {
			return array(
				'ok'        => false,
				'error'     => 'json_parse',
				'retryable' => true,
			);
		}
		return array(
			'ok'    => true,
			'data'  => $data,
			'usage' => (array) ( $decoded['usage'] ?? array() ),
		);
	}
}
