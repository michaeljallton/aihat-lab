<?php
/**
 * Removes the plugin's tables and options when it is deleted from the Plugins
 * screen. Set AIHAT_HIRE_KEEP_DATA to true in wp-config.php to keep the data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( defined( 'AIHAT_HIRE_KEEP_DATA' ) && AIHAT_HIRE_KEEP_DATA ) {
	return;
}

global $wpdb;
foreach ( array( 'workshops', 'participants', 'enrollments', 'tasks', 'codes', 'sessions', 'jobs', 'runs', 'prompts', 'pushes', 'limits' ) as $aihat_hire_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'aihat_hire_' . $aihat_hire_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
delete_option( 'aihat_hire_db_version' );
delete_option( 'aihat_hire_last_retention' );
wp_clear_scheduled_hook( 'aihat_hire_cron' );
