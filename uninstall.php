<?php
/**
 * Uninstall: removes every table, option, role and capability of the plugin,
 * unless the administrator chose to keep the data in the global settings.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'DFXPRAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once DFXPRAY_PLUGIN_DIR . 'includes/class-autoloader.php';
DFXPRAY_Autoloader::register();

DFXPRAY_Installer::get_instance()->uninstall();
