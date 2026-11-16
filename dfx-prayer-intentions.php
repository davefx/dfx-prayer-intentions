<?php
/**
 * Plugin Name:       DFX Prayer Intentions – Prayer Requests for Small Groups
 * Plugin URI:        https://github.com/davefx/dfx-prayer-intentions
 * Description:       Collect, moderate and share the prayer intentions of small intercession groups: public request form, lifecycle by type, WhatsApp-ready index and daily digest.
 * Version:           26.11.36
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            David Marín Carreño
 * Author URI:        https://davefx.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       dfx-prayer-intentions
 * Domain Path:       /languages
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DFXPRAY_VERSION', '26.11.36' );
define( 'DFXPRAY_PLUGIN_FILE', __FILE__ );
define( 'DFXPRAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DFXPRAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DFXPRAY_PLUGIN_DIR . 'includes/class-autoloader.php';
DFXPRAY_Autoloader::register();

register_activation_hook( __FILE__, array( DFXPRAY_Installer::get_instance(), 'activate' ) );
register_deactivation_hook( __FILE__, array( DFXPRAY_Installer::get_instance(), 'deactivate' ) );

DFXPRAY_Plugin::get_instance()->run();
