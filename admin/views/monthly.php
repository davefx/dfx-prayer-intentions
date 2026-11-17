<?php
/**
 * Monthly digest: the monthly list of the leaders' app to share, and the
 * leaders' version with who asks and a private block. Not sent: copied.
 *
 * @var int $group_id Active group.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="description"><?php esc_html_e( 'Every active intention, by topic, to read at the monthly adoration.', 'dfx-prayer-intentions' ); ?></p>

<?php
DFXPRAY_Admin::get_instance()->view(
	'partials/digest-variants',
	array(
		'prefix' => 'dfxpray-monthly',
		'digest' => DFXPRAY_Digest_Composer::get_instance()->compose_monthly( $group_id ),
	)
);
