<?php
/**
 * Read-only text with a Copy button.
 *
 * @var string $id    Element ID.
 * @var string $label Label.
 * @var string $text  Text.
 * @var int    $rows  Rows.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="dfxpray-copy-box">
	<label for="<?php echo esc_attr( $id ); ?>" class="screen-reader-text"><?php echo esc_html( $label ); ?></label>
	<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text code" rows="<?php echo esc_attr( (string) $rows ); ?>" readonly><?php echo esc_textarea( $text ); ?></textarea>
	<p><button type="button" class="button button-primary" data-dfxpray-copy="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'dfx-prayer-intentions' ); ?></button></p>
</div>
