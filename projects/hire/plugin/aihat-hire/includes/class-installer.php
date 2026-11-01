<?php
/**
 * Creates and upgrades the plugin's tables, and schedules its cron.
 * All times are stored as Unix timestamps (UTC) so the SQL stays portable.
 */

namespace AIHat\Hire;

final class Installer {

	public const CRON_HOOK     = 'aihat_hire_cron';
	public const CRON_SCHEDULE = 'aihat_hire_5min';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'aihat_hire_' . $name;
	}

	/**
	 * Table definitions in dbDelta format.
	 *
	 * @return array<string,string> name => CREATE TABLE statement
	 */
	public static function schema( string $charset_collate = '' ): array {
		$t = array();
		foreach ( array( 'workshops', 'participants', 'enrollments', 'tasks', 'codes', 'sessions', 'jobs', 'runs', 'prompts', 'pushes', 'limits' ) as $name ) {
			$t[ $name ] = self::table( $name );
		}
		return array(
			'workshops'    => "CREATE TABLE {$t['workshops']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(64) NOT NULL,
  label varchar(191) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'open',
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$charset_collate};",
			'participants' => "CREATE TABLE {$t['participants']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  title varchar(150) NOT NULL DEFAULT '',
  company varchar(150) NOT NULL DEFAULT '',
  industry varchar(100) NOT NULL DEFAULT '',
  industry_suggested varchar(100) NOT NULL DEFAULT '',
  industry_confirmed tinyint(1) NOT NULL DEFAULT 0,
  consent tinyint(1) NOT NULL DEFAULT 0,
  consent_at bigint(20) NOT NULL DEFAULT 0,
  consent_version varchar(32) NOT NULL DEFAULT '',
  ctct_status varchar(16) NOT NULL DEFAULT 'none',
  created_at bigint(20) NOT NULL DEFAULT 0,
  last_login_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY last_login_at (last_login_at)
) {$charset_collate};",
			'enrollments'  => "CREATE TABLE {$t['enrollments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  participant_id bigint(20) unsigned NOT NULL,
  workshop_id bigint(20) unsigned NOT NULL,
  picked_prompt_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY participant_workshop (participant_id,workshop_id),
  KEY workshop_id (workshop_id)
) {$charset_collate};",
			'tasks'        => "CREATE TABLE {$t['tasks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enrollment_id bigint(20) unsigned NOT NULL,
  tab varchar(8) NOT NULL,
  name varchar(150) NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  minutes int(11) NOT NULL DEFAULT 0,
  unit varchar(8) NOT NULL DEFAULT 'minutes',
  frequency decimal(6,2) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY enrollment_id (enrollment_id)
) {$charset_collate};",
			'codes'        => "CREATE TABLE {$t['codes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  code_hash char(64) NOT NULL,
  profile text NULL,
  workshop_id bigint(20) unsigned NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  expires_at bigint(20) NOT NULL DEFAULT 0,
  used_at bigint(20) NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY expires_at (expires_at)
) {$charset_collate};",
			'sessions'     => "CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  participant_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at bigint(20) NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY participant_id (participant_id)
) {$charset_collate};",
			'jobs'         => "CREATE TABLE {$t['jobs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  participant_id bigint(20) unsigned NOT NULL,
  enrollment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(16) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  input longtext NULL,
  result longtext NULL,
  error varchar(255) NOT NULL DEFAULT '',
  attempts int(11) NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  started_at bigint(20) NOT NULL DEFAULT 0,
  finished_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY participant_type (participant_id,type,created_at)
) {$charset_collate};",
			'runs'         => "CREATE TABLE {$t['runs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enrollment_id bigint(20) unsigned NOT NULL,
  cards longtext NULL,
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY enrollment_id (enrollment_id)
) {$charset_collate};",
			'prompts'      => "CREATE TABLE {$t['prompts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  enrollment_id bigint(20) unsigned NOT NULL,
  run_id bigint(20) unsigned NOT NULL,
  card_index int(11) NOT NULL,
  crew_member varchar(100) NOT NULL DEFAULT '',
  build_tag varchar(16) NOT NULL DEFAULT '',
  role_tag varchar(16) NOT NULL DEFAULT '',
  prompt longtext NULL,
  created_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY enrollment_id (enrollment_id)
) {$charset_collate};",
			'pushes'       => "CREATE TABLE {$t['pushes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  participant_id bigint(20) unsigned NOT NULL,
  event varchar(16) NOT NULL,
  payload longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'queued',
  attempts int(11) NOT NULL DEFAULT 0,
  next_attempt_at bigint(20) NOT NULL DEFAULT 0,
  log text NULL,
  created_at bigint(20) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status_next (status,next_attempt_at),
  KEY participant_id (participant_id)
) {$charset_collate};",
			'limits'       => "CREATE TABLE {$t['limits']} (
  lkey varchar(191) NOT NULL,
  window_start bigint(20) NOT NULL DEFAULT 0,
  hits int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (lkey)
) {$charset_collate};",
		);
	}

	public static function activate(): void {
		self::install();
		self::schedule();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		update_option( 'aihat_hire_db_version', DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'aihat_hire_db_version' ) !== DB_VERSION ) {
			self::install();
		}
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
