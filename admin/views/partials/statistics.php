<?php
/**
 * Statistics of all groups, for administrators: figures only, one row per
 * group (archived ones too) and the totals. Nothing here reveals a name or a
 * text of a group the administrator does not lead.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_stats   = DFXPRAY_Statistics::get_instance()->for_all_groups();
$dfxpray_led     = DFXPRAY_Permissions::get_instance()->group_ids_for_user();
$dfxpray_columns = array(
	'leaders'     => __( 'Leaders', 'dfx-prayer-intentions' ),
	'members'     => __( 'Members', 'dfx-prayer-intentions' ),
	'pending'     => __( 'Pending', 'dfx-prayer-intentions' ),
	'listed'      => __( 'In the list', 'dfx-prayer-intentions' ),
	/* translators: %d: number of days. */
	'received_30' => sprintf( __( 'Received (last %d days)', 'dfx-prayer-intentions' ), DFXPRAY_Statistics::DAYS ),
	/* translators: %d: number of days. */
	'closed_30'   => sprintf( __( 'Closed (last %d days)', 'dfx-prayer-intentions' ), DFXPRAY_Statistics::DAYS ),
	'answered'    => __( 'Answered (all time)', 'dfx-prayer-intentions' ),
	'total'       => __( 'Total received', 'dfx-prayer-intentions' ),
);
?>
<section class="dfxpray-statistics">
	<h2 class="dfxpray-section-title"><?php esc_html_e( 'Statistics of all groups', 'dfx-prayer-intentions' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Figures only. You open the intentions of the groups you lead.', 'dfx-prayer-intentions' ); ?></p>
	<?php if ( ! $dfxpray_stats['groups'] ) : ?>
		<p><?php esc_html_e( 'There are no groups yet.', 'dfx-prayer-intentions' ); ?></p>
	<?php else : ?>
		<div class="dfxpray-table-scroll">
			<table class="widefat striped dfxpray-statistics-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Group', 'dfx-prayer-intentions' ); ?></th>
						<?php foreach ( $dfxpray_columns as $dfxpray_label ) : ?>
							<th scope="col" class="num"><?php echo esc_html( $dfxpray_label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dfxpray_stats['groups'] as $dfxpray_gid => $dfxpray_row ) : ?>
						<tr data-group="<?php echo esc_attr( (string) $dfxpray_gid ); ?>">
							<th scope="row">
								<?php if ( in_array( $dfxpray_gid, $dfxpray_led, true ) ) : ?>
									<a href="<?php echo esc_url( DFXPRAY_Admin::get_instance()->group_url( $dfxpray_gid ) ); ?>"><?php echo esc_html( $dfxpray_row['name'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $dfxpray_row['name'] ); ?>
								<?php endif; ?>
								<?php if ( 'archived' === $dfxpray_row['status'] ) : ?>
									<span class="dfxpray-status dfxpray-status--closed"><?php esc_html_e( 'Archived', 'dfx-prayer-intentions' ); ?></span>
								<?php endif; ?>
							</th>
							<?php foreach ( array_keys( $dfxpray_columns ) as $dfxpray_key ) : ?>
								<td class="num" data-stat="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( number_format_i18n( $dfxpray_row[ $dfxpray_key ] ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<?php if ( count( $dfxpray_stats['groups'] ) > 1 ) : ?>
					<tfoot>
						<tr class="dfxpray-statistics-totals">
							<th scope="row"><?php esc_html_e( 'All groups', 'dfx-prayer-intentions' ); ?></th>
							<?php foreach ( array_keys( $dfxpray_columns ) as $dfxpray_key ) : ?>
								<td class="num" data-stat-total="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( number_format_i18n( $dfxpray_stats['totals'][ $dfxpray_key ] ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		</div>
	<?php endif; ?>
</section>
