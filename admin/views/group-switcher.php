<?php
/**
 * Group selector in the header of every screen. Hidden with a single group.
 *
 * @var string $page   Current page slug.
 * @var int    $active Active group ID.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_ids = DFXPRAY_Permissions::get_instance()->group_ids_for_user();
if ( count( $dfxpray_ids ) < 2 ) {
	return;
}
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="dfxpray-group-switcher">
	<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
	<label for="dfxpray-group-select"><?php esc_html_e( 'Group:', 'dfx-prayer-intentions' ); ?></label>
	<select id="dfxpray-group-select" name="group" onchange="this.form.submit()">
		<?php foreach ( $dfxpray_ids as $dfxpray_id ) : ?>
			<?php $dfxpray_g = DFXPRAY_Groups::get_instance()->get( $dfxpray_id ); ?>
			<option value="<?php echo esc_attr( (string) $dfxpray_id ); ?>" <?php selected( $active, $dfxpray_id ); ?>><?php echo esc_html( $dfxpray_g['name'] ); ?></option>
		<?php endforeach; ?>
	</select>
	<noscript><button type="submit" class="button"><?php esc_html_e( 'Switch', 'dfx-prayer-intentions' ); ?></button></noscript>
</form>
