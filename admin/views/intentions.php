<?php
/**
 * Intentions: list with filters, or the detail screen.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
$dfxpray_view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );

if ( in_array( $dfxpray_view, array( 'edit', 'new' ), true ) ) {
	DFXPRAY_Admin::get_instance()->view(
		'intention-edit',
		array(
			'group_id' => $group_id,
			'id'       => 'edit' === $dfxpray_view ? absint( $_GET['id'] ?? 0 ) : 0,
		)
	);
	return;
}

$dfxpray_admin     = DFXPRAY_Admin::get_instance();
$dfxpray_lifecycle = DFXPRAY_Lifecycle::get_instance();
$dfxpray_filters   = array(
	'status'    => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ),
	'type'      => sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ),
	'priority'  => sanitize_key( wp_unslash( $_GET['priority'] ?? '' ) ),
	'date_from' => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
	'date_to'   => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
	'search'    => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
);
// phpcs:enable

$dfxpray_rows = DFXPRAY_Intentions::get_instance()->query( $group_id, array_filter( $dfxpray_filters ) + array( 'urgent_first' => true ) );
?>
<p><a class="page-title-action" href="<?php echo esc_url( $dfxpray_admin->group_url( $group_id, 'intentions', array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add intention', 'dfx-prayer-intentions' ); ?></a></p>

<form method="get" class="dfxpray-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( 'dfxpray-group-' . $group_id ); ?>">
	<input type="hidden" name="tab" value="intentions">
	<select name="status" aria-label="<?php esc_attr_e( 'Status', 'dfx-prayer-intentions' ); ?>">
		<option value=""><?php esc_html_e( 'All statuses', 'dfx-prayer-intentions' ); ?></option>
		<?php foreach ( $dfxpray_lifecycle->statuses() as $dfxpray_key => $dfxpray_label ) : ?>
			<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_filters['status'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="type" aria-label="<?php esc_attr_e( 'Kind', 'dfx-prayer-intentions' ); ?>">
		<option value=""><?php esc_html_e( 'All kinds', 'dfx-prayer-intentions' ); ?></option>
		<?php foreach ( $dfxpray_lifecycle->types() as $dfxpray_key => $dfxpray_label ) : ?>
			<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_filters['type'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
		<?php endforeach; ?>
	</select>
	<select name="priority" aria-label="<?php esc_attr_e( 'Priority', 'dfx-prayer-intentions' ); ?>">
		<option value=""><?php esc_html_e( 'Any priority', 'dfx-prayer-intentions' ); ?></option>
		<option value="high" <?php selected( $dfxpray_filters['priority'], 'high' ); ?>><?php esc_html_e( 'High', 'dfx-prayer-intentions' ); ?></option>
		<option value="normal" <?php selected( $dfxpray_filters['priority'], 'normal' ); ?>><?php esc_html_e( 'Normal', 'dfx-prayer-intentions' ); ?></option>
	</select>
	<label><?php esc_html_e( 'From', 'dfx-prayer-intentions' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $dfxpray_filters['date_from'] ); ?>"></label>
	<label><?php esc_html_e( 'To', 'dfx-prayer-intentions' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $dfxpray_filters['date_to'] ); ?>"></label>
	<input type="search" name="s" value="<?php echo esc_attr( $dfxpray_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search by name', 'dfx-prayer-intentions' ); ?>">
	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dfx-prayer-intentions' ); ?></button>
</form>

<table class="widefat striped dfxpray-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'For', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Requester', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Kind', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Date', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Status', 'dfx-prayer-intentions' ); ?></th>
			<th><?php esc_html_e( 'Next review', 'dfx-prayer-intentions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $dfxpray_rows ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No intentions found.', 'dfx-prayer-intentions' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $dfxpray_rows as $dfxpray_row ) : ?>
			<tr>
				<td>
					<?php echo 'high' === $dfxpray_row['priority'] ? '❗ ' : ''; ?>
					<a href="
					<?php
					echo esc_url(
						$dfxpray_admin->url(
							'dfxpray-intentions',
							array(
								'view' => 'edit',
								'id'   => $dfxpray_row['id'],
							)
						)
					);
					?>
								"><strong><?php echo esc_html( $dfxpray_row['beneficiary_name'] ); ?></strong></a>
					<?php if ( '' !== $dfxpray_row['summary'] ) : ?>
						<br><small><?php echo esc_html( $dfxpray_row['summary'] ); ?></small>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $dfxpray_row['requester_name'] ); ?></td>
				<td><?php echo esc_html( $dfxpray_lifecycle->types()[ $dfxpray_row['type'] ] ?? '' ); ?></td>
				<td><?php echo $dfxpray_row['reference_date'] ? esc_html( DFXPRAY_Formatter::get_instance()->when( $dfxpray_row['reference_date'], $dfxpray_row['reference_time'] ) ) : '—'; ?></td>
				<td><span class="dfxpray-status dfxpray-status--<?php echo esc_attr( $dfxpray_row['status'] ); ?>"><?php echo esc_html( $dfxpray_lifecycle->statuses()[ $dfxpray_row['status'] ] ?? '' ); ?></span></td>
				<td><?php echo $dfxpray_row['next_review'] ? esc_html( mysql2date( get_option( 'date_format' ), $dfxpray_row['next_review'] ) ) : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
