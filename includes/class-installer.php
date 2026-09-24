<?php
/**
 * Installer: tables, role, capabilities and cron events.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation, deactivation and schema upgrades.
 */
final class DFXPRAY_Installer {

	use DFXPRAY_Singleton;

	/**
	 * Schema version. Bump it whenever get_schema() changes.
	 */
	public const DB_VERSION = '2';

	/**
	 * The capability that gives access to the leaders' panel.
	 */
	public const CAPABILITY = 'dfxpray_manage';

	/**
	 * The leader role.
	 */
	public const ROLE = 'dfxpray_leader';

	/**
	 * Run on plugin activation.
	 */
	public function activate(): void {
		$this->create_tables();
		$this->add_role_and_capabilities();
		$this->schedule_events();
		update_option( 'dfxpray_flush_rewrite', 1 );
	}

	/**
	 * Run on plugin deactivation. Data is kept; only uninstall removes it.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'dfxpray_tick' );
		wp_clear_scheduled_hook( 'dfxpray_daily' );
	}

	/**
	 * Run on uninstall: remove tables, options, role and capabilities, unless
	 * the administrator chose to keep the data.
	 */
	public function uninstall(): void {
		global $wpdb;

		if ( ! DFXPRAY_Settings::get_instance()->get( 'delete_data_on_uninstall' ) ) {
			return;
		}

		$this->deactivate();

		foreach ( $this->get_table_names() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB
		}

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dfxpray\\_%' OR option_name LIKE '\\_transient\\_dfxpray\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dfxpray\\_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();

		remove_role( self::ROLE );
		foreach ( wp_roles()->roles as $name => $role ) {
			$role_object = get_role( $name );
			if ( $role_object && $role_object->has_cap( self::CAPABILITY ) ) {
				$role_object->remove_cap( self::CAPABILITY );
			}
		}

		DFXPRAY_Settings::get_instance()->flush_cache();
	}

	/**
	 * Upgrade the schema when the stored version is older than the code.
	 */
	public function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( 'dfxpray_db_version' ) ) {
			$this->activate();
		}
	}

	/**
	 * Create or update the plugin tables.
	 */
	public function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->get_schema() );
		update_option( 'dfxpray_db_version', self::DB_VERSION, false );
	}

	/**
	 * Names of every plugin table, keyed by short name.
	 *
	 * @return array<string, string>
	 */
	public function get_table_names(): array {
		global $wpdb;

		$names = array();
		foreach ( array( 'groups', 'group_leaders', 'intentions', 'updates', 'comments', 'log' ) as $short ) {
			$names[ $short ] = $wpdb->prefix . 'dfxpray_' . $short;
		}

		return $names;
	}

	/**
	 * The CREATE TABLE statements, dbDelta style.
	 */
	private function get_schema(): string {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$t       = $this->get_table_names();

		return "CREATE TABLE {$t['groups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  description text NULL,
  slug varchar(191) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  settings longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$collate};
CREATE TABLE {$t['group_leaders']} (
  group_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (group_id,user_id),
  KEY user_id (user_id)
) {$collate};
CREATE TABLE {$t['intentions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id bigint(20) unsigned NOT NULL,
  requester_name varchar(191) NOT NULL,
  requester_contact varchar(191) NOT NULL DEFAULT '',
  beneficiary_name varchar(191) NOT NULL,
  beneficiary_is_requester tinyint(1) NOT NULL DEFAULT 0,
  relationship varchar(100) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  summary varchar(100) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL,
  reference_date date NULL,
  reference_time time NULL,
  key_dates longtext NULL,
  anniversary tinyint(1) NOT NULL DEFAULT 0,
  discretion varchar(20) NOT NULL DEFAULT 'first_name',
  requester_display_name varchar(191) NOT NULL DEFAULT '',
  beneficiary_display_name varchar(191) NOT NULL DEFAULT '',
  priority varchar(10) NOT NULL DEFAULT 'normal',
  status varchar(20) NOT NULL DEFAULT 'pending',
  close_reason varchar(20) NOT NULL DEFAULT '',
  next_review date NULL,
  ack_sent tinyint(1) NOT NULL DEFAULT 0,
  source varchar(10) NOT NULL DEFAULT 'form',
  linked_id bigint(20) unsigned NULL,
  alerts_sent text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  activated_at datetime NULL,
  closed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY group_status (group_id,status),
  KEY requester_contact (requester_contact),
  KEY closed_at (closed_at)
) {$collate};
CREATE TABLE {$t['updates']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  intention_id bigint(20) unsigned NOT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  body text NOT NULL,
  is_news tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY intention_id (intention_id)
) {$collate};
CREATE TABLE {$t['comments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  intention_id bigint(20) unsigned NOT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT '',
  body text NOT NULL,
  created_at datetime NOT NULL,
  edited_by bigint(20) unsigned NULL,
  edited_at datetime NULL,
  PRIMARY KEY  (id),
  KEY intention_id (intention_id)
) {$collate};
CREATE TABLE {$t['log']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id bigint(20) unsigned NULL,
  intention_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(50) NOT NULL,
  details text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY group_id (group_id),
  KEY intention_id (intention_id),
  KEY action (action)
) {$collate};";
	}

	/**
	 * Create the leader role and grant the capability to administrators.
	 */
	private function add_role_and_capabilities(): void {
		// Only the panel, plus "read" so leaders can reach their own profile
		// (and change their password). The dashboard sends them to the panel.
		// Translation happens at display time; the stored name stays English.
		if ( null === get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'Prayer leader',
				array(
					self::CAPABILITY => true,
					'read'           => true,
				)
			);
		}

		$leader = get_role( self::ROLE );
		if ( $leader && ! $leader->has_cap( 'read' ) ) {
			$leader->add_cap( 'read' );
		}

		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAPABILITY ) ) {
			$admin->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Schedule the recurring cron events.
	 */
	private function schedule_events(): void {
		if ( ! wp_next_scheduled( 'dfxpray_tick' ) ) {
			wp_schedule_event( time(), 'dfxpray_five_minutes', 'dfxpray_tick' );
		}

		if ( ! wp_next_scheduled( 'dfxpray_daily' ) ) {
			wp_schedule_event( time(), 'daily', 'dfxpray_daily' );
		}
	}
}
