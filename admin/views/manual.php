<?php
/**
 * The user manual, in the user's language, with its screenshots.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="dfxpray-manual">
	<?php echo DFXPRAY_Manual::get_instance()->page( get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped and filtered with wp_kses by DFXPRAY_Manual. ?>
</div>
