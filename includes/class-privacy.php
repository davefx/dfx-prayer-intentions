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
 * Plugs into WordPress' privacy tools, and backs the panel's own lookup by
 * email or phone. A contact finds the requests sent with it (with their
 * updates and the leaders' notes) and the member records with that phone.
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
	 * Who is responsible for a group's data: its controller, the default one,
	 * or the site.
	 *
	 * @param int|null $group_id Group ID; null for the default.
	 */
	public function controller( ?int $group_id = null ): string {
		$controller = trim( (string) DFXPRAY_Settings::get_instance()->get( 'privacy_controller', $group_id ) );

		return '' !== $controller ? $controller : (string) get_bloginfo( 'name' );
	}

	/**
	 * Contact for data protection of a group, or ''.
	 *
	 * @param int|null $group_id Group ID; null for the default.
	 */
	public function contact( ?int $group_id = null ): string {
		return trim( (string) DFXPRAY_Settings::get_instance()->get( 'privacy_contact', $group_id ) );
	}

	/**
	 * The privacy policy of a group: its own, or the site's; '' when none.
	 *
	 * @param int $group_id Group ID.
	 */
	public function policy_url( int $group_id ): string {
		$own = (string) DFXPRAY_Settings::get_instance()->get( 'privacy_policy_url', $group_id );

		return '' !== $own ? $own : (string) get_privacy_policy_url();
	}

	/**
	 * Who provides the service as processor, '' when not set.
	 */
	public function provider(): string {
		return trim( (string) DFXPRAY_Settings::get_instance()->get( 'provider_name' ) );
	}

	/**
	 * The provider with its identification: "Name (tax ID, address, email)".
	 */
	private function provider_identified(): string {
		$settings = DFXPRAY_Settings::get_instance();
		$details  = array_filter( array_map( 'trim', array( (string) $settings->get( 'provider_tax_id' ), (string) $settings->get( 'provider_address' ), (string) $settings->get( 'provider_email' ) ) ) );

		return $this->provider() . ( $details ? ' (' . implode( ', ', $details ) . ')' : '' );
	}

	/**
	 * The subprocessors, as configured (one per line), or a generic line.
	 */
	private function subprocessors(): string {
		$list = trim( (string) DFXPRAY_Settings::get_instance()->get( 'subprocessors' ) );

		return '' !== $list ? $list : __( 'the hosting and email providers of this site.', 'dfx-prayer-intentions' );
	}

	/**
	 * Whether a group still lacks a controller of its own or an accepted
	 * processing agreement (only when a provider is set: otherwise the site
	 * itself is responsible).
	 *
	 * @param int $group_id Group ID.
	 */
	public function agreement_pending( int $group_id ): bool {
		$settings = DFXPRAY_Settings::get_instance();

		return '' === trim( (string) $settings->get( 'privacy_controller', $group_id ) ) || ( '' !== $this->provider() && '' === (string) $settings->get( 'dpa_accepted_on', $group_id ) );
	}

	/**
	 * The data processing agreement (art. 28 of the GDPR) between a group's
	 * controller and the provider, ready to accept.
	 *
	 * @param int $group_id Group ID.
	 */
	public function processing_agreement( int $group_id ): string {
		$group      = DFXPRAY_Groups::get_instance()->get( $group_id );
		$controller = $this->controller( $group_id );
		$provider   = '' !== $this->provider() ? $this->provider_identified() : (string) get_bloginfo( 'name' );
		$accepted   = (string) DFXPRAY_Settings::get_instance()->get( 'dpa_accepted_on', $group_id );

		$clauses = array(
			/* translators: %s: group name. */
			sprintf( __( 'DATA PROCESSING AGREEMENT (art. 28 of the GDPR) for the prayer group “%s”.', 'dfx-prayer-intentions' ), $group ? $group['name'] : '' ),
			/* translators: 1: data controller, 2: provider with its identification. */
			sprintf( __( '1. Parties. %1$s, as data controller, and %2$s, as data processor.', 'dfx-prayer-intentions' ), $controller, $provider ),
			__( '2. Object and duration. The processor hosts and runs the tool with which the group receives, moderates and shares its prayer requests and keeps its list of members, for as long as the group uses the service.', 'dfx-prayer-intentions' ),
			__( '3. Data and data subjects. People who send prayer requests (name, contact, phone and the text of the request, which may include data about health and religious beliefs, special categories of data); the people prayed for (name, relationship and the circumstances told); the members of the group (name, how they are called, phone, birthday, date of death and notes).', 'dfx-prayer-intentions' ),
			__( '4. Obligations of the processor. a) To process the data only on the documented instructions of the controller, which are the configuration of the group and what its leaders ask; b) to ensure the confidentiality of everyone who processes them; c) to apply the security measures of art. 32: access restricted to each group, encryption in transit, backups and scheduled deletion; d) to use only the following subprocessors, informing the controller of any change so that it can object:', 'dfx-prayer-intentions' ) . "\n" . $this->subprocessors(),
			__( 'e) To help the controller to answer the exercise of rights, and with security and impact assessments; f) to notify the controller without undue delay of any personal data breach; g) to make available the information needed to demonstrate compliance; h) at the end of the service, to delete all the data of the group, unless the controller asks for them first.', 'dfx-prayer-intentions' ),
			__( '5. Obligations of the controller. To have a legal basis and inform the data subjects (the request form does so), to appoint the leaders of the group, who are bound to confidentiality, and to give its instructions through the leaders or in writing.', 'dfx-prayer-intentions' ),
			__( '6. The service is provided free of charge.', 'dfx-prayer-intentions' ),
			'' !== $accepted
				/* translators: %s: date. */
				? sprintf( __( '7. Accepted on %s by both parties.', 'dfx-prayer-intentions' ), $accepted )
				: __( '7. Accepted on ____ by both parties. Signed: for the controller ____________; for the processor ____________.', 'dfx-prayer-intentions' ),
		);

		return implode( "\n\n", $clauses );
	}

	/**
	 * Export what is kept about a contact (email or phone): the requests sent
	 * with it, and, on the first page, the member records with that phone.
	 *
	 * @param string $contact Email address or phone, in any format.
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
			$notes     = array_map(
				static fn( $c ) => $c['created_at'] . ': ' . $c['body'],
				DFXPRAY_Comments::get_instance()->for_intention( $id )
			);
			$dates     = array_map(
				static fn( $d ) => trim( $d['date'] . ' ' . $d['time'] . ' ' . $d['label'] ),
				(array) $intention['key_dates']
			);

			$fields = array(
				__( 'Group', 'dfx-prayer-intentions' )     => $group ? $group['name'] : '',
				__( 'Your name', 'dfx-prayer-intentions' ) => $intention['requester_name'],
				__( 'Contact', 'dfx-prayer-intentions' )   => $intention['requester_contact'],
				__( 'Phone', 'dfx-prayer-intentions' )     => $intention['requester_phone'],
				__( 'Beneficiary', 'dfx-prayer-intentions' ) => $intention['beneficiary_name'],
				__( 'Relationship', 'dfx-prayer-intentions' ) => $intention['relationship'],
				__( 'Names to show', 'dfx-prayer-intentions' ) => trim( implode( ' / ', array_filter( array( $intention['requester_display_name'], $intention['beneficiary_display_name'], $intention['requester_first_name'], $intention['beneficiary_first_name'] ) ) ) ),
				__( 'Kind', 'dfx-prayer-intentions' )      => $types[ $intention['type'] ] ?? $intention['type'],
				__( 'Date', 'dfx-prayer-intentions' )      => trim( $intention['reference_date'] . ' ' . $intention['reference_time'] ),
				__( 'Key dates', 'dfx-prayer-intentions' ) => implode( "\n", $dates ),
				__( 'Intention', 'dfx-prayer-intentions' ) => $intention['body'],
				__( 'Summary', 'dfx-prayer-intentions' )   => $intention['summary'],
				__( 'Discretion', 'dfx-prayer-intentions' ) => DFXPRAY_Discretion::get_instance()->levels()[ $intention['discretion'] ] ?? '',
				__( 'Status', 'dfx-prayer-intentions' )    => DFXPRAY_Lifecycle::get_instance()->statuses()[ $intention['status'] ] ?? '',
				__( 'Sent on', 'dfx-prayer-intentions' )   => $intention['created_at'],
				__( 'Updates', 'dfx-prayer-intentions' )   => implode( "\n", $updates ),
				__( 'Leaders’ notes', 'dfx-prayer-intentions' ) => implode( "\n", $notes ),
			);

			$data[] = $this->item( 'dfxpray-intentions', __( 'Prayer intentions', 'dfx-prayer-intentions' ), 'dfxpray-intention-' . $id, $fields );
		}

		if ( 1 === max( 1, (int) $page ) ) {
			foreach ( $this->find_members( (string) $contact ) as $member ) {
				$group    = DFXPRAY_Groups::get_instance()->get( $member['group_id'] );
				$birthday = implode( '/', array_filter( array( $member['birth_day'], $member['birth_month'], $member['birth_year'] ) ) );

				$data[] = $this->item(
					'dfxpray-members',
					__( 'Member of a prayer group', 'dfx-prayer-intentions' ),
					'dfxpray-member-' . $member['id'],
					array(
						__( 'Group', 'dfx-prayer-intentions' )    => $group ? $group['name'] : '',
						__( 'Name', 'dfx-prayer-intentions' )     => $member['name'],
						__( 'Called', 'dfx-prayer-intentions' )   => $member['called'],
						__( 'Phone', 'dfx-prayer-intentions' )    => $member['phone'],
						__( 'Birthday', 'dfx-prayer-intentions' ) => $birthday,
						__( 'Notes', 'dfx-prayer-intentions' )    => (string) $member['notes'],
					)
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * One exported item in WordPress' format.
	 *
	 * @param string                $group_id    Group of items.
	 * @param string                $group_label Its label.
	 * @param string                $item_id     Item ID.
	 * @param array<string, string> $fields      Name => value.
	 */
	private function item( string $group_id, string $group_label, string $item_id, array $fields ): array {
		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => array_map(
				static fn( $name, $value ) => array(
					'name'  => $name,
					'value' => (string) $value,
				),
				array_keys( $fields ),
				array_values( $fields )
			),
		);
	}

	/**
	 * Erase what is kept about a contact: the requests sent with it, with
	 * everything attached, and the member records with that phone.
	 *
	 * @param string $contact Email address or phone, in any format.
	 * @param int    $page    Page, from 1 (always the first: erased rows vanish).
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $contact, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature required by WordPress.
		$ids     = $this->find( (string) $contact, 1 );
		$members = $this->find_members( (string) $contact );

		DFXPRAY_Intentions::get_instance()->delete( $ids );
		foreach ( $members as $member ) {
			DFXPRAY_Members::get_instance()->delete( (int) $member['id'] );
		}

		return array(
			'items_removed'  => $ids || $members,
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
	 * Suggested privacy policy text for the site: who runs it as processor,
	 * that each group's controller is on its form, and everything article 13
	 * of the GDPR asks to tell, for those who send requests, those prayed for
	 * and the members of the groups.
	 */
	public function policy_text(): string {
		$settings = DFXPRAY_Settings::get_instance();
		$title    = static fn( string $text ): string => '<strong>' . $text . '</strong> ';
		$provider = $this->provider();

		$who = '' !== $provider
			? $title( __( 'Who runs this site.', 'dfx-prayer-intentions' ) )
				/* translators: %s: provider of the service, with its identification. */
				. sprintf( __( '%s provides this service free of charge to parishes and prayer groups to manage their prayer requests. It acts as data processor (art. 28 of the GDPR): it processes the data only on behalf of and on the instructions of each controller, and never for purposes of its own.', 'dfx-prayer-intentions' ), $this->provider_identified() )
				. "\n\n" . $title( __( 'Who is responsible.', 'dfx-prayer-intentions' ) )
				. __( 'The controller of each prayer request, and of the data of the members of each group, is the parish or body shown on the form of that group, with its contact for data protection.', 'dfx-prayer-intentions' )
			: $title( __( 'Who is responsible.', 'dfx-prayer-intentions' ) )
				/* translators: %s: data controller (a parish, an association…). */
				. sprintf( __( '%s is responsible for the prayer requests sent through this site and for the data of the members of its prayer groups.', 'dfx-prayer-intentions' ), $this->controller() )
				/* translators: %s: email or address for data protection. */
				. ( '' !== $this->contact() ? ' ' . sprintf( __( 'You can write to %s about your data.', 'dfx-prayer-intentions' ), $this->contact() ) : '' );

		$rights = '' !== $provider
			? __( 'You can access your data, rectify it, erase it, restrict or object to its processing, ask for its portability and withdraw your consent at any time, before the controller of your group (in the contact shown on its form) or writing to the provider of the service, who will pass your request on to it. You can also complain to the data protection supervisory authority (in Spain, the Agencia Española de Protección de Datos).', 'dfx-prayer-intentions' )
			: __( 'You can access your data, rectify it, erase it, restrict or object to its processing, ask for its portability and withdraw your consent at any time, writing to the contact above or with the contact or phone you gave in the form. You can also complain to the data protection supervisory authority (in Spain, the Agencia Española de Protección de Datos).', 'dfx-prayer-intentions' );

		return implode(
			"\n\n",
			array(
				$who,
				$title( __( 'What data we collect.', 'dfx-prayer-intentions' ) )
				. __( 'When you send a prayer request we collect your name, a way to contact you if you give it (email or phone), the name of the person you ask us to pray for, your relationship with them, the text of your intention, the dates you give and how the names may appear. It may include data about health or religious beliefs, which are special categories of personal data.', 'dfx-prayer-intentions' ),
				$title( __( 'Purpose and legal basis.', 'dfx-prayer-intentions' ) )
				. __( 'We use these data only to pray for your intention in the group you write to and, if you left a contact, to ask you how it goes. The legal basis is your explicit consent (arts. 6.1.a and 9.2.a of the GDPR).', 'dfx-prayer-intentions' ),
				$title( __( 'Who sees them.', 'dfx-prayer-intentions' ) )
				. __( 'Only the leaders of the group read your request: in their private panel, in their app and, if the group keeps it so, in an email notice. A leader may also use a program of their own (a routine) that works on the group\'s list with a password only for this, from their own computer. The group receives a short summary written by the leaders, with the names only as you chose (full name, first name, initials or anonymous), through its messaging channel. The full text is never shared with the group.', 'dfx-prayer-intentions' ),
				$title( __( 'Services that take part.', 'dfx-prayer-intentions' ) )
				. __( 'The summaries reach the group through messaging services such as WhatsApp or Telegram, and the notices for the leaders by email and, if configured, by Telegram or a webhook. If the form uses Cloudflare Turnstile against abuse, Cloudflare receives your IP address. Some of these services may process data outside the European Union, under the safeguards each of them offers, such as the EU–US Data Privacy Framework or standard contractual clauses.', 'dfx-prayer-intentions' )
				. ( '' !== $provider ? "\n\n" . __( 'The provider of the service relies on these subprocessors:', 'dfx-prayer-intentions' ) . "\n" . $this->subprocessors() : '' ),
				$title( __( 'How long we keep them.', 'dfx-prayer-intentions' ) )
				/* translators: %d: number of days. */
				. sprintf( __( 'While we pray for your intention and until the leaders close it; then it is deleted, with its updates and notes, %d days later. To stop abuse, your IP address is kept only as a one-way hash for one hour, and what you typed is kept for a few minutes if the form has to be shown again after an error.', 'dfx-prayer-intentions' ), (int) $settings->get( 'retention_days' ) ),
				$title( __( 'People we pray for.', 'dfx-prayer-intentions' ) )
				. __( 'When you ask for someone else, we do not inform them directly, because it would be impossible or would require a disproportionate effort (art. 14.5.b of the GDPR). To protect them, the group only receives a discreet summary without diagnoses, with their name as you chose, and they can exercise the same rights as you.', 'dfx-prayer-intentions' ),
				$title( __( 'Members of the group.', 'dfx-prayer-intentions' ) )
				. __( 'For the members of each group the leaders keep their name, how they are called, their phone, their birthday, the date of their death and short notes, to name them correctly and to remember their birthdays and the anniversaries of their death in the daily message. The legal basis is the legitimate interest of the group and, as members of a religious body, art. 9.2.d of the GDPR: these data are not disclosed outside it without their consent. They are kept while they belong to the group, and any member can ask to see them or to have them erased.', 'dfx-prayer-intentions' ),
				$title( __( 'Minors.', 'dfx-prayer-intentions' ) )
				/* translators: %d: minimum age. */
				. sprintf( __( 'You must be at least %d years old to send a request on your own.', 'dfx-prayer-intentions' ), (int) $settings->get( 'consent_min_age' ) ),
				$title( __( 'Your rights.', 'dfx-prayer-intentions' ) ) . $rights,
			)
		);
	}

	/**
	 * IDs of the intentions sent from a contact: the same email, or the same
	 * phone written in any format, as the contact or as the requester's phone.
	 *
	 * @param string $contact Contact.
	 * @param int    $page    Page, from 1.
	 * @return int[]
	 */
	private function find( string $contact, int $page ): array {
		global $wpdb;

		$contact = trim( $contact );
		$phone   = $this->phone_key( $contact );
		$table   = DFXPRAY_Installer::get_instance()->get_table_names()['intentions'];
		$offset  = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		if ( '' === $contact ) {
			return array();
		}

		if ( '' === $phone ) {
			return array_map(
				'intval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						'SELECT id FROM %i WHERE LOWER(requester_contact) = LOWER(%s) ORDER BY id LIMIT %d OFFSET %d',
						$table,
						$contact,
						self::PER_PAGE,
						$offset
					)
				)
			);
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, requester_contact, requester_phone FROM %i WHERE requester_contact <> '' OR requester_phone <> '' ORDER BY id", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$ids  = array();
		foreach ( (array) $rows as $row ) {
			if ( $phone === $this->phone_key( (string) $row['requester_contact'] ) || $phone === $this->phone_key( (string) $row['requester_phone'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		return array_slice( $ids, $offset, self::PER_PAGE );
	}

	/**
	 * Member records whose phone matches the contact, in any format.
	 *
	 * @param string $contact Contact.
	 * @return array[]
	 */
	private function find_members( string $contact ): array {
		global $wpdb;

		$phone = $this->phone_key( $contact );
		if ( '' === $phone ) {
			return array();
		}

		$table = DFXPRAY_Installer::get_instance()->get_table_names()['members'];
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE phone <> '' ORDER BY id", $table ) ); // phpcs:ignore WordPress.DB
		$found = array();
		foreach ( (array) $ids as $id ) {
			$member = DFXPRAY_Members::get_instance()->get( (int) $id );
			if ( $member && $phone === $this->phone_key( (string) $member['phone'] ) ) {
				$found[] = $member;
			}
		}

		return $found;
	}

	/**
	 * A phone reduced to its international digits, to compare numbers written
	 * in any format ("600 11 12 22", "+34600111222", "0034-600-111-222");
	 * '' when it is not a phone.
	 *
	 * @param string $value Contact as written.
	 */
	private function phone_key( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^\+?[\d\s().\/-]{6,}$/', $value ) ) {
			return '';
		}

		$digits = (string) preg_replace( '/\D/', '', $value );
		if ( str_starts_with( $value, '+' ) ) {
			return $digits;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			return substr( $digits, 2 );
		}

		return preg_replace( '/\D/', '', (string) DFXPRAY_Settings::get_instance()->get( 'phone_country_code' ) ) . $digits;
	}
}
