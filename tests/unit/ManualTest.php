<?php
/**
 * Tests for the Markdown renderer of the user manual.
 *
 * @package DFXPRAY
 */

/**
 * Manual rendering.
 */
class ManualTest extends DFXPRAY_Test_Case {

	/**
	 * Base URL of the images in the tests.
	 */
	private const BASE = 'https://example.org/wp-content/plugins/dfx-prayer-intentions/docs/manual/es/';

	/**
	 * Renderer.
	 *
	 * @var DFXPRAY_Manual
	 */
	private DFXPRAY_Manual $manual;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->manual = DFXPRAY_Manual::get_instance();
	}

	/**
	 * Render Markdown with the test base URL.
	 *
	 * @param string $markdown Markdown.
	 */
	private function render( string $markdown ): string {
		return $this->manual->render( $markdown, self::BASE );
	}

	/**
	 * Headings get ids, and the ## ones make a table of contents at the top.
	 */
	public function test_headings_and_table_of_contents(): void {
		$html = $this->render( "# Manual\n\nIntro.\n\n## Qué es y para quién\n\n### Las piezas\n\n## Grupos, responsables y permisos\n\n## Qué es y para quién\n" );

		$this->assertStringContainsString( '<h1 id="manual">Manual</h1>', $html );
		$this->assertStringContainsString( '<h2 id="que-es-y-para-quien">Qué es y para quién</h2>', $html );
		$this->assertStringContainsString( '<h3 id="las-piezas">Las piezas</h3>', $html );
		$this->assertStringContainsString( '<h2 id="que-es-y-para-quien-2">', $html );
		$this->assertStringContainsString( '<nav class="dfxpray-manual-toc"', $html );
		$this->assertStringContainsString( '<a href="#grupos-responsables-y-permisos">Grupos, responsables y permisos</a>', $html );
		$this->assertStringNotContainsString( 'href="#las-piezas"', $html );
		$this->assertLessThan( strpos( $html, '<h2' ), strpos( $html, 'dfxpray-manual-toc' ) );
	}

	/**
	 * Paragraphs and inline formatting.
	 */
	public function test_paragraphs_and_inline(): void {
		$html = $this->render( "Una **negrita**, una *cursiva* y `wp-cron.php`,\nen dos líneas.\n\nOtro [enlace](https://davefx.com) y [ancla](#grupos)." );

		$this->assertStringContainsString( '<p>Una <strong>negrita</strong>, una <em>cursiva</em> y <code>wp-cron.php</code>, en dos líneas.</p>', $html );
		$this->assertStringContainsString( '<a href="https://davefx.com">enlace</a>', $html );
		$this->assertStringContainsString( '<a href="#grupos">ancla</a>', $html );
	}

	/**
	 * Code spans and fenced code keep their content literally, escaped.
	 */
	public function test_code(): void {
		$html = $this->render( "`POST /wp-json/dfxpray/v1/<operación>` y `**no**`\n\n```\n<b>tal cual</b> **no**\n  sangrado\n```\n" );

		$this->assertStringContainsString( '<code>POST /wp-json/dfxpray/v1/&lt;operación&gt;</code>', $html );
		$this->assertStringContainsString( '<code>**no**</code>', $html );
		$this->assertStringContainsString( "<pre><code>&lt;b&gt;tal cual&lt;/b&gt; **no**\n  sangrado</code></pre>", $html );
	}

	/**
	 * Ordered and unordered lists, with nested items.
	 */
	public function test_lists(): void {
		$html = $this->render( "- Uno\n- **Dos**\n\n1. Primero\n2. Segundo:\n   - a\n   - b\n3. Tercero\n    1. anidado con cuatro\n" );

		$this->assertStringContainsString( '<ul><li>Uno</li><li><strong>Dos</strong></li></ul>', $html );
		$this->assertStringContainsString( '<ol><li>Primero</li><li>Segundo:<ul><li>a</li><li>b</li></ul></li><li>Tercero<ol><li>anidado con cuatro</li></ol></li></ol>', $html );
	}

	/**
	 * Pipe tables, styled like WordPress tables.
	 */
	public function test_tables(): void {
		$html = $this->render( "| Pieza | Para qué |\n| --- | --- |\n| Formulario | Pedir **oración** |\n| App | Lo mismo |\n" );

		$this->assertStringContainsString( '<table class="widefat striped"><thead><tr><th>Pieza</th><th>Para qué</th></tr></thead><tbody><tr><td>Formulario</td><td>Pedir <strong>oración</strong></td></tr><tr><td>App</td><td>Lo mismo</td></tr></tbody></table>', $html );
	}

	/**
	 * Blockquotes and horizontal rules.
	 */
	public function test_blockquote_and_rule(): void {
		$html = $this->render( "> Ojo al **desinstalar**.\n> Sigue.\n\n---\n\nFin." );

		$this->assertStringContainsString( '<blockquote><p>Ojo al <strong>desinstalar</strong>. Sigue.</p></blockquote>', $html );
		$this->assertStringContainsString( '<hr>', $html );
		$this->assertStringContainsString( '<p>Fin.</p>', $html );
	}

	/**
	 * Images, Markdown and HTML, point at the plugin's copy of the manual.
	 */
	public function test_images(): void {
		$html = $this->render( "![La página «Grupos»](img/02-grupos.webp)\n\n<img src=\"img/01-formulario.webp\" alt=\"El formulario\" width=\"320\">\n<img src=\"img/20-app-hoy.webp\" alt=\"App\" width=\"320\">\n\n![remota](https://example.com/x.webp)" );

		$this->assertStringContainsString( '<img src="' . self::BASE . 'img/02-grupos.webp" alt="La página «Grupos»"', $html );
		$this->assertStringContainsString( '<img src="' . self::BASE . 'img/01-formulario.webp" alt="El formulario" width="320"', $html );
		$this->assertStringContainsString( self::BASE . 'img/20-app-hoy.webp', $html );
		$this->assertStringContainsString( 'src="https://example.com/x.webp"', $html );
	}

	/**
	 * Nothing executable survives: raw HTML is escaped, img tags keep only
	 * src, alt and width, and dangerous URLs are dropped.
	 */
	public function test_escaping(): void {
		$html = $this->render(
			"<script>alert(1)</script>\n\n"
			. "<img src=\"img/a.webp\" onerror=\"alert(2)\" alt=\"x\" width=\"10\" style=\"x\">\n\n"
			. "<img src=\"javascript:alert(3)\" alt=\"y\">\n\n"
			. "[clic](javascript:alert(4)) y ![i](javascript:alert(5))\n\n"
			. "# <em onclick=\"x\">Título</em>\n\n"
			. "| <b>c</b> |\n| --- |\n| <iframe src=x></iframe> |\n\n"
			. "- <a href=\"javascript:alert(6)\">x</a>\n"
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertDoesNotMatchRegularExpression( '/<[^>]+\son(error|click)=/i', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertDoesNotMatchRegularExpression( '/(href|src)="[^"]*javascript/i', $html );
		$this->assertStringContainsString( '&lt;a href=', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<em onclick', $html );
		$this->assertStringContainsString( '<img src="' . self::BASE . 'img/a.webp" alt="x" width="10"', $html );
	}

	/**
	 * The manual in the user's language: Spanish for any Spanish locale,
	 * English for the rest when it exists, Spanish otherwise.
	 */
	public function test_language_for_locale(): void {
		$this->assertSame( 'es', $this->manual->language_for( 'es_ES', array( 'es' ) ) );
		$this->assertSame( 'es', $this->manual->language_for( 'es_MX', array( 'es', 'en' ) ) );
		$this->assertSame( 'en', $this->manual->language_for( 'en_US', array( 'es', 'en' ) ) );
		$this->assertSame( 'en', $this->manual->language_for( 'fr_FR', array( 'es', 'en' ) ) );
		$this->assertSame( 'es', $this->manual->language_for( 'en_US', array( 'es' ) ) );
		$this->assertContains( 'es', $this->manual->languages() );
	}

	/**
	 * The shipped manual renders whole, with its images from the plugin.
	 */
	public function test_shipped_manual(): void {
		$html = $this->manual->page( 'es_ES' );

		$this->assertStringContainsString( '<h1 id="manual-de-dfx-prayer-intentions">', $html );
		$this->assertStringContainsString( 'dfxpray-manual-toc', $html );
		$this->assertStringContainsString( DFXPRAY_PLUGIN_URL . 'docs/manual/es/img/02-grupos.webp', $html );
		$this->assertStringContainsString( '<table class="widefat striped">', $html );
		$this->assertStringNotContainsString( '&lt;img', $html );
		$this->assertStringNotContainsString( 'src="img/', $html );
	}
}
