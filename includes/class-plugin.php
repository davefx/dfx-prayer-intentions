<?php
/**
 * Core plugin class.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component to WordPress.
 */
final class DFXPRAY_Plugin {

	use DFXPRAY_Singleton;

	/**
	 * Whether run() already happened.
	 *
	 * @var bool
	 */
	private bool $running = false;

	/**
	 * Register hooks for every component. Safe to call more than once.
	 */
	public function run(): void {
		if ( $this->running ) {
			return;
		}
		$this->running = true;

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Hour alerts need a five-minute tick.
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'admin_init', array( DFXPRAY_Installer::get_instance(), 'maybe_upgrade' ) );

		DFXPRAY_Scheduler::get_instance()->register_hooks();
		DFXPRAY_Notifier::get_instance()->register_hooks();
		DFXPRAY_Form::get_instance()->register_hooks();
		DFXPRAY_Public_Page::get_instance()->register_hooks();
		DFXPRAY_Privacy::get_instance()->register_hooks();
		DFXPRAY_Admin::get_instance()->register_hooks();
		DFXPRAY_App_API::get_instance()->register_hooks();
		DFXPRAY_REST::get_instance()->register_hooks();
		DFXPRAY_Service_Accounts::get_instance()->register_hooks();
		DFXPRAY_App::get_instance()->register_hooks();
		DFXPRAY_Invitations::get_instance()->register_hooks();
	}

	/**
	 * Add the five-minute cron interval used by the tick.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedule( $schedules ) {
		$schedules['dfxpray_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'dfx-prayer-intentions' ),
		);

		return $schedules;
	}

	/**
	 * Tell WordPress where the bundled translations live. They are loaded
	 * just in time, after any language pack in wp-content/languages/plugins,
	 * and follow switch_to_locale().
	 *
	 * The absolute path is registered directly because load_plugin_textdomain()
	 * derives it from plugin_basename(), which breaks when the plugin is
	 * loaded from outside the plugins directory (symlinks, test suites).
	 */
	public function load_textdomain(): void {
		global $wp_textdomain_registry;

		$wp_textdomain_registry->set_custom_path( 'dfx-prayer-intentions', DFXPRAY_PLUGIN_DIR . 'languages' );
	}
}
