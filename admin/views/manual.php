<?php
/**
 * The user manual, in the user's language (or the one a link asks for),
 * with its screenshots.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The other language, when a link of the manual asks for it; read only.
$dfxpray_language = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which manual to show.
?>
<div class="dfxpray-manual">
	<?php echo DFXPRAY_Manual::get_instance()->page( get_user_locale(), $dfxpray_language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped and filtered with wp_kses by DFXPRAY_Manual. ?>
</div>
