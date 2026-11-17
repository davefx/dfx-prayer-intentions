<?php
/**
 * Daily digest: both variants as they are now, when it was last sent, and
 * recent hour alerts.
 *
 * The shareable version is the daily message of the leaders' app, always
 * current like the app; the leaders' one is the same message with who asks
 * and a private block.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin     = DFXPRAY_Admin::get_instance();
$dfxpray_scheduler = DFXPRAY_Scheduler::get_instance();
$dfxpray_stored    = $dfxpray_scheduler->get_stored_digest( $group_id );
$dfxpray_is_today  = $dfxpray_stored && DFXPRAY_Clock::get_instance()->today() === $dfxpray_stored['date'];
$dfxpray_digest    = DFXPRAY_Digest_Composer::get_instance()->compose( $group_id );
$dfxpray_time      = (string) DFXPRAY_Settings::get_instance()->get( 'digest_time', $group_id );
$dfxpray_length    = mb_strlen( $dfxpray_digest['shareable'] );
?>
<p class="description">
	<?php
	if ( $dfxpray_is_today ) {
		/* translators: %s: date and time. */
		printf( esc_html__( 'Generated and sent on %s.', 'dfx-prayer-intentions' ), esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_stored['generated_at'] ) ) );
	} else {
		/* translators: %s: time of day. */
		printf( esc_html__( 'Preview: today’s digest has not been sent yet. It goes out every day at %s.', 'dfx-prayer-intentions' ), esc_html( $dfxpray_time ) );
	}
	?>
</p>
<p class="dfxpray-app-link">
	<?php
	printf(
		/* translators: %s: link to the leaders' app. */
		esc_html__( 'On your phone: %s (you can add it to the home screen).', 'dfx-prayer-intentions' ),
		'<a href="' . esc_url( DFXPRAY_App::get_instance()->url() ) . '">' . esc_html__( 'open the leaders’ app', 'dfx-prayer-intentions' ) . '</a>'
	);
	?>
</p>

<?php $dfxpray_admin->form_open( 'send_digest_now', array( 'group_id' => $group_id ) ); ?>
	<p><button type="submit" class="button"><?php esc_html_e( 'Generate and send now', 'dfx-prayer-intentions' ); ?></button></p>
</form>

<?php if ( DFXPRAY_Index_Composer::get_instance()->is_too_long( $dfxpray_digest['shareable'] ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: 1: length, 2: limit. */
				esc_html__( 'The message is %1$s characters long, over %2$s: it may be too long to read comfortably in one message.', 'dfx-prayer-intentions' ),
				esc_html( number_format_i18n( $dfxpray_length ) ),
				esc_html( number_format_i18n( DFXPRAY_Settings::INDEX_LENGTH_WARNING ) )
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php
$dfxpray_admin->view(
	'partials/digest-variants',
	array(
		'prefix' => 'dfxpray-digest',
		'digest' => $dfxpray_digest,
	)
);
?>
<p class="dfxpray-meta">
	<?php
	/* translators: %s: number of characters. */
	printf( esc_html__( '%s characters', 'dfx-prayer-intentions' ), esc_html( number_format_i18n( $dfxpray_length ) ) );
	?>
</p>

<?php $dfxpray_alerts = $dfxpray_scheduler->get_recent_alerts( $group_id ); ?>
<?php if ( $dfxpray_alerts ) : ?>
	<h2><?php esc_html_e( 'Recent hour alerts', 'dfx-prayer-intentions' ); ?></h2>
	<?php foreach ( $dfxpray_alerts as $dfxpray_i => $dfxpray_alert ) : ?>
		<p class="dfxpray-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_alert['time'] ) ); ?></p>
		<?php
		$dfxpray_admin->view(
			'partials/copy-box',
			array(
				'id'    => 'dfxpray-alert-' . $dfxpray_i,
				'label' => __( 'Hour alert', 'dfx-prayer-intentions' ),
				'text'  => $dfxpray_alert['text'],
				'rows'  => 2,
			)
		);
		?>
	<?php endforeach; ?>
<?php endif; ?>
