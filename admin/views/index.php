<?php
/**
 * Index preview with a Copy button.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_composer = DFXPRAY_Index_Composer::get_instance();
$dfxpray_text     = $dfxpray_composer->compose( $group_id );
$dfxpray_length   = mb_strlen( $dfxpray_text );
?>
<p class="description"><?php esc_html_e( 'Active and in-review intentions, formatted for WhatsApp. Copy it and paste it into the group.', 'dfx-prayer-intentions' ); ?></p>
<p class="dfxpray-app-link">
	<?php
	printf(
		/* translators: %s: link to the leaders' app. */
		esc_html__( 'On your phone: %s (you can add it to the home screen).', 'dfx-prayer-intentions' ),
		'<a href="' . esc_url( DFXPRAY_App::get_instance()->url() ) . '">' . esc_html__( 'open the leaders’ app', 'dfx-prayer-intentions' ) . '</a>'
	);
	?>
</p>

<?php if ( $dfxpray_composer->is_too_long( $dfxpray_text ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: 1: length, 2: limit. */
				esc_html__( 'The index is %1$s characters long, over %2$s: it may be too long to read comfortably in one message.', 'dfx-prayer-intentions' ),
				esc_html( number_format_i18n( $dfxpray_length ) ),
				esc_html( number_format_i18n( DFXPRAY_Settings::INDEX_LENGTH_WARNING ) )
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php
DFXPRAY_Admin::get_instance()->view(
	'partials/copy-box',
	array(
		'id'    => 'dfxpray-index-text',
		'label' => __( 'Index', 'dfx-prayer-intentions' ),
		'text'  => $dfxpray_text,
		'rows'  => 20,
	)
);
?>
<p class="dfxpray-meta">
	<?php
	/* translators: %s: number of characters. */
	printf( esc_html__( '%s characters', 'dfx-prayer-intentions' ), esc_html( number_format_i18n( $dfxpray_length ) ) );
	?>
</p>

<h2><?php esc_html_e( 'Monthly list', 'dfx-prayer-intentions' ); ?></h2>
<p class="description"><?php esc_html_e( 'Every active intention, by topic, to read at the monthly meeting.', 'dfx-prayer-intentions' ); ?></p>
<?php
DFXPRAY_Admin::get_instance()->view(
	'partials/copy-box',
	array(
		'id'    => 'dfxpray-monthly-text',
		'label' => __( 'Monthly list', 'dfx-prayer-intentions' ),
		'text'  => $dfxpray_composer->compose( $group_id, 'monthly' ),
		'rows'  => 20,
	)
);
?>
