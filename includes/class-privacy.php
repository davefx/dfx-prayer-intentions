<?php
/**
 * GDPR tooling: personal data export and erasure, policy text.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugs into WordPress' privacy tools, matching requests by the requester's
 * contact. Internal comments are leaders' notes and are erased with the
 * intention, but not exported.
 */
final class DFXPRAY_Privacy {

	use DFXPRAY_Singleton;

	/**
	 * Identifier of the exporter and eraser.
	 */
	private const ID = 'dfx-prayer-intentions';

	/**
	 * Intentions handled per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Prayer intentions', 'dfx-prayer-intentions' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Prayer intentions', 'dfx-prayer-intentions' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the requests sent from a contact.
	 *
	 * @param string $contact Email address (or phone) as given to the tool.
	 * @param int    $page    Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $contact, $page = 1 ): array {
		$ids   = $this->find( (string) $contact, (int) $page );
		$data  = array();
		$types = DFXPRAY_Lifecycle::get_instance()->types();

		foreach ( $ids as $id ) {
			$intention = DFXPRAY_Intentions::get_instance()->get( $id );
			$group     = DFXPRAY_Groups::get_instance()->get( $intention['group_id'] );
			$updates   = array_map(
				static fn( $u ) => $u['created_at'] . ': ' . $u['body'],
				DFXPRAY_Updates::get_instance()->for_intention( $id )
			);

			$fields = array(
				__( 'Group', 'dfx-prayer-intentions' )     => $group ? $group['name'] : '',
				__( 'Your name', 'dfx-prayer-intentions' ) => $intention['requester_name'],
				__( 'Contact', 'dfx-prayer-intentions' )   => $intention['requester_contact'],
				__( 'Phone', 'dfx-prayer-intentions' )     => $intention['requester_phone'],
				__( 'Beneficiary', 'dfx-prayer-intentions' ) => $intention['beneficiary_name'],
				__( 'Relationship', 'dfx-prayer-intentions' ) => $intention['relationship'],
				__( 'Kind', 'dfx-prayer-intentions' )      => $types[ $intention['type'] ] ?? $intention['type'],
				__( 'Date', 'dfx-prayer-intentions' )      => (string) $intention['reference_date'],
				__( 'Intention', 'dfx-prayer-intentions' ) => $intention['body'],
				__( 'Summary', 'dfx-prayer-intentions' )   => $intention['summary'],
				__( 'Discretion', 'dfx-prayer-intentions' ) => DFXPRAY_Discretion::get_instance()->levels()[ $intention['discretion'] ] ?? '',
				__( 'Status', 'dfx-prayer-intentions' )    => DFXPRAY_Lifecycle::get_instance()->statuses()[ $intention['status'] ] ?? '',
				__( 'Sent on', 'dfx-prayer-intentions' )   => $intention['created_at'],
				__( 'Updates', 'dfx-prayer-intentions' )   => implode( "\n", $updates ),
			);

			$data[] = array(
				'group_id'    => 'dfxpray-intentions',
				'group_label' => __( 'Prayer intentions', 'dfx-prayer-intentions' ),
				'item_id'     => 'dfxpray-intention-' . $id,
				'data'        => array_map(
					static fn( $name, $value ) => array(
						'name'  => $name,
						'value' => $value,
					),
					array_keys( $fields ),
					array_values( $fields )
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase the requests sent from a contact, with everything attached.
	 *
	 * @param string $contact Email address (or phone).
	 * @param int    $page    Page, from 1 (always the first: erased rows vanish).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $contact, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature required by WordPress.
		$ids = $this->find( (string) $contact, 1 );

		DFXPRAY_Intentions::get_instance()->delete( $ids );

		return array(
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Add the suggested text to the privacy policy guide.
	 */
	public function add_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'DFX Prayer Intentions', 'dfx-prayer-intentions' ), wp_kses_post( wpautop( $this->policy_text() ) ) );
		}
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text(): string {
		$days = (int) DFXPRAY_Settings::get_instance()->get( 'retention_days' );

		return implode(
			"\n\n",
			array(
				__( 'When you send a prayer request through our form, we collect your name, optionally a way to contact you, the name of the person you ask us to pray for, your relationship with them and the text of your intention. This may include information about health or religious beliefs, which are special categories of personal data.', 'dfx-prayer-intentions' ),
				__( 'Only the leaders of the prayer group you write to can read your request. They share with the group a short summary written by them, showing the names only as you chose (full name, first name, initials or anonymous). The full text is never shared.', 'dfx-prayer-intentions' ),
				/* translators: %d: number of days. */
				sprintf( __( 'Closed or discarded requests are deleted automatically %d days after being closed, together with their updates and notes.', 'dfx-prayer-intentions' ), $days ),
				__( 'You can ask for a copy of your data or for its erasure at any time, using the contact you gave in the form.', 'dfx-prayer-intentions' ),
			)
		);
	}

	/**
	 * IDs of the intentions sent from a contact.
	 *
	 * @param string $contact Contact.
	 * @param int    $page    Page, from 1.
	 * @return int[]
	 */
	private function find( string $contact, int $page ): array {
		global $wpdb;

		$contact = trim( $contact );
		if ( '' === $contact ) {
			return array();
		}

		$table = DFXPRAY_Installer::get_instance()->get_table_names()['intentions'];

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'SELECT id FROM %i WHERE LOWER(requester_contact) = LOWER(%s) ORDER BY id LIMIT %d OFFSET %d',
					$table,
					$contact,
					self::PER_PAGE,
					( max( 1, $page ) - 1 ) * self::PER_PAGE
				)
			)
		);
	}
}
