<?php
/**
 * Global settings (administrators): defaults every group starts from, and
 * the settings that are global only.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin = DFXPRAY_Admin::get_instance();
?>
<p class="description"><?php esc_html_e( 'These values apply to every group. Leaders can override deadlines, templates, schedules and channels for their own group.', 'dfx-prayer-intentions' ); ?></p>
<p class="description"><?php esc_html_e( 'Punctuality: WordPress runs scheduled tasks only when someone visits the site. For the digest and hour alerts to go out on time, set a real system cron that calls wp-cron.php every five minutes.', 'dfx-prayer-intentions' ); ?></p>
<?php $dfxpray_admin->form_open( 'save_global_settings', array( 'has_panel_roles' => 1 ), 'dfxpray-settings-form' ); ?>
	<h2><?php esc_html_e( 'Access to the panel', 'dfx-prayer-intentions' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Administrators and users with the “Prayer leader” role always have access. Other roles can be given access too; their users still only see the groups they are assigned to.', 'dfx-prayer-intentions' ); ?></p>
	<p>
		<?php $dfxpray_panel_roles = DFXPRAY_Permissions::get_instance()->panel_roles(); ?>
		<?php foreach ( DFXPRAY_Permissions::get_instance()->configurable_roles() as $dfxpray_role => $dfxpray_name ) : ?>
			<label class="dfxpray-leader"><input type="checkbox" name="panel_roles[]" value="<?php echo esc_attr( $dfxpray_role ); ?>" <?php checked( in_array( $dfxpray_role, $dfxpray_panel_roles, true ) ); ?>> <?php echo esc_html( $dfxpray_name ); ?></label>
		<?php endforeach; ?>
	</p>

	<?php
	$dfxpray_admin->view(
		'partials/settings-fields',
		array(
			'values' => DFXPRAY_Settings::get_instance()->all(),
			'keys'   => $dfxpray_admin->setting_sections( 'global' ),
		)
	);
	?>
	<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'dfx-prayer-intentions' ); ?></button></p>
</form>
