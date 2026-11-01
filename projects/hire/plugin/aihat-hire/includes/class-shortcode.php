<?php
/**
 * [aihat_hire] renders the app shell. All participant data loads by AJAX, so
 * the page HTML holds nothing personal. The page also tells caches and search
 * engines to stay away.
 */

namespace AIHat\Hire;

final class Shortcode {

	public const TAG = 'aihat_hire';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'page_headers' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	private static function is_tool_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, self::TAG );
	}

	/**
	 * No caching and no indexing for the tool page, whatever else is set.
	 */
	public static function page_headers(): void {
		if ( ! self::is_tool_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Respected by most WordPress cache plugins.
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	public static function robots( array $robots ): array {
		if ( self::is_tool_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
		}
		return $robots;
	}

	public static function render(): string {
		$base = plugins_url( '', PLUGIN_FILE );
		$dir  = dirname( PLUGIN_FILE );
		if ( Config::bool( 'AIHAT_HIRE_LOAD_FONTS' ) ) {
			wp_enqueue_style( 'aihat-hire-fonts', 'https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Michroma&family=Raleway:wght@400;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'aihat-hire', $base . '/assets/app.css', array(), (string) filemtime( $dir . '/assets/app.css' ) );
		wp_enqueue_script( 'aihat-hire', $base . '/assets/app.js', array(), (string) filemtime( $dir . '/assets/app.js' ), true );

		$privacy = Config::str( 'AIHAT_HIRE_PRIVACY_URL' );
		if ( '' === $privacy && function_exists( 'get_privacy_policy_url' ) ) {
			$privacy = get_privacy_policy_url();
		}
		$event = isset( $_GET['event'] ) ? Api::normalize_event( sanitize_text_field( wp_unslash( $_GET['event'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'aihat-hire',
			'AIHAT_HIRE',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'event'   => $event,
				'privacy' => $privacy,
				'copy'    => Copy::all(),
			)
		);

		return '<div class="aihat-hire" id="aihat-hire-app" aria-live="polite"><noscript>This tool needs JavaScript.</noscript></div>';
	}
}
