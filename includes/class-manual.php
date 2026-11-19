<?php
/**
 * The user manual, rendered inside the panel.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders docs/manual/<lang>/README.md as HTML for the panel's «Manual»
 * screen. It understands exactly the Markdown the manual uses: ATX headings
 * (with ids, and a table of contents of the ## ones), paragraphs, bold,
 * italic, code spans and fenced code, links, images (Markdown, and HTML img
 * tags with src, alt and width only), ordered and unordered lists with
 * nested items, pipe tables, blockquotes and horizontal rules. Everything
 * else is escaped, relative image paths point at the plugin's copy of the
 * manual, links to the manual in another language (../<lang>/README.md)
 * open that manual in the panel, and the result goes through wp_kses.
 * Nothing is cached.
 */
final class DFXPRAY_Manual {

	use DFXPRAY_Singleton;

	/**
	 * Language used when no better one exists.
	 */
	private const FALLBACK = 'es';

	/**
	 * Placeholder delimiter for inline tokens; never in the manual's text.
	 */
	private const MARK = "\x1A";

	/**
	 * Inline tokens of the text being rendered.
	 *
	 * @var string[]
	 */
	private array $tokens = array();

	/**
	 * Heading ids used so far.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * The ## headings: id => text (HTML).
	 *
	 * @var array<string, string>
	 */
	private array $toc = array();

	/**
	 * Base URL of relative images.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * URL of the manual in each language: language => URL.
	 *
	 * @var array<string, string>
	 */
	private array $manuals = array();

