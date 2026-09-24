<?php
/**
 * Activity log of the group, with a filter for failed deliveries.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
$dfxpray_failed = ! empty( $_GET['failed'] );
$dfxpray_admin  = DFXPRAY_Admin::get_instance();
?>
<p>
	<a href="<?php echo esc_url( $dfxpray_admin->url( 'dfxpray-log' ) ); ?>" class="<?php echo $dfxpray_failed ? '' : 'current'; ?>"><?php esc_html_e( 'Everything', 'dfx-prayer-intentions' ); ?></a> |
	<a href="<?php echo esc_url( $dfxpray_admin->url( 'dfxpray-log', array( 'failed' => 1 ) ) ); ?>" class="<?php echo $dfxpray_failed ? 'current' : ''; ?>"><?php esc_html_e( 'Failed deliveries', 'dfx-prayer-intentions' ); ?></a>
</p>
<?php
$dfxpray_admin->view(
	'partials/log-table',
	array( 'entries' => DFXPRAY_Log::get_instance()->for_group( $group_id, $dfxpray_failed ? array( 'channel_failed' ) : array() ) )
);
