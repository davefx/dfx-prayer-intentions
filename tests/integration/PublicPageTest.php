<?php
/**
 * Tests for the obfuscated public URL of each group.
 *
 * @package DFXPRAY
 */

/**
 * Public page behaviour.
 */
class PublicPageTest extends DFXPRAY_Test_Case {

	use DFXPRAY_Intention_Factory;

	/**
	 * Set up pretty permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Each active group gets a rewrite rule for its slug.
	 */
	public function test_rewrite_rule_per_group(): void {
		$group = DFXPRAY_Groups::get_instance()->get( $this->make_group( 'A' ) );
		DFXPRAY_Public_Page::get_instance()->add_rewrite_rules();
		flush_rewrite_rules( false );

		$this->go_to( home_url( '/' . $group['slug'] . '/' ) );

		$this->assertSame( $group['slug'], get_query_var( 'dfxpray_group_slug' ) );
		$this->assertSame( $group['id'], DFXPRAY_Public_Page::get_instance()->current_group_id() );
	}

	/**
	 * Unknown or archived slugs resolve to no group.
	 */
	public function test_archived_group_is_not_served(): void {
		$id    = $this->make_group( 'A' );
		$group = DFXPRAY_Groups::get_instance()->get( $id );
		DFXPRAY_Public_Page::get_instance()->add_rewrite_rules();
		flush_rewrite_rules( false );
		DFXPRAY_Groups::get_instance()->archive( $id );

		$this->go_to( home_url( '/' . $group['slug'] . '/' ) );

		$this->assertSame( 0, DFXPRAY_Public_Page::get_instance()->current_group_id() );
	}

	/**
	 * The page asks robots to stay away, by meta and by header.
	 */
	public function test_noindex(): void {
		$page = DFXPRAY_Public_Page::get_instance();

		$robots = $page->robots( array( 'max-image-preview' => 'large' ) );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );

		$this->assertSame( 'noindex, nofollow', $page->headers()['X-Robots-Tag'] );
	}

	/**
	 * Page caches are told to keep out: headers for proxies and CDNs, and the
	 * DONOTCACHEPAGE constant most WordPress cache plugins honour.
	 */
	public function test_no_cache(): void {
		$headers = DFXPRAY_Public_Page::get_instance()->headers();

		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $headers['X-LiteSpeed-Cache-Control'] );

		DFXPRAY_Form::get_instance()->render( $this->make_group( 'A' ) );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/**
	 * The rendered page holds the form of its group, and robots.txt says nothing.
	 */
	public function test_render_page(): void {
		$id = $this->make_group( 'Santa Marta' );

		$html = DFXPRAY_Public_Page::get_instance()->render_page( $id );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="dfxpray_group" value="' . $id . '"', $html );
		$this->assertStringContainsString( "<meta name='robots' content='noindex, nofollow", $html );
		$this->assertStringNotContainsString( 'prayer-requests', apply_filters( 'robots_txt', '', true ) );
	}

	/**
	 * Pages that embed the form are also kept out of search engines.
	 */
	public function test_pages_with_form_get_noindex(): void {
		$with    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[dfxpray_form]',
			)
		);
		$block   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:dfxpray/form {"groupId":1} /-->',
			)
		);
		$without = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Hello',
			)
		);
		$page    = DFXPRAY_Public_Page::get_instance();

		$this->assertTrue( $page->post_has_form( get_post( $with ) ) );
		$this->assertTrue( $page->post_has_form( get_post( $block ) ) );
		$this->assertFalse( $page->post_has_form( get_post( $without ) ) );
	}

	/**
	 * Nothing about intentions is exposed through REST.
	 */
	public function test_no_rest_routes(): void {
		$routes = array_keys( rest_get_server()->get_routes() );

		$this->assertSame( array(), array_values( preg_grep( '/dfxpray|prayer/i', $routes ) ) );
	}
}
