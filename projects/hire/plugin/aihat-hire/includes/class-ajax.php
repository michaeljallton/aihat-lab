<?php
/**
 * admin-ajax.php endpoints. Every call is a POST carrying the aihat_hire nonce.
 * Responses are never cached.
 */

namespace AIHat\Hire;

final class Ajax {

	public const NONCE = 'aihat_hire';

	/** action suffix => handler */
	private const ROUTES = array(
		'state'            => 'state',
		'request_code'     => 'request_code',
		'verify_code'      => 'verify_code',
		'confirm_industry' => 'confirm_industry',
		'save_task'        => 'save_task',
		'delete_task'      => 'delete_task',
		'copy_tasks'       => 'copy_tasks',
		'generate'         => 'generate',
		'pick'             => 'pick',
		'poll'             => 'poll',
		'email_prompt'     => 'email_prompt',
		'sign_out'         => 'sign_out',
		'delete_account'   => 'delete_account',
	);

	public static function register(): void {
		foreach ( array_keys( self::ROUTES ) as $route ) {
			$cb = static function () use ( $route ) {
				self::handle( $route );
			};
			add_action( 'wp_ajax_aihat_hire_' . $route, $cb );
			add_action( 'wp_ajax_nopriv_aihat_hire_' . $route, $cb );
		}
	}

	private static function handle( string $route ): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'method' ), 405 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'nonce' ) );
		}
		$in  = wp_unslash( $_POST ); // Each handler sanitizes the fields it uses.
		$now = time();
		$method = self::ROUTES[ $route ];
		switch ( $method ) {
			case 'sign_out':
				$out = Api::sign_out();
				break;
			case 'delete_account':
				$out = Api::delete_account( $now );
				break;
			default:
				$out = Api::$method( (array) $in, $now );
		}
		wp_send_json( $out );
	}
}
