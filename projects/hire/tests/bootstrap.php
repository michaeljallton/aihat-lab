<?php
/**
 * Test bootstrap: loads the plugin classes without WordPress, with a small
 * $wpdb stand-in on in-memory SQLite and stubs for the few WordPress
 * functions the code under test calls. The Anthropic API, SMTP and the
 * webhook are always mocked.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );

	require __DIR__ . '/FakeWpdb.php';

	$GLOBALS['wpdb'] = new FakeWpdb();

	function sanitize_text_field( $s ) {
		$s = strip_tags( (string) $s );
		$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
		return trim( $s );
	}
	function wp_next_scheduled( $hook ) {
		return false;
	}
	function wp_schedule_single_event( $time, $hook ) {
		return true;
	}
}

namespace AIHat\Hire {
	const VERSION     = 'test';
	const DB_VERSION  = '1';
	const PLUGIN_FILE = __DIR__ . '/../plugin/aihat-hire/aihat-hire.php';

	require __DIR__ . '/../plugin/aihat-hire/includes/autoload.php';
	require __DIR__ . '/TestCase.php';
}
