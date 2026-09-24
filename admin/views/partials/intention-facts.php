<?php
/**
 * Read-only facts of an intention, as the requester sent them.
 *
 * @var array $intention Intention.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dfxpray_types  = DFXPRAY_Lifecycle::get_instance()->types();
$dfxpray_levels = DFXPRAY_Discretion::get_instance()->levels();
?>
<dl class="dfxpray-facts">
	<dt><?php esc_html_e( 'Requester', 'dfx-prayer-intentions' ); ?></dt>
	<dd><?php echo esc_html( $intention['requester_name'] ); ?><?php echo '' !== $intention['requester_contact'] ? ' · ' . esc_html( $intention['requester_contact'] ) : ''; ?></dd>
	<dt><?php esc_html_e( 'For', 'dfx-prayer-intentions' ); ?></dt>
	<dd>
		<?php
		echo $intention['beneficiary_is_requester']
			? esc_html__( 'Themselves', 'dfx-prayer-intentions' )
			: esc_html( $intention['beneficiary_name'] . ( '' !== $intention['relationship'] ? ' (' . $intention['relationship'] . ')' : '' ) );
		?>
	</dd>
	<dt><?php esc_html_e( 'Kind', 'dfx-prayer-intentions' ); ?></dt>
	<dd>
		<?php echo esc_html( $dfxpray_types[ $intention['type'] ] ?? $intention['type'] ); ?>
		<?php if ( $intention['reference_date'] ) : ?>
			· <?php echo esc_html( DFXPRAY_Formatter::get_instance()->when( $intention['reference_date'], $intention['reference_time'] ) ); ?>
		<?php endif; ?>
	</dd>
	<dt><?php esc_html_e( 'Discretion', 'dfx-prayer-intentions' ); ?></dt>
	<dd><?php echo esc_html( $dfxpray_levels[ $intention['discretion'] ] ?? '' ); ?> → <em><?php echo esc_html( DFXPRAY_Formatter::get_instance()->person( $intention ) ); ?></em></dd>
</dl>
<blockquote class="dfxpray-body"><?php echo nl2br( esc_html( $intention['body'] ) ); ?></blockquote>
