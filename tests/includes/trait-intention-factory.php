<?php
/**
 * Helpers to build intentions in tests.
 *
 * @package DFXPRAY
 */

/**
 * Intention fixtures.
 */
trait DFXPRAY_Intention_Factory {

	/**
	 * Valid form data, with overrides.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	protected function form_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'requester_name'    => 'Luis Gómez',
				'requester_contact' => 'luis@example.org',
				'beneficiary_name'  => 'Carmen Ruiz',
				'relationship'      => 'madre de',
				'type'              => 'ongoing',
				'body'              => 'Please pray for my mother, who is ill.',
				'discretion'        => 'first_name',
			),
			$overrides
		);
	}

	/**
	 * Create a group, optionally with leaders.
	 *
	 * @param string $name    Name.
	 * @param int[]  $leaders Leader user IDs.
	 * @return int
	 */
	protected function make_group( string $name = 'Group', array $leaders = array() ): int {
		$id = DFXPRAY_Groups::get_instance()->create( array( 'name' => $name ) );
		if ( $leaders ) {
			DFXPRAY_Groups::get_instance()->set_leaders( $id, $leaders );
		}
		return $id;
	}

	/**
	 * Create an active intention through the manual path.
	 *
	 * @param int   $group_id  Group.
	 * @param array $overrides Field overrides.
	 * @return int
	 */
	protected function make_active( int $group_id, array $overrides = array() ): int {
		$id = DFXPRAY_Intentions::get_instance()->create( $group_id, $this->form_data( array_merge( array( 'summary' => 'Summary' ), $overrides ) ), 'manual' );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Create a pending intention through the form path.
	 *
	 * @param int   $group_id  Group.
	 * @param array $overrides Field overrides.
	 * @return int
	 */
	protected function make_pending( int $group_id, array $overrides = array() ): int {
		$id = DFXPRAY_Intentions::get_instance()->create( $group_id, $this->form_data( $overrides ), 'form' );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}
}
