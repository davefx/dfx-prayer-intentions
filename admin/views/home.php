<?php
/**
 * Home: counters of every group of the user and shortcuts.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_admin = DFXPRAY_Admin::get_instance();
$dfxpray_ids   = DFXPRAY_Permissions::get_instance()->group_ids_for_user();
$dfxpray_label = array(
	'pending'  => __( 'Pending', 'dfx-prayer-intentions' ),
	'review'   => __( 'In review', 'dfx-prayer-intentions' ),
	'today'    => __( 'Events today', 'dfx-prayer-intentions' ),
	'tomorrow' => __( 'Events tomorrow', 'dfx-prayer-intentions' ),
);
$dfxpray_link  = array(
	'pending'  => 'dfxpray-moderation',
	'review'   => 'dfxpray-reviews',
	'today'    => 'dfxpray-intentions',
	'tomorrow' => 'dfxpray-intentions',
);

if ( ! $dfxpray_ids ) {
	echo '<div class="notice notice-info inline"><p>' . esc_html__( 'You are not assigned to any group yet. Ask the site administrator.', 'dfx-prayer-intentions' ) . '</p></div>';
	if ( DFXPRAY_Permissions::get_instance()->is_admin() ) {
		echo '<p><a class="button button-primary" href="' . esc_url( $dfxpray_admin->url( 'dfxpray-groups' ) ) . '">' . esc_html__( 'Create the first group', 'dfx-prayer-intentions' ) . '</a></p>';
	}
	return;
}
?>
<div class="dfxpray-cards">
	<?php foreach ( $dfxpray_ids as $dfxpray_id ) : ?>
		<?php
		$dfxpray_g      = DFXPRAY_Groups::get_instance()->get( $dfxpray_id );
		$dfxpray_counts = DFXPRAY_Intentions::get_instance()->counts( $dfxpray_id );
		?>
		<section class="dfxpray-card">
			<h2><?php echo esc_html( $dfxpray_g['name'] ); ?></h2>
			<ul class="dfxpray-counters">
				<?php foreach ( $dfxpray_counts as $dfxpray_key => $dfxpray_value ) : ?>
					<li>
						<a href="<?php echo esc_url( $dfxpray_admin->url( $dfxpray_link[ $dfxpray_key ], array( 'group' => $dfxpray_id ) ) ); ?>">
							<span class="dfxpray-count" data-count="<?php echo esc_attr( $dfxpray_key ); ?>"><?php echo esc_html( number_format_i18n( $dfxpray_value ) ); ?></span>
							<span class="dfxpray-count-label"><?php echo esc_html( $dfxpray_label[ $dfxpray_key ] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a class="button" href="<?php echo esc_url( $dfxpray_admin->url( 'dfxpray-index', array( 'group' => $dfxpray_id ) ) ); ?>"><?php esc_html_e( 'Index', 'dfx-prayer-intentions' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dfxpray_admin->url( 'dfxpray-digest', array( 'group' => $dfxpray_id ) ) ); ?>"><?php esc_html_e( 'Daily digest', 'dfx-prayer-intentions' ); ?></a>
				<a class="button" href="
				<?php
				echo esc_url(
					$dfxpray_admin->url(
						'dfxpray-intentions',
						array(
							'group' => $dfxpray_id,
							'view'  => 'new',
						)
					)
				);
				?>
										"><?php esc_html_e( 'Add intention', 'dfx-prayer-intentions' ); ?></a>
			</p>
		</section>
	<?php endforeach; ?>
</div>
