<?php
/**
 * The two variants of a digest side by side, each ready to copy.
 *
 * @var string $prefix ID prefix of the boxes ("dfxpray-digest").
 * @var array  $digest Digest: shareable, leaders.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_variants = array(
	'shareable' => array( __( 'Shareable version', 'dfx-prayer-intentions' ), __( 'Nothing to share today.', 'dfx-prayer-intentions' ) ),
	'leaders'   => array( __( 'Leaders’ version', 'dfx-prayer-intentions' ), __( 'Nothing for the leaders today.', 'dfx-prayer-intentions' ) ),
);
?>
<div class="dfxpray-columns dfxpray-columns--even">
	<?php foreach ( $dfxpray_variants as $dfxpray_key => $dfxpray_texts ) : ?>
		<div>
			<h2><?php echo esc_html( $dfxpray_texts[0] ); ?></h2>
			<?php
			if ( '' === $digest[ $dfxpray_key ] ) {
				echo '<p class="dfxpray-empty">' . esc_html( $dfxpray_texts[1] ) . '</p>';
			} else {
				DFXPRAY_Admin::get_instance()->view(
					'partials/copy-box',
					array(
						'id'    => $prefix . '-' . $dfxpray_key,
						'label' => $dfxpray_texts[0],
						'text'  => $digest[ $dfxpray_key ],
						'rows'  => 20,
					)
				);
			}
			?>
		</div>
	<?php endforeach; ?>
</div>
