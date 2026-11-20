<?php
/**
 * Close form, with the optional linked thanksgiving.
 *
 * @var array $intention Intention.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

DFXPRAY_Admin::get_instance()->form_open( 'close', array( 'intention_id' => $intention['id'] ), 'dfxpray-close-form' );
?>
	<label><?php esc_html_e( 'Close as', 'dfx-prayer-intentions' ); ?>
		<select name="reason">
			<?php foreach ( DFXPRAY_Lifecycle::get_instance()->close_reasons() as $dfxpray_key => $dfxpray_label ) : ?>
				<option value="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( $dfxpray_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<?php if ( DFXPRAY_Lifecycle::get_instance()->can_spawn_thanksgiving( $intention['type'] ) ) : ?>
		<?php $dfxpray_preview = DFXPRAY_Intentions::get_instance()->thanksgiving_preview( (int) $intention['id'], '' ); ?>
		<label><input type="checkbox" name="thanksgiving" value="1"> <?php esc_html_e( 'Answered: add a thanksgiving', 'dfx-prayer-intentions' ); ?></label>
		<input type="text" name="thanksgiving_summary" maxlength="100" placeholder="<?php esc_attr_e( 'Summary of the thanksgiving', 'dfx-prayer-intentions' ); ?>" aria-label="<?php esc_attr_e( 'Summary of the thanksgiving', 'dfx-prayer-intentions' ); ?>">
		<?php if ( is_string( $dfxpray_preview ) ) : ?>
			<span class="dfxpray-thanks-preview" data-intention="<?php echo esc_attr( (string) $intention['id'] ); ?>" hidden>
				<?php esc_html_e( 'In the daily and monthly digests it will appear as:', 'dfx-prayer-intentions' ); ?>
				<code><?php echo esc_html( $dfxpray_preview ); ?></code>
			</span>
		<?php endif; ?>
	<?php endif; ?>
	<button type="submit" class="button"><?php esc_html_e( 'Close', 'dfx-prayer-intentions' ); ?></button>
</form>
