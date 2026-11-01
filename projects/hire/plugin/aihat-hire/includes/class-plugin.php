<?php
/**
 * Wires the plugin into WordPress.
 */

namespace AIHat\Hire;

final class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( Installer::CRON_HOOK, array( self::class, 'cron' ) );
		add_action( Installer::CRON_HOOK . '_now', array( self::class, 'cron' ) );
		add_action( 'phpmailer_init', array( Mailer::class, 'configure' ) );
		Ajax::register();
		Shortcode::register();
		if ( is_admin() ) {
			Admin::register();
		}
		Installer::schedule();
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules[ Installer::CRON_SCHEDULE ] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (Hire Your First Crew Member)',
		);
		return $schedules;
	}

	/**
	 * Five-minute housekeeping: Constant Contact pushes, crashed jobs,
	 * expired codes and sessions, and once a day the retention purge.
	 */
	public static function cron(): void {
		$now = time();
		Pushes::process( $now );
		Store::reap_stale_jobs( $now - Worker::stale_after(), $now );
		Store::purge_expired( $now );
		if ( (int) get_option( 'aihat_hire_last_retention', 0 ) < $now - DAY_IN_SECONDS ) {
			self::retention( $now );
			update_option( 'aihat_hire_last_retention', $now, false );
		}
	}

	public static function retention( int $now ): int {
		$cutoff = $now - Config::int( 'AIHAT_HIRE_RETENTION_DAYS' ) * DAY_IN_SECONDS;
		$ids    = Store::stale_participant_ids( $cutoff );
		foreach ( $ids as $id ) {
			Store::delete_participant( $id );
		}
		return count( $ids );
	}
}
