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

<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only lookup for administrators.
$dfxpray_lookup = isset( $_GET['privacy_lookup'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['privacy_lookup'] ) ) ) : '';
?>
<h2 id="dfxpray-privacy"><?php esc_html_e( 'Requests about personal data', 'dfx-prayer-intentions' ); ?></h2>
<p class="description"><?php esc_html_e( 'When someone asks to see or erase their data, look them up by the email or phone they gave, in any format. You find their requests, by phone their member records and, by email, the invitations to lead a group sent to them. WordPress’ own tools (Tools › Export / Erase Personal Data) only take emails.', 'dfx-prayer-intentions' ); ?></p>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#dfxpray-privacy">
	<input type="hidden" name="page" value="dfxpray-global">
	<p>
		<label for="dfxpray-privacy-lookup" class="screen-reader-text"><?php esc_html_e( 'Email or phone', 'dfx-prayer-intentions' ); ?></label>
		<input type="search" id="dfxpray-privacy-lookup" name="privacy_lookup" value="<?php echo esc_attr( $dfxpray_lookup ); ?>" placeholder="<?php esc_attr_e( 'Email or phone', 'dfx-prayer-intentions' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Look up', 'dfx-prayer-intentions' ); ?></button>
	</p>
</form>
<?php
if ( '' !== $dfxpray_lookup ) {
	$dfxpray_items = array();
	$dfxpray_page  = 1;
	do {
		$dfxpray_found = DFXPRAY_Privacy::get_instance()->export( $dfxpray_lookup, $dfxpray_page++ );
		$dfxpray_items = array_merge( $dfxpray_items, $dfxpray_found['data'] );
	} while ( ! $dfxpray_found['done'] );

	if ( ! $dfxpray_items ) {
		echo '<p class="dfxpray-empty">' . esc_html__( 'Nothing is kept about this contact.', 'dfx-prayer-intentions' ) . '</p>';
	} else {
		$dfxpray_text = implode(
			"\n\n",
			array_map(
				static fn( $item ) => '== ' . $item['group_label'] . " ==\n" . implode(
					"\n",
					array_map(
						static fn( $field ) => $field['name'] . ': ' . $field['value'],
						array_filter( $item['data'], static fn( $field ) => '' !== $field['value'] )
					)
				),
				$dfxpray_items
			)
		);

		/* translators: %d: number of records found. */
		echo '<p>' . esc_html( sprintf( __( 'Records found: %d. Copy them to hand them over:', 'dfx-prayer-intentions' ), count( $dfxpray_items ) ) ) . '</p>';
		$dfxpray_admin->view(
			'partials/copy-box',
			array(
				'id'    => 'dfxpray-privacy-export',
				'label' => __( 'Personal data found', 'dfx-prayer-intentions' ),
				'text'  => $dfxpray_text,
				'rows'  => 16,
			)
		);
		$dfxpray_admin->form_open( 'privacy_erase', array( 'contact' => $dfxpray_lookup ) );
		echo '<p><button type="submit" class="button button-link-delete" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Erase everything found? It cannot be undone.', 'dfx-prayer-intentions' ) . '">' . esc_html__( 'Erase everything found', 'dfx-prayer-intentions' ) . '</button></p></form>';
	}
}
