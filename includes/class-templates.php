<?php
/**
 * Template loader.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the plugin templates. A theme can override any of them by copying
 * it to <theme>/dfx-prayer-intentions/<name>.
 */
final class DFXPRAY_Templates {

	use DFXPRAY_Singleton;

	/**
	 * Path of a template, theme first.
	 *
	 * @param string $name Template file name, e.g. "index.php".
	 */
	public function locate( string $name ): string {
		$path = locate_template( 'dfx-prayer-intentions/' . $name );

		if ( ! $path ) {
			$path = DFXPRAY_PLUGIN_DIR . 'templates/' . $name;
		}

		/**
		 * Filters the path of a plugin template.
		 *
		 * @param string $path Path.
		 * @param string $name Template name.
		 */
		return apply_filters( 'dfxpray_template_path', $path, $name );
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string $name Template file name.
	 * @param array  $vars Variables available in the template.
	 */
	public function render( string $name, array $vars = array() ): string {
		$path = $this->locate( $name );

		ob_start();
		( static function ( string $dfxpray_template, array $dfxpray_vars ) {
			extract( $dfxpray_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $dfxpray_template;
		} )( $path, $vars );

		return trim( (string) ob_get_clean() );
	}
}
