<?php
/**
 * Daily digest: last generated (or a preview), both variants, and recent hour alerts.
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
$dfxpray_digest    = $dfxpray_is_today ? $dfxpray_stored : DFXPRAY_Digest_Composer::get_instance()->compose( $group_id );
$dfxpray_time      = (string) DFXPRAY_Settings::get_instance()->get( 'digest_time', $group_id );
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

<?php $dfxpray_admin->form_open( 'send_digest_now', array( 'group_id' => $group_id ) ); ?>
	<p><button type="submit" class="button"><?php esc_html_e( 'Generate and send now', 'dfx-prayer-intentions' ); ?></button></p>
</form>

<div class="dfxpray-columns dfxpray-columns--even">
	<div>
		<h2><?php esc_html_e( 'Shareable version', 'dfx-prayer-intentions' ); ?></h2>
		<?php
		if ( '' === $dfxpray_digest['shareable'] ) {
			echo '<p class="dfxpray-empty">' . esc_html__( 'Nothing to share today.', 'dfx-prayer-intentions' ) . '</p>';
		} else {
			$dfxpray_admin->view(
				'partials/copy-box',
				array(
					'id'    => 'dfxpray-digest-shareable',
					'label' => __( 'Shareable version', 'dfx-prayer-intentions' ),
					'text'  => $dfxpray_digest['shareable'],
					'rows'  => 16,
				)
			);
		}
		?>
	</div>
	<div>
		<h2><?php esc_html_e( 'Leaders’ version', 'dfx-prayer-intentions' ); ?></h2>
		<?php
		if ( '' === $dfxpray_digest['leaders'] ) {
			echo '<p class="dfxpray-empty">' . esc_html__( 'Nothing for the leaders today.', 'dfx-prayer-intentions' ) . '</p>';
		} else {
			$dfxpray_admin->view(
				'partials/copy-box',
				array(
					'id'    => 'dfxpray-digest-leaders',
					'label' => __( 'Leaders’ version', 'dfx-prayer-intentions' ),
					'text'  => $dfxpray_digest['leaders'],
					'rows'  => 16,
				)
			);
		}
		?>
	</div>
</div>

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
