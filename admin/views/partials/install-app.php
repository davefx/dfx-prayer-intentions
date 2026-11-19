<?php
/**
 * The link to install the leaders' app, on every screen of the panel: the
 * address, a Copy button, and how to install it on Android and iPhone.
 *
 * @var bool $open Whether the instructions start open.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_app_url = DFXPRAY_App::get_instance()->url();
?>
<div class="dfxpray-install">
	<p>
		<strong><?php esc_html_e( 'Install the app on your phone:', 'dfx-prayer-intentions' ); ?></strong>
		<a class="dfxpray-install-url" href="<?php echo esc_url( $dfxpray_app_url ); ?>"><?php echo esc_html( $dfxpray_app_url ); ?></a>
		<button type="button" class="button button-small" data-dfxpray-copy-text="<?php echo esc_attr( $dfxpray_app_url ); ?>"><?php esc_html_e( 'Copy link', 'dfx-prayer-intentions' ); ?></button>
	</p>
	<details class="dfxpray-install-how"<?php echo empty( $open ) ? '' : ' open'; ?>>
		<summary><?php esc_html_e( 'How to install it', 'dfx-prayer-intentions' ); ?></summary>
		<ul>
			<li><?php esc_html_e( 'Android (Chrome): open the link and tap “Install” (or the ⋮ menu › Install app).', 'dfx-prayer-intentions' ); ?></li>
			<li><?php esc_html_e( 'iPhone (Safari): open the link, tap Share › Add to Home Screen.', 'dfx-prayer-intentions' ); ?></li>
		</ul>
	</details>
</div>
