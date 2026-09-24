<?php
/**
 * Each group's own public page, at an obfuscated URL.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves /<base>-<random>/ for every active group. The page is public but not
 * advertised: noindex/nofollow in meta and in X-Robots-Tag, and never listed
 * in sitemaps, search or menus (it is not a post). It is deliberately not
 * added to robots.txt, which would announce it to anyone curious.
 */
final class DFXPRAY_Public_Page {

	use DFXPRAY_Singleton;

	/**
	 * Query variable carrying the slug.
	 */
	public const QUERY_VAR = 'dfxpray_group_slug';

	/**
	 * Whether the current request must not be indexed.
	 *
	 * @var bool
	 */
	private bool $noindex = false;

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), PHP_INT_MAX );
	}

	/**
	 * One rewrite rule per active group.
	 */
	public function add_rewrite_rules(): void {
		foreach ( DFXPRAY_Groups::get_instance()->all( 'active' ) as $group ) {
			add_rewrite_rule( '^' . preg_quote( $group['slug'], '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=' . $group['slug'], 'top' );
		}
	}

	/**
	 * Flush rules after groups or slugs change.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'dfxpray_flush_rewrite' ) ) {
			delete_option( 'dfxpray_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Register the query variable.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Group of the current request, or 0.
	 */
	public function current_group_id(): int {
		$slug = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $slug ) {
			return 0;
		}

		$group = DFXPRAY_Groups::get_instance()->get_by_slug( $slug );

		return $group && 'active' === $group['status'] ? $group['id'] : 0;
	}

	/**
	 * Serve the group page, or mark pages embedding the form as noindex.
	 */
	public function serve(): void {
		if ( is_singular() && $this->post_has_form( get_post() ) ) {
			$this->noindex = true;
			$this->prevent_caching();
			return;
		}

		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$group_id = $this->current_group_id();

		if ( ! $group_id ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$this->prevent_caching();
		status_header( 200 );

		echo $this->render_page( $group_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the templates.
		exit;
	}

	/**
	 * Full HTML of a group's page.
	 *
	 * @param int $group_id Group ID.
	 */
	public function render_page( int $group_id ): string {
		$this->noindex = true;
		$group         = DFXPRAY_Groups::get_instance()->get( $group_id );
		$form          = DFXPRAY_Form::get_instance()->render( $group_id );

		return DFXPRAY_Templates::get_instance()->render(
			'form-page.php',
			array(
				'group' => $group,
				'form'  => $form,
			)
		);
	}

	/**
	 * Robots directives for the page.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public function robots( array $robots ): array {
		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );

		return array_merge(
			array(
				'noindex'  => true,
				'nofollow' => true,
			),
			$robots
		);
	}

	/**
	 * Filter for wp_robots: only on our pages.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public function filter_robots( $robots ) {
		return $this->noindex ? $this->robots( (array) $robots ) : $robots;
	}

	/**
	 * Keep page caches away: each form carries a nonce that expires, and a
	 * cached copy would soon refuse every request.
	 *
	 * DONOTCACHEPAGE is honoured by WP Super Cache, W3 Total Cache, WP Rocket,
	 * LiteSpeed Cache, WP Fastest Cache and others; the headers cover proxies,
	 * CDNs and server caches.
	 */
	public function prevent_caching(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by cache plugins.
		}

		$this->send_headers();
	}

	/**
	 * HTTP headers for the page.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return array_merge(
			wp_get_nocache_headers(),
			array(
				'X-Robots-Tag'              => 'noindex, nofollow',
				'Cache-Control'             => 'no-cache, must-revalidate, max-age=0, no-store, private',
				'X-LiteSpeed-Cache-Control' => 'no-cache',
			)
		);
	}

	/**
	 * Whether a post embeds the form (shortcode or block).
	 *
	 * @param WP_Post|null $post Post.
	 */
	public function post_has_form( $post ): bool {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'dfxpray_form' ) || has_block( 'dfxpray/form', $post );
	}

	/**
	 * Send the headers.
	 */
	private function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( $this->headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}
}
