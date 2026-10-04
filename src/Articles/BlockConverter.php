<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

/**
 * Turns clean article HTML into core block markup.
 *
 * Raw HTML saved in post_content opens in the block editor as one "Classic"
 * block, which nobody wants to edit. This writes each element the way the
 * block editor itself saves it, so a post made through MCP opens as ordinary
 * headings, paragraphs, lists, quotes, tables and images that a writer can
 * change by hand.
 *
 * The markup has to match what the editor's own save() produces, wrapper
 * classes included, or the editor shows "This block contains unexpected or
 * invalid content". Attributes are kept to the minimum for that reason.
 *
 * Needs no WordPress: images are resolved through a callable the caller passes
 * in, so this class runs in the unit tests as it is.
 */
final class BlockConverter {

	/** Inline tags kept inside a paragraph, heading, list item or cell. Everything else is unwrapped. */
	private const INLINE = array( 'a', 'strong', 'em', 'code', 'br', 'sup', 'sub', 's' );

	/** Inline tags written under another name, the way the editor stores them. */
	private const INLINE_ALIAS = array( 'b' => 'strong', 'i' => 'em', 'del' => 's', 'strike' => 's' );

	/** Containers that are unwrapped: their children are converted in place. */
	private const UNWRAP = array( 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'span', 'body', 'html', 'nav' );

	/** Dropped with everything inside. */
	private const DROP = array( 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link' );

	/** @var callable|null fn( string $src ): ?array{id:int,url:string} */
	private $image_for;

	/** @var string[] */
	private array $warnings = array();

	private int $blocks = 0;

	private bool $h1_seen = false;

	/**
	 * @param callable|null $image_for Finds a media-library image for an img src. Returns
	 *                                 array{id:int,url:string} or null when the image is not in the library.
	 */
	public function __construct( ?callable $image_for = null ) {
		$this->image_for = $image_for;
	}

	/**
	 * @return array{content:string,blocks:int,warnings:string[]}
	 */
	public function convert( string $html ): array {
		$this->warnings = array();
		$this->blocks   = 0;
		$this->h1_seen  = false;

		$html = trim( $html );
		if ( '' === $html ) {
			return array( 'content' => '', 'blocks' => 0, 'warnings' => array() );
		}

		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="aiwp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );

		$root = $doc->getElementById( 'aiwp-root' );
		$out  = null === $root ? array() : $this->children( $root );

		return array(
			'content'  => implode( "\n\n", $out ),
			'blocks'   => $this->blocks,
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	// ------------------------------------------------------------- blocks

	/**
	 * Convert the children of a container into a list of top-level blocks.
	 *
	 * @return string[]
	 */
	private function children( \DOMNode $node ): array {
		$out    = array();
		$inline = '';   // loose text and inline tags waiting to become one paragraph

		$flush = function () use ( &$inline, &$out ): void {
			if ( '' !== trim( strip_tags( $inline, '<br>' ) ) ) {
				$out[] = $this->paragraph( trim( $inline ) );
			}
			$inline = '';
		};

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$inline .= $this->text( $child->textContent );
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, self::INLINE, true ) || isset( self::INLINE_ALIAS[ $tag ] ) ) {
				$inline .= $this->inline_node( $child );
				continue;
			}

			$flush();

			if ( in_array( $tag, self::DROP, true ) ) {
				continue;
			}
			if ( in_array( $tag, self::UNWRAP, true ) ) {
				$out = array_merge( $out, $this->children( $child ) );
				continue;
			}

			$block = $this->block( $child, $tag );
			if ( null !== $block ) {
				$out[] = $block;
			}
		}

		$flush();

		return $out;
	}

	private function block( \DOMElement $el, string $tag ): ?string {
		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$level = (int) $m[1];
			if ( 1 === $level ) {
				// The article design prints the title as the h1.
				$level = 2;
				if ( ! $this->h1_seen ) {
					$this->warnings[] = 'An h1 in the body became an h2: the article design already prints the title as the h1.';
					$this->h1_seen    = true;
				}
			}
			return $this->heading( $level, $this->inline_children( $el ) );
		}

		switch ( $tag ) {
			case 'p':
				$imgs = $el->getElementsByTagName( 'img' );
				if ( 1 === $imgs->length && '' === trim( $el->textContent ) ) {
					return $this->image( $imgs->item( 0 ), '' );
				}
				$inner = trim( $this->inline_children( $el ) );
				return '' === trim( strip_tags( $inner, '<br>' ) ) ? null : $this->paragraph( $inner );

			case 'ul':
			case 'ol':
				return $this->list_block( $el, 'ol' === $tag );

			case 'blockquote':
				return $this->quote( $el );

			case 'table':
				return $this->table( $el );

			case 'img':
				return $this->image( $el, '' );

			case 'figure':
				return $this->figure( $el );

			case 'hr':
				++$this->blocks;
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'pre':
				++$this->blocks;
				return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . $this->text( $el->textContent ) . "</code></pre>\n<!-- /wp:code -->";
		}

