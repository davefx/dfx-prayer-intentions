<?php
/**
 * Tests for the brothers of a group.
 *
 * @package DFXPRAY
 */

/**
 * Members behaviour: the people of a group, with how they are called, their
 * phone and their birthday.
 */
class MembersTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Service under test.
	 *
	 * @var DFXPRAY_Members
	 */
	private DFXPRAY_Members $members;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Madrid' );
		$this->freeze_time( '2026-09-24 10:00' );
		$this->members = DFXPRAY_Members::get_instance();
		$this->group   = $this->make_group( 'A' );
	}

	/**
	 * A member is kept with how they are called, a clean phone and a
	 * birthday (the year is optional).
	 */
	public function test_create(): void {
		$id = $this->members->create(
			$this->group,
			array(
				'name'        => ' Ramón García Sanz ',
				'called'      => 'Ramón García',
				'phone'       => '600 11 22 33',
				'birth_day'   => '24',
				'birth_month' => '9',
				'birth_year'  => '',
			)
		);

		$this->assertIsInt( $id );
		$member = $this->members->get( $id );
		$this->assertSame( 'Ramón García Sanz', $member['name'] );
		$this->assertSame( 'Ramón García', $member['called'] );
		$this->assertSame( '600112233', $member['phone'] );
		$this->assertSame( 24, $member['birth_day'] );
		$this->assertSame( 9, $member['birth_month'] );
		$this->assertNull( $member['birth_year'] );
		$this->assertSame( $this->group, $member['group_id'] );
	}

	/**
	 * Internal notes about a member (the retreat he walked, his table…).
	 */
	public function test_notes(): void {
		$id = $this->members->create( $this->group, array( 'name' => 'Pablo Navarro', 'notes' => " Caminó en el XI Retiro (Mesa 4)\nCoro " ) );
		$this->assertSame( "Caminó en el XI Retiro (Mesa 4)\nCoro", $this->members->get( $id )['notes'] );

		$this->assertTrue( $this->members->update( $id, array( 'called' => 'Pablo Navarro' ) ) );
		$this->assertSame( "Caminó en el XI Retiro (Mesa 4)\nCoro", $this->members->get( $id )['notes'] );
	}

	/**
	 * Wrong data is refused.
	 */
	public function test_validation(): void {
		$this->assertSame( 'dfxpray_member_name_required', $this->members->create( $this->group, array( 'name' => '' ) )->get_error_code() );
		$this->assertSame( 'dfxpray_member_birthday_invalid', $this->members->create( $this->group, array( 'name' => 'Luis', 'birth_day' => '31', 'birth_month' => '2' ) )->get_error_code() );
		$this->assertSame( 'dfxpray_member_birthday_invalid', $this->members->create( $this->group, array( 'name' => 'Luis', 'birth_day' => '12' ) )->get_error_code() );
		$this->assertSame( 'dfxpray_phone_invalid', $this->members->create( $this->group, array( 'name' => 'Luis', 'phone' => 'call me' ) )->get_error_code() );
		$this->assertIsInt( $this->members->create( $this->group, array( 'name' => 'Luis', 'birth_day' => '29', 'birth_month' => '2' ) ) );
	}

	/**
	 * Members are edited and deleted; a group lists its own, by name.
	 */
	public function test_update_delete_and_list(): void {
		$luis  = $this->members->create( $this->group, array( 'name' => 'Luis Sebastián' ) );
		$axel  = $this->members->create( $this->group, array( 'name' => 'Axel Jonás' ) );
		$other = $this->members->create( $this->make_group( 'B' ), array( 'name' => 'Pedro' ) );

		$this->assertTrue( $this->members->update( $luis, array( 'called' => 'Luis Sebastián', 'birth_day' => '3', 'birth_month' => '5' ) ) );
		$this->assertSame( 3, $this->members->get( $luis )['birth_day'] );
		$this->assertSame( 'Luis Sebastián', $this->members->get( $luis )['name'] );
		$this->assertSame( array( $axel, $luis ), array_column( $this->members->for_group( $this->group ), 'id' ) );

		$this->members->delete( $axel );
		$this->assertNull( $this->members->get( $axel ) );
		$this->assertSame( array( $luis ), array_column( $this->members->for_group( $this->group ), 'id' ) );
		$this->assertNotNull( $this->members->get( $other ) );
	}

	/**
	 * Birthdays of a day, in the group; those born on 29 February celebrate
	 * on the 28th in common years.
	 */
	public function test_birthdays_on(): void {
		$today    = $this->members->create( $this->group, array( 'name' => 'Ramón', 'birth_day' => '24', 'birth_month' => '9', 'birth_year' => '1970' ) );
		$this->members->create( $this->group, array( 'name' => 'Teo', 'birth_day' => '25', 'birth_month' => '9' ) );
		$this->members->create( $this->group, array( 'name' => 'Sin fecha' ) );
		$this->members->create( $this->make_group( 'B' ), array( 'name' => 'Otro', 'birth_day' => '24', 'birth_month' => '9' ) );
		$leap = $this->members->create( $this->group, array( 'name' => 'Bisiesto', 'birth_day' => '29', 'birth_month' => '2' ) );

		$this->assertSame( array( $today ), array_column( $this->members->birthdays_on( $this->group, '2026-09-24' ), 'id' ) );
		$this->assertSame( array( $leap ), array_column( $this->members->birthdays_on( $this->group, '2027-02-28' ), 'id' ) );
		$this->assertSame( array( $leap ), array_column( $this->members->birthdays_on( $this->group, '2028-02-29' ), 'id' ) );
		$this->assertSame( array(), $this->members->birthdays_on( $this->group, '2028-02-28' ) );
	}

	/**
	 * A member who has died keeps his date of death (never a day to come);
	 * he leaves the birthdays and is remembered every year on the
	 * anniversary of his death.
	 */
	public function test_death_date(): void {
		$juan = $this->members->create( $this->group, array( 'name' => 'Juan', 'birth_day' => '24', 'birth_month' => '9', 'death_date' => '2024-03-10' ) );
		$this->assertSame( '2024-03-10', $this->members->get( $juan )['death_date'] );

		$this->assertSame( array(), $this->members->birthdays_on( $this->group, '2026-09-24' ) );
		$this->assertSame( array( $juan ), array_column( $this->members->death_anniversaries_on( $this->group, '2027-03-10' ), 'id' ) );
		$this->assertSame( array(), $this->members->death_anniversaries_on( $this->group, '2024-03-10' ), 'Not on the day itself.' );

		$this->assertSame( 'dfxpray_member_death_invalid', $this->members->update( $juan, array( 'death_date' => '2026-09-25' ) )->get_error_code() );
		$this->assertSame( 'dfxpray_member_death_invalid', $this->members->update( $juan, array( 'death_date' => 'ayer' ) )->get_error_code() );

		$this->assertTrue( $this->members->update( $juan, array( 'death_date' => '' ) ) );
		$this->assertNull( $this->members->get( $juan )['death_date'] );
		$this->assertSame( array( $juan ), array_column( $this->members->birthdays_on( $this->group, '2026-09-24' ), 'id' ) );
	}

	/**
	 * The members in the order of the calendar, by the month and day of
	 * their date: the birthday, or the anniversary of the death of those
	 * who died. Each carries its next date, the days until it and the
	 * years turned or since; members without a date go last, by name.
	 */
	public function test_calendar(): void {
		$m = $this->members;
		$m->create( $this->group, array( 'name' => 'Zacarías Pérez' ) );
		$m->create( $this->group, array( 'name' => 'Luis Gómez', 'birth_day' => '10', 'birth_month' => '1', 'birth_year' => '1960' ) );
		$m->create( $this->group, array( 'name' => 'Ramón Vallvé', 'birth_day' => '5', 'birth_month' => '3', 'death_date' => '2023-12-27' ) );
		$m->create( $this->group, array( 'name' => 'Pepe Ruiz' ) );
		$m->create( $this->group, array( 'name' => 'Teo Martín', 'birth_day' => '20', 'birth_month' => '11' ) );
		$m->create( $this->group, array( 'name' => 'Javier López', 'called' => 'Javier López', 'birth_day' => '16', 'birth_month' => '11', 'birth_year' => '1972' ) );
		$m->create( $this->group, array( 'name' => 'Bisiesto Feb', 'birth_day' => '29', 'birth_month' => '2', 'birth_year' => '1980' ) );

		$list = $m->calendar( $this->group, '2026-11-16' );

		$this->assertSame( array( 'Luis Gómez', 'Bisiesto Feb', 'Javier López', 'Teo Martín', 'Ramón Vallvé', 'Pepe Ruiz', 'Zacarías Pérez' ), array_column( $list, 'name' ) );
		$this->assertSame( array( 1, 2, 11, 11, 12, null, null ), array_column( $list, 'month' ) );
		$this->assertSame( array( 10, 29, 16, 20, 27, null, null ), array_column( $list, 'day' ) );
		$this->assertSame( array( 'birthday', 'birthday', 'birthday', 'birthday', 'anniversary', '', '' ), array_column( $list, 'kind' ) );
		$this->assertSame( array( '2027-01-10', '2027-02-28', '2026-11-16', '2026-11-20', '2026-12-27', null, null ), array_column( $list, 'next' ) );
		$this->assertSame( array( 55, 104, 0, 4, 41, null, null ), array_column( $list, 'days' ) );
		$this->assertSame( array( 67, 47, 54, null, 3, null, null ), array_column( $list, 'years' ) );
		$this->assertSame( 'Javier López', $list[2]['shown'] );
		$this->assertSame( 'Teo', $list[3]['shown'] );
	}

	/**
	 * Deleting a group deletes its members.
	 */
	public function test_deleting_the_group_deletes_its_members(): void {
		$id = $this->members->create( $this->group, array( 'name' => 'Ramón' ) );

		DFXPRAY_Groups::get_instance()->delete( $this->group );

		$this->assertNull( $this->members->get( $id ) );
	}
}
