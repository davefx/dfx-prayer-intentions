<?php
/**
 * Tests for updates and internal comments.
 *
 * @package DFXPRAY
 */

/**
 * Updates and comments behaviour.
 */
class UpdatesAndCommentsTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * A group.
	 *
	 * @var int
	 */
	private int $group;

	/**
	 * A leader.
	 *
	 * @var int
	 */
	private int $leader;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->freeze_time( '2026-09-24 10:00' );
		$this->leader = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		$this->group  = $this->make_group( 'A', array( $this->leader ) );
		wp_set_current_user( $this->leader );
	}

	/**
	 * Updates record date, author, text and news flag.
	 */
	public function test_add_update(): void {
		$id      = $this->make_active( $this->group );
		$updates = DFXPRAY_Updates::get_instance();

		$updates->add( $id, 'Surgery went well', true );

		$list = $updates->for_intention( $id );
		$this->assertCount( 1, $list );
		$this->assertSame( 'Surgery went well', $list[0]['body'] );
		$this->assertTrue( $list[0]['is_news'] );
		$this->assertSame( $this->leader, $list[0]['author_id'] );
		$this->assertSame( '2026-09-24 10:00:00', $list[0]['created_at'] );
	}

	/**
	 * Empty updates are refused.
	 */
	public function test_empty_update_refused(): void {
		$id = $this->make_active( $this->group );

		$this->assertWPError( DFXPRAY_Updates::get_instance()->add( $id, '  ' ) );
	}

	/**
	 * News since a moment, only from the group's listable intentions.
	 */
	public function test_news_since(): void {
		$updates = DFXPRAY_Updates::get_instance();
		$a       = $this->make_active( $this->group );
		$closed  = $this->make_active( $this->group );
		$other   = $this->make_active( $this->make_group( 'B' ) );

		$this->freeze_time( '2026-09-22 10:00' );
		$updates->add( $a, 'Old news', true );
		$this->freeze_time( '2026-09-24 09:00' );
		$updates->add( $a, 'Fresh news', true );
		$updates->add( $a, 'Not news', false );
		$updates->add( $closed, 'Closed news', true );
		$updates->add( $other, 'Other group news', true );
		DFXPRAY_Intentions::get_instance()->close( $closed, 'answered' );

		$news = $updates->news_since( $this->group, '2026-09-23 10:00:00' );

		$this->assertSame( array( 'Fresh news' ), array_column( $news, 'body' ) );
		$this->assertSame( $a, $news[0]['intention_id'] );
	}

	/**
	 * Comments are listed newest first.
	 */
	public function test_comments_newest_first(): void {
		$id       = $this->make_active( $this->group );
		$comments = DFXPRAY_Comments::get_instance();

		$comments->add( $id, 'Called her', 'call' );
		$this->freeze_time( '2026-09-24 12:00' );
		$comments->add( $id, 'Visited', 'visit' );

		$list = $comments->for_intention( $id );
		$this->assertSame( array( 'Visited', 'Called her' ), array_column( $list, 'body' ) );
		$this->assertSame( 'visit', $list[0]['type'] );
	}

	/**
	 * Unknown comment types are dropped.
	 */
	public function test_unknown_comment_type_is_blank(): void {
		$id = $this->make_active( $this->group );
		$c  = DFXPRAY_Comments::get_instance()->add( $id, 'Hi', 'gossip' );

		$this->assertSame( '', DFXPRAY_Comments::get_instance()->get( $c )['type'] );
	}

	/**
	 * Any leader may edit any comment, and the edit is signed.
	 */
	public function test_edit_comment_is_signed(): void {
		$id       = $this->make_active( $this->group );
		$comments = DFXPRAY_Comments::get_instance();
		$c        = $comments->add( $id, 'Typo' );

		$other = self::factory()->user->create( array( 'role' => 'dfxpray_leader' ) );
		wp_set_current_user( $other );
		$this->freeze_time( '2026-09-25 08:00' );
		$this->assertTrue( $comments->update( $c, 'Fixed', 'note' ) );

		$comment = $comments->get( $c );
		$this->assertSame( 'Fixed', $comment['body'] );
		$this->assertSame( $this->leader, $comment['author_id'] );
		$this->assertSame( $other, $comment['edited_by'] );
		$this->assertSame( '2026-09-25 08:00:00', $comment['edited_at'] );
	}

	/**
	 * Comments can be deleted.
	 */
	public function test_delete_comment(): void {
		$id       = $this->make_active( $this->group );
		$comments = DFXPRAY_Comments::get_instance();
		$c        = $comments->add( $id, 'Bye' );

		$comments->delete( $c );

		$this->assertNull( $comments->get( $c ) );
		$this->assertSame( 'comment_deleted', DFXPRAY_Log::get_instance()->for_intention( $id )[0]['action'] );
	}

	/**
	 * A comment becomes an update when it should reach the group.
	 */
	public function test_convert_comment_to_update(): void {
		$id       = $this->make_active( $this->group );
		$comments = DFXPRAY_Comments::get_instance();
		$c        = $comments->add( $id, 'She is home now', 'call' );

		$update_id = $comments->convert_to_update( $c, true );

		$this->assertIsInt( $update_id );
		$update = DFXPRAY_Updates::get_instance()->for_intention( $id )[0];
		$this->assertSame( 'She is home now', $update['body'] );
		$this->assertTrue( $update['is_news'] );
		$this->assertNotNull( $comments->get( $c ) );
	}
}