		// Anything else (dl, details, address, …) keeps its words as a paragraph.
		$text = $this->words( $el );
		if ( '' === $text ) {
			return null;
		}
		$this->warnings[] = sprintf( 'A <%s> element has no matching block, so its text became a paragraph.', $tag );
		return $this->paragraph( $this->text( $text ) );
	}

	/** All the text inside an element, with a space between its parts. */
	private function words( \DOMNode $node ): string {
		$parts = array();
		foreach ( ( new \DOMXPath( $node->ownerDocument ) )->query( './/text()', $node ) as $t ) {
			$parts[] = $t->textContent;
		}
		return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
	}

	private function paragraph( string $inner ): string {
		++$this->blocks;
		return "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
	}

	private function heading( int $level, string $inner ): string {
		++$this->blocks;
		$attrs = 2 === $level ? '' : ' ' . $this->attrs( array( 'level' => $level ) );
		return '<!-- wp:heading' . $attrs . " -->\n<h{$level} class=\"wp-block-heading\">" . trim( $inner ) . "</h{$level}>\n<!-- /wp:heading -->";
	}

	private function list_block( \DOMElement $list, bool $ordered ): ?string {
		$items = array();
		foreach ( $list->childNodes as $li ) {
			if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) {
				continue;
			}
			$inline = '';
			$nested = array();
			foreach ( $li->childNodes as $c ) {
				$ctag = XML_ELEMENT_NODE === $c->nodeType ? strtolower( $c->nodeName ) : '';
				if ( 'ul' === $ctag || 'ol' === $ctag ) {
					$sub = $this->list_block( $c, 'ol' === $ctag );
					if ( null !== $sub ) {
						$nested[] = $sub;
					}
				} elseif ( 'p' === $ctag ) {
					$inline .= ( '' === $inline ? '' : ' ' ) . $this->inline_children( $c );
				} else {
					$inline .= $this->inline_node( $c );
				}
			}
			++$this->blocks;
			$items[] = "<!-- wp:list-item -->\n<li>" . trim( $inline ) . implode( '', $nested ) . "</li>\n<!-- /wp:list-item -->";
		}
		if ( array() === $items ) {
			return null;
		}

		++$this->blocks;
		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' ' . $this->attrs( array( 'ordered' => true ) ) : '';
		return '<!-- wp:list' . $attrs . " -->\n<{$tag} class=\"wp-block-list\">" . implode( "\n\n", $items ) . "</{$tag}>\n<!-- /wp:list -->";
	}

	private function quote( \DOMElement $el ): string {
		$cite  = '';
		$inner = array();
		$loose = '';
		foreach ( $el->childNodes as $c ) {
			$ctag = XML_ELEMENT_NODE === $c->nodeType ? strtolower( $c->nodeName ) : '';
			if ( 'cite' === $ctag || 'footer' === $ctag ) {
				$cite = trim( $this->inline_children( $c ) );
			} elseif ( 'p' === $ctag ) {
				$p = trim( $this->inline_children( $c ) );
				if ( '' !== $p ) {
					$inner[] = $this->paragraph( $p );
				}
			} else {
				$loose .= $this->inline_node( $c );
			}
		}
		if ( '' !== trim( strip_tags( $loose ) ) ) {
			$inner[] = $this->paragraph( trim( $loose ) );
		}

		++$this->blocks;
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . implode( "\n\n", $inner )
			. ( '' !== $cite ? '<cite>' . $cite . '</cite>' : '' ) . "</blockquote>\n<!-- /wp:quote -->";
	}

	private function table( \DOMElement $table, string $caption = '' ): ?string {
		$head = array();
		$body = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells   = array();
			$is_head = 'thead' === strtolower( $tr->parentNode->nodeName );
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
					continue;
				}
				$ctag = strtolower( $cell->nodeName );
				if ( 'th' !== $ctag && 'td' !== $ctag ) {
					continue;
				}
				$cells[] = "<{$ctag}>" . trim( $this->inline_children( $cell ) ) . "</{$ctag}>";
			}
			if ( array() === $cells ) {
				continue;
			}
			// A first row of only <th> outside a <thead> is still the header.
			if ( ! $is_head && array() === $head && array() === $body && ! preg_match( '/<td>/', implode( '', $cells ) ) ) {
				$is_head = true;
			}
			if ( $is_head ) {
				$head[] = '<tr>' . implode( '', $cells ) . '</tr>';
			} else {
				$body[] = '<tr>' . implode( '', $cells ) . '</tr>';
			}
		}
		if ( array() === $head && array() === $body ) {
			return null;
		}

		// Auto layout, not the editor's default fixed layout: with fixed layout every column
		// gets the same width and WordPress breaks words mid-word to fit ("Develop|ers").
		// Auto layout sizes columns to their content, and a wide table scrolls instead.
		$html = '<table>'
			. ( array() !== $head ? '<thead>' . implode( '', $head ) . '</thead>' : '' )
			. '<tbody>' . implode( '', $body ) . '</tbody></table>';

		++$this->blocks;
		return '<!-- wp:table ' . $this->attrs( array( 'hasFixedLayout' => false ) ) . " -->\n<figure class=\"wp-block-table\">" . $html
			. ( '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '' )
			. "</figure>\n<!-- /wp:table -->";
	}

	private function figure( \DOMElement $fig ): ?string {
		$caption = '';
		foreach ( $fig->getElementsByTagName( 'figcaption' ) as $fc ) {
			$caption = trim( $this->inline_children( $fc ) );
		}
		$table = $fig->getElementsByTagName( 'table' )->item( 0 );
		if ( null !== $table ) {
			return $this->table( $table, $caption );
		}
		$img = $fig->getElementsByTagName( 'img' )->item( 0 );
		if ( null !== $img ) {
			return $this->image( $img, $caption );
		}
		$text = trim( $fig->textContent );
		return '' === $text ? null : $this->paragraph( $this->text( $text ) );
	}

	private function image( \DOMElement $img, string $caption ): ?string {
		$src   = trim( $img->getAttribute( 'src' ) );
		$alt   = trim( $img->getAttribute( 'alt' ) );
		$found = ( '' !== $src && null !== $this->image_for ) ? call_user_func( $this->image_for, $src ) : null;

		if ( ! is_array( $found ) || empty( $found['id'] ) || empty( $found['url'] ) ) {
			$this->warnings[] = sprintf(
				'An image was left out because it is not in the media library (%s). Import it with media_import, then use the media library address.',
				'' === $src ? 'no src' : $src
			);
			return null;
		}

		$id = (int) $found['id'];
		++$this->blocks;
		return '<!-- wp:image ' . $this->attrs( array( 'id' => $id, 'sizeSlug' => 'large', 'linkDestination' => 'none' ) ) . " -->\n"
			. '<figure class="wp-block-image size-large"><img src="' . $this->attr( (string) $found['url'] ) . '" alt="' . $this->attr( $alt ) . '" class="wp-image-' . $id . '"/>'
			. ( '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '' )
			. "</figure>\n<!-- /wp:image -->";
	}

	// ------------------------------------------------------------- inline

	private function inline_children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $c ) {
			$out .= $this->inline_node( $c );
		}
		return preg_replace( '/\s+/u', ' ', $out );
	}

	private function inline_node( \DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return $this->text( $node->textContent );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );
		$tag = self::INLINE_ALIAS[ $tag ] ?? $tag;

		if ( in_array( $tag, self::DROP, true ) || 'img' === $tag ) {
			if ( 'img' === $tag ) {
				$this->warnings[] = 'An image inside text was left out. Put images on their own line so they become image blocks.';
			}
			return '';
		}
		if ( 'br' === $tag ) {
			return '<br>';
		}
		if ( ! in_array( $tag, self::INLINE, true ) ) {
			return $this->inline_children( $node );   // unwrap
		}

		$inner = $this->inline_children( $node );
		if ( 'a' === $tag ) {
			$href = trim( $node instanceof \DOMElement ? $node->getAttribute( 'href' ) : '' );
			if ( '' === $href || preg_match( '/^\s*(javascript|vbscript|data):/i', $href ) ) {
				return $inner;
			}
			return '<a href="' . $this->attr( $href ) . '">' . $inner . '</a>';
		}

		return "<{$tag}>" . $inner . "</{$tag}>";
	}

	private function text( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private function attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Block comment attributes, encoded the way the editor writes them.
	 *
	 * @param array<string,mixed> $attrs
	 */
	private function attrs( array $attrs ): string {
		$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// The editor escapes these so a comment can never be closed early.
		return str_replace( array( '--', '<', '>', '&' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026' ), $json );
	}
}
