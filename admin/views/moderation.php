<?php
/**
 * Moderation inbox: approve with a summary, discard, or merge duplicates.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin      = DFXPRAY_Admin::get_instance();
$dfxpray_intentions = DFXPRAY_Intentions::get_instance();
$dfxpray_pending    = array_reverse( $dfxpray_intentions->query( $group_id, array( 'status' => 'pending' ) ) );
$dfxpray_targets    = $dfxpray_intentions->listable( $group_id );
$dfxpray_levels     = DFXPRAY_Discretion::get_instance()->levels();

if ( ! $dfxpray_pending ) {
	echo '<p class="dfxpray-empty">' . esc_html__( 'Nothing waiting for moderation.', 'dfx-prayer-intentions' ) . '</p>';
	return;
}

foreach ( $dfxpray_pending as $dfxpray_item ) :
	$dfxpray_order = array_keys( $dfxpray_levels );
	$dfxpray_from  = array_search( $dfxpray_item['discretion'], $dfxpray_order, true );
	?>
	<article class="dfxpray-card dfxpray-moderation-item" id="intention-<?php echo esc_attr( (string) $dfxpray_item['id'] ); ?>">
		<header>
			<strong><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dfxpray_item['created_at'] ) ); ?></strong>
		</header>
		<?php $dfxpray_admin->view( 'partials/intention-facts', array( 'intention' => $dfxpray_item ) ); ?>

		<?php $dfxpray_admin->form_open( 'approve', array( 'intention_id' => $dfxpray_item['id'] ), 'dfxpray-approve' ); ?>
			<p>
				<label for="dfxpray-summary-<?php echo esc_attr( (string) $dfxpray_item['id'] ); ?>"><?php esc_html_e( 'Summary for the index', 'dfx-prayer-intentions' ); ?></label>
				<input type="text" class="large-text" id="dfxpray-summary-<?php echo esc_attr( (string) $dfxpray_item['id'] ); ?>" name="summary" maxlength="100" required>
				<small><?php esc_html_e( 'Up to 100 characters. This is the only text the group will see.', 'dfx-prayer-intentions' ); ?></small>
			</p>
			<p class="dfxpray-inline-fields">
				<label>
					<?php esc_html_e( 'Discretion', 'dfx-prayer-intentions' ); ?>
					<select name="discretion">
						<?php foreach ( array_slice( $dfxpray_levels, (int) $dfxpray_from, null, true ) as $dfxpray_key => $dfxpray_label ) : ?>
							<option value="<?php echo esc_attr( $dfxpray_key ); ?>" <?php selected( $dfxpray_item['discretion'], $dfxpray_key ); ?>><?php echo esc_html( $dfxpray_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Priority', 'dfx-prayer-intentions' ); ?>
					<select name="priority">
						<option value="normal"><?php esc_html_e( 'Normal', 'dfx-prayer-intentions' ); ?></option>
						<option value="high"><?php esc_html_e( 'High', 'dfx-prayer-intentions' ); ?></option>
						<option value="immediate"><?php esc_html_e( 'Immediate', 'dfx-prayer-intentions' ); ?></option>
					</select>
				</label>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Approve', 'dfx-prayer-intentions' ); ?></button></p>
		</form>

		<div class="dfxpray-inline-forms">
			<?php $dfxpray_admin->form_open( 'discard', array( 'intention_id' => $dfxpray_item['id'] ) ); ?>
				<select name="reason" aria-label="<?php esc_attr_e( 'Reason', 'dfx-prayer-intentions' ); ?>">
					<option value="spam"><?php esc_html_e( 'Spam', 'dfx-prayer-intentions' ); ?></option>
					<option value="duplicate"><?php esc_html_e( 'Duplicate', 'dfx-prayer-intentions' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Discard', 'dfx-prayer-intentions' ); ?></button>
			</form>

			<?php if ( $dfxpray_targets ) : ?>
				<?php $dfxpray_admin->form_open( 'merge', array( 'intention_id' => $dfxpray_item['id'] ) ); ?>
					<select name="target_id" aria-label="<?php esc_attr_e( 'Existing intention', 'dfx-prayer-intentions' ); ?>">
						<?php foreach ( $dfxpray_targets as $dfxpray_target ) : ?>
							<option value="<?php echo esc_attr( (string) $dfxpray_target['id'] ); ?>"><?php echo esc_html( DFXPRAY_Formatter::get_instance()->line( $dfxpray_target ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Merge as duplicate', 'dfx-prayer-intentions' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</article>
<?php endforeach; ?>
