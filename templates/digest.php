<?php
/**
 * Daily prayer digest, WhatsApp syntax (plain text). Used for both variants.
 *
 * Override it by copying this file to <theme>/dfx-prayer-intentions/digest.php.
 *
 * Available variables:
 *
 * @var string $header        Header, with {date} already replaced.
 * @var string $footer        Footer.
 * @var array  $sections      Sections: title, emoji, lines (strings, no bullet).
 * @var string $empty_message Shown when there are no sections.
 * @var string $variant       'leaders' or 'shareable'.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_blocks = array();

if ( '' !== $header ) {
	$dfxpray_blocks[] = $header;
}

foreach ( $sections as $dfxpray_section ) {
	$dfxpray_title = ( '' !== $dfxpray_section['emoji'] ? $dfxpray_section['emoji'] . ' ' : '' ) . '*' . $dfxpray_section['title'] . '*';
	$dfxpray_lines = array_map( static fn( $line ) => '• ' . $line, $dfxpray_section['lines'] );

	$dfxpray_blocks[] = implode( "\n", array_merge( array( $dfxpray_title ), $dfxpray_lines ) );
}

if ( ! $sections ) {
	$dfxpray_blocks[] = $empty_message;
}

if ( '' !== $footer ) {
	$dfxpray_blocks[] = $footer;
}

echo implode( "\n\n", $dfxpray_blocks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text for WhatsApp, escaped by the caller when shown in HTML.
