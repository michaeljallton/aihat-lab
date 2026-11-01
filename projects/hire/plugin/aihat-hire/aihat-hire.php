<?php
/**
 * Plugin Name:       Hire Your First Crew Member
 * Description:       Workshop tool: participants list recurring tasks, get five recommended crew members, and take away a PRICE interview prompt. Shortcode [aihat_hire].
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            The AI Hat
 * License:           Proprietary
 * Text Domain:       aihat-hire
 */

namespace AIHat\Hire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.1.0';
const DB_VERSION  = '1';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
