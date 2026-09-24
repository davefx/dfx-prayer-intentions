<?php
/**
 * Class autoloader.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps DFXPRAY_* class names to files, WordPress style:
 * DFXPRAY_Index_Composer lives in includes/class-index-composer.php,
 * DFXPRAY_Channel_Email in includes/channels/class-channel-email.php,
 * the DFXPRAY_Channel interface in includes/channels/interface-channel.php.
 */
final class DFXPRAY_Autoloader {

	/**
	 * Directories searched, relative to the plugin root.
	 *
	 * @var string[]
	 */
	private const DIRECTORIES = array( 'includes/', 'includes/channels/', 'admin/' );

	/**
	 * File name prefixes tried, in order.
	 *
	 * @var string[]
	 */
	private const PREFIXES = array( 'class-', 'interface-', 'trait-' );

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file if the class belongs to the plugin.
	 *
	 * @param string $class_name Class name.
	 */
	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'DFXPRAY_' ) ) {
			return;
		}

		$slug = str_replace( '_', '-', strtolower( substr( $class_name, strlen( 'DFXPRAY_' ) ) ) );

		foreach ( self::DIRECTORIES as $directory ) {
			foreach ( self::PREFIXES as $prefix ) {
				$file = DFXPRAY_PLUGIN_DIR . $directory . $prefix . $slug . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
					return;
				}
			}
		}
	}
}
