<?php
/**
 * Singleton trait shared by every plugin service class.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides get_instance() and forbids any other way of building the object.
 */
trait DFXPRAY_Singleton {

	/**
	 * The single instance, one per using class.
	 *
	 * @var static|null
	 */
	private static $instance = null;

	/**
	 * Return the single instance of the class.
	 *
	 * @return static
	 */
	public static function get_instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Only get_instance() builds the object.
	 */
	protected function __construct() {
		$this->init();
	}

	/**
	 * Hook for set-up work in the using class.
	 */
	protected function init(): void {
	}

	/**
	 * Cloning is not allowed.
	 */
	private function __clone() {
	}

	/**
	 * Unserializing is not allowed.
	 *
	 * @throws LogicException Always.
	 */
	public function __wakeup() {
		throw new LogicException( 'Cannot unserialize a singleton.' );
	}
}
