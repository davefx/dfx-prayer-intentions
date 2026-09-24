<?php
/**
 * Log entries.
 *
 * @var array[] $entries Log entries.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $entries ) {
	echo '<p class="dfxpray-empty">' . esc_html__( 'Nothing recorded yet.', 'dfx-prayer-intentions' ) . '</p>';
	return;
}
?>
<table class="widefat striped dfxpray-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'When', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Who', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'What', 'dfx-prayer-intentions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $entries as $dfxpray_entry ) : ?>
			<?php $dfxpray_user = $dfxpray_entry['user_id'] ? get_userdata( $dfxpray_entry['user_id'] ) : null; ?>
			<tr class="dfxpray-log--<?php echo esc_attr( $dfxpray_entry['action'] ); ?>">
				<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_entry['created_at'] ) ); ?></td>
				<td><?php echo esc_html( $dfxpray_user ? $dfxpray_user->display_name : __( 'System', 'dfx-prayer-intentions' ) ); ?></td>
				<td><?php echo esc_html( DFXPRAY_Admin::get_instance()->describe_log_entry( $dfxpray_entry ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