	/**
	 * Languages the manual is written in.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		$languages = array();
		foreach ( (array) glob( DFXPRAY_PLUGIN_DIR . 'docs/manual/*/README.md' ) as $file ) {
			$languages[] = basename( dirname( (string) $file ) );
		}

		return $languages;
	}

	/**
	 * The language of the manual for a locale: Spanish for any Spanish
	 * locale, English for the others when it exists, Spanish otherwise.
	 *
	 * @param string        $locale    Locale, e.g. es_ES.
	 * @param string[]|null $available Languages available; those shipped by default.
	 */
	public function language_for( string $locale, ?array $available = null ): string {
		$available = $available ?? $this->languages();

		if ( str_starts_with( strtolower( $locale ), 'es' ) ) {
			return self::FALLBACK;
		}

		return in_array( 'en', $available, true ) ? 'en' : self::FALLBACK;
	}

	/**
	 * The manual for a locale, as HTML.
	 *
	 * @param string $locale   Locale.
	 * @param string $language Language asked for (e.g. from a link to the
	 *                         other language); ignored if it does not exist.
	 */
	public function page( string $locale, string $language = '' ): string {
		$available = $this->languages();
		$language  = in_array( $language, $available, true ) ? $language : $this->language_for( $locale, $available );
		$file      = DFXPRAY_PLUGIN_DIR . 'docs/manual/' . $language . '/README.md';

		if ( ! is_readable( $file ) ) {
			return '';
		}

		$manuals = array();
		foreach ( $available as $other ) {
			$manuals[ $other ] = add_query_arg(
				array(
					'page' => 'dfxpray-manual',
					'lang' => $other,
				),
				admin_url( 'admin.php' )
			);
		}

		return $this->render( (string) file_get_contents( $file ), DFXPRAY_PLUGIN_URL . 'docs/manual/' . $language . '/', $manuals ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin.
	}

	/**
	 * Render Markdown.
	 *
	 * @param string                $markdown Markdown.
	 * @param string                $base     URL that relative image paths are relative to.
	 * @param array<string, string> $manuals  URL of the manual in each language, for
	 *                                        links to ../<lang>/README.md.
	 */
	public function render( string $markdown, string $base, array $manuals = array() ): string {
		$this->base    = trailingslashit( $base );
		$this->manuals = $manuals;
		$this->tokens  = array();
		$this->ids     = array();
		$this->toc     = array();

		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );
		$html  = $this->blocks( $lines );

		if ( $this->toc ) {
			$items = '';
			foreach ( $this->toc as $id => $text ) {
				$items .= '<li><a href="#' . esc_attr( $id ) . '">' . $text . '</a></li>';
			}
			$toc = '<nav class="dfxpray-manual-toc" aria-label="' . esc_attr__( 'Contents', 'dfx-prayer-intentions' ) . '"><ul>' . $items . '</ul></nav>';

			// Right after the title when there is one, otherwise at the top.
			$end  = strpos( $html, '</h1>' );
			$html = false === $end ? $toc . $html : substr( $html, 0, $end + 5 ) . $toc . substr( $html, $end + 5 );
		}

		return wp_kses( $html, $this->allowed_html() );
	}

	/**
	 * Render a sequence of block lines.
	 *
	 * @param string[] $lines Lines.
	 */
	private function blocks( array $lines ): string {
		$html      = '';
		$paragraph = array();
		$count     = count( $lines );
		$i         = 0;
		$flush     = function () use ( &$paragraph, &$html ): void {
			if ( $paragraph ) {
				$html     .= '<p>' . $this->inline( implode( ' ', array_map( 'trim', $paragraph ) ) ) . '</p>';
				$paragraph = array();
			}
		};

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( '' === trim( $line ) ) {
				$flush();
				++$i;
				continue;
			}

			if ( preg_match( '/^\s*```/', $line ) ) {
				$flush();
				$code = array();
				++$i;
				while ( $i < $count && ! preg_match( '/^\s*```/', $lines[ $i ] ) ) {
					$code[] = $lines[ $i ];
					++$i;
				}
				$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
				++$i;
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m ) ) {
				$flush();
				$html .= $this->heading( strlen( $m[1] ), $m[2] );
				++$i;
				continue;
			}

			if ( preg_match( '/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line ) ) {
				$flush();
				$html .= '<hr>';
				++$i;
				continue;
			}

			if ( preg_match( '/^\s{0,3}>/', $line ) ) {
				$flush();
				$quote = array();
				while ( $i < $count && preg_match( '/^\s{0,3}>\s?(.*)$/', $lines[ $i ], $q ) ) {
					$quote[] = $q[1];
					++$i;
				}
				$html .= '<blockquote>' . $this->blocks( $quote ) . '</blockquote>';
				continue;
			}

			if ( str_starts_with( ltrim( $line ), '|' ) && isset( $lines[ $i + 1 ] ) && $this->is_table_separator( $lines[ $i + 1 ] ) ) {
				$flush();
				$rows = array( $line );
				$i   += 2;
				while ( $i < $count && str_starts_with( ltrim( $lines[ $i ] ), '|' ) ) {
					$rows[] = $lines[ $i ];
					++$i;
				}
				$html .= $this->table( $rows );
				continue;
			}

			$marker = $this->list_marker( $line );
			if ( $marker ) {
				$flush();
				$items = array();
				while ( $i < $count ) {
					$current = $lines[ $i ];
					$next    = $this->list_marker( $current );
					if ( '' === trim( $current ) ) {
						// A blank line goes on only into an indented line or
						// another item of the same kind of list.
						$after = $lines[ $i + 1 ] ?? '';
						$item  = $this->list_marker( $after );
						if ( preg_match( '/^\s{2,}\S/', $after ) || ( $item && $item[1] === $marker[1] ) ) {
							++$i;
							continue;
						}
						break;
					}
					if ( $items && ( $next ? ( $next[0] <= $marker[0] && $next[1] !== $marker[1] ) : ! preg_match( '/^\s{2,}\S/', $current ) ) ) {
						break;
					}
					$items[] = $current;
					++$i;
				}
				$html .= $this->list_html( $items );
				continue;
			}

			$paragraph[] = $line;
			++$i;
		}

		$flush();

		return $html;
	}

	/**
	 * A heading with a unique id; ## ones go to the table of contents.
	 *
	 * @param int    $level Level.
	 * @param string $text  Markdown text.
	 */
	private function heading( int $level, string $text ): string {
		$content = $this->inline( $text );
		$id      = sanitize_title( wp_strip_all_tags( $content ) );
		$id      = '' !== $id ? $id : 'section';

		if ( isset( $this->ids[ $id ] ) ) {
			++$this->ids[ $id ];
			$id .= '-' . $this->ids[ $id ];
		} else {
			$this->ids[ $id ] = 1;
		}

		if ( 2 === $level ) {
			$this->toc[ $id ] = $content;
		}

		return '<h' . $level . ' id="' . esc_attr( $id ) . '">' . $content . '</h' . $level . '>';
	}

	/**
	 * A list item marker at the start of a line: [indent, ordered, text].
	 *
	 * @param string $line Line.
	 * @return array{0:int, 1:bool, 2:string}|null
	 */
	private function list_marker( string $line ): ?array {
		if ( ! preg_match( '/^(\s*)([-*+]|\d+\.)\s+(.*)$/', $line, $m ) ) {
			return null;
		}

		return array( strlen( $m[1] ), ctype_digit( rtrim( $m[2], '.' ) ), $m[3] );
	}

	/**
	 * A list, with the items nested deeper than its first one inside them.
	 *
	 * @param string[] $lines Lines of the list.
	 */
	private function list_html( array $lines ): string {
		$first = $this->list_marker( $lines[0] );
		$base  = $first[0];
		$items = array();

		foreach ( $lines as $line ) {
			$marker = $this->list_marker( $line );
			if ( $marker && $marker[0] <= $base ) {
				$items[] = array(
					'text'     => $marker[2],
					'children' => array(),
				);
				continue;
			}

			$last = count( $items ) - 1;
			if ( ! $marker && ! $items[ $last ]['children'] ) {
				$items[ $last ]['text'] .= ' ' . trim( $line );
			} else {
				$items[ $last ]['children'][] = $line;
			}
		}

		$tag  = $first[1] ? 'ol' : 'ul';
		$html = '<' . $tag . '>';
		foreach ( $items as $item ) {
			$html .= '<li>' . $this->inline( trim( $item['text'] ) );
			if ( $item['children'] ) {
				$html .= $this->list_marker( $item['children'][0] )
					? $this->list_html( $item['children'] )
					: $this->blocks( array_map( 'trim', $item['children'] ) );
			}
			$html .= '</li>';
		}

		return $html . '</' . $tag . '>';
	}

	/**
	 * Whether a line is the separator under a table's header.
	 *
	 * @param string $line Line.
	 */
	private function is_table_separator( string $line ): bool {
		return (bool) preg_match( '/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $line );
	}

	/**
	 * A pipe table: the first row is the header.
	 *
	 * @param string[] $rows Rows, without the separator.
	 */
	private function table( array $rows ): string {
		$cells = static fn( string $row ): array => array_map( 'trim', explode( '|', trim( trim( $row ), '|' ) ) );
		$html  = '<table class="widefat striped"><thead><tr>';

		foreach ( $cells( array_shift( $rows ) ) as $cell ) {
			$html .= '<th>' . $this->inline( $cell ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $cells( $row ) as $cell ) {
				$html .= '<td>' . $this->inline( $cell ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Inline Markdown: code, images, links, bold and italic; the rest escaped.
	 *
	 * @param string $text Text.
	 */
	private function inline( string $text ): string {
		$text = str_replace( self::MARK, '', $text );

		$text = (string) preg_replace_callback( '/`([^`]+)`/', fn( $m ) => $this->token( '<code>' . esc_html( $m[1] ) . '</code>' ), $text );
		$text = (string) preg_replace_callback( '/<img\s([^>]*?)\/?>/i', fn( $m ) => $this->token( $this->html_image( $m[1] ) ), $text );
		$text = (string) preg_replace_callback( '/!\[([^\]]*)\]\(([^)\s]+)\)/', fn( $m ) => $this->token( $this->image( $m[2], $m[1], 0 ) ), $text );
		$text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				$url = esc_url( $m[2], array( 'http', 'https', 'mailto' ) );
				if ( str_starts_with( $m[2], '#' ) ) {
					$url = '#' . sanitize_title( substr( $m[2], 1 ) );
				} elseif ( preg_match( '#^\.\./([a-z]{2})/README\.md$#', $m[2], $language ) ) {
					$url = esc_url( $this->manuals[ $language[1] ] ?? '' );
				}
				$label = $this->emphasis( esc_html( $m[1] ) );

				return $this->token( '' !== $url ? '<a href="' . esc_attr( $url ) . '">' . $label . '</a>' : $label );
			},
			$text
		);

		$text = $this->emphasis( esc_html( $text ) );

		return (string) preg_replace_callback(
			'/' . self::MARK . '(\d+)' . self::MARK . '/',
			fn( $m ) => $this->tokens[ (int) $m[1] ] ?? '',
			$text
		);
	}

	/**
	 * Bold and italic, on escaped text.
	 *
	 * @param string $text Escaped text.
	 */
	private function emphasis( string $text ): string {
		$text = (string) preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text );

		return (string) preg_replace( '/(?<![\*\w])\*(?=\S)(.+?)(?<=\S)\*(?![\*\w])/', '<em>$1</em>', $text );
	}

	/**
	 * Keep a piece of rendered HTML out of the escaping.
	 *
	 * @param string $html HTML.
	 */
	private function token( string $html ): string {
		$this->tokens[] = $html;

		return self::MARK . ( count( $this->tokens ) - 1 ) . self::MARK;
	}

	/**
	 * An HTML img tag of the manual: only src, alt and width survive.
	 *
	 * @param string $attributes Attributes as written.
	 */
	private function html_image( string $attributes ): string {
		preg_match_all( '/([a-z]+)\s*=\s*"([^"]*)"/i', $attributes, $pairs, PREG_SET_ORDER );
		$found = array();
		foreach ( $pairs as $pair ) {
			$found[ strtolower( $pair[1] ) ] = html_entity_decode( $pair[2], ENT_QUOTES, 'UTF-8' );
		}

		return $this->image( $found['src'] ?? '', $found['alt'] ?? '', (int) ( $found['width'] ?? 0 ) );
	}

	/**
	 * An image; relative paths point at the plugin's copy of the manual.
	 *
	 * @param string $src   Source as written.
	 * @param string $alt   Alternative text.
	 * @param int    $width Width, or 0.
	 */
	private function image( string $src, string $alt, int $width ): string {
		if ( '' !== $src && ! preg_match( '#^([a-z][a-z0-9+.-]*:|/|\#)#i', $src ) ) {
			$src = $this->base . ltrim( $src, './' );
		}
		$src = esc_url( $src, array( 'http', 'https' ) );

		if ( '' === $src ) {
			return esc_html( $alt );
		}

		return '<img src="' . esc_attr( $src ) . '" alt="' . esc_attr( $alt ) . '"' . ( $width > 0 ? ' width="' . $width . '"' : '' ) . ' loading="lazy">';
	}

	/**
	 * Tags and attributes the renderer produces, and nothing else.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_html(): array {
		$plain   = array();
		$heading = array( 'id' => true );
		$allowed = array(
			'h1'         => $heading,
			'h2'         => $heading,
			'h3'         => $heading,
			'h4'         => $heading,
			'h5'         => $heading,
			'h6'         => $heading,
			'nav'        => array(
				'class'      => true,
				'aria-label' => true,
			),
			'a'          => array( 'href' => true ),
			'img'        => array(
				'src'     => true,
				'alt'     => true,
				'width'   => true,
				'loading' => true,
			),
			'table'      => array( 'class' => true ),
			'blockquote' => $plain,
		);

		foreach ( array( 'p', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li', 'thead', 'tbody', 'tr', 'th', 'td', 'hr' ) as $tag ) {
			$allowed[ $tag ] = $plain;
		}

		return $allowed;
	}
}
