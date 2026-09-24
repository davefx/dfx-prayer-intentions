<?php
/**
 * Reviews due: "still current" or close.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin = DFXPRAY_Admin::get_instance();
$dfxpray_rows  = DFXPRAY_Intentions::get_instance()->query( $group_id, array( 'status' => 'review' ) );

if ( ! $dfxpray_rows ) {
	echo '<p class="dfxpray-empty">' . esc_html__( 'No reviews due.', 'dfx-prayer-intentions' ) . '</p>';
	return;
}
?>
<p class="description"><?php esc_html_e( 'These intentions reached their review date. They still appear in the index until you decide.', 'dfx-prayer-intentions' ); ?></p>
<?php foreach ( $dfxpray_rows as $dfxpray_row ) : ?>
	<article class="dfxpray-card dfxpray-review-item">
		<p>
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
						"><strong><?php echo esc_html( DFXPRAY_Formatter::get_instance()->line( $dfxpray_row ) ); ?></strong></a>
			<br><small><?php echo esc_html( DFXPRAY_Lifecycle::get_instance()->types()[ $dfxpray_row['type'] ] ?? '' ); ?></small>
		</p>
		<div class="dfxpray-inline-forms">
			<?php $dfxpray_admin->form_open( 'keep_active', array( 'intention_id' => $dfxpray_row['id'] ) ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Still current', 'dfx-prayer-intentions' ); ?></button>
			</form>
			<?php $dfxpray_admin->view( 'partials/close-form', array( 'intention' => $dfxpray_row ) ); ?>
		</div>
	</article>
<?php endforeach; ?>
