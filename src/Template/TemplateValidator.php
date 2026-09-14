<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Security\Sanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Decides whether AI-supplied template markup may be stored and rendered.
 *
 * Two layers:
 *  1. cheap rejection of obviously hostile strings
 *  2. a real HTML parse, then an element + attribute allowlist walk
 */
final class TemplateValidator {

	public const ALLOWED_TAGS = array(
		'main', 'section', 'article', 'header', 'footer', 'nav', 'aside',
		'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p',
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		'a', 'button', 'img', 'picture', 'source', 'figure', 'figcaption',
		'blockquote', 'cite', 'strong', 'em', 'b', 'i', 'small', 'mark', 'br', 'hr',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
		'details', 'summary', 'time', 'address', 'hgroup', 'abbr', 'q', 'code', 'pre', 'kbd', 'samp',
		'sub', 'sup', 's', 'del', 'ins', 'wbr', 'data', 'output', 'progress', 'meter',
		'svg', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'g', 'defs',
		// Inside an <svg> these are the accessible name and description, and
		// check_html refuses them anywhere else. Blocking them outright made
		// every diagram on every site unreachable to a screen reader.
		'title', 'desc',
		'lineargradient', 'radialgradient', 'stop', 'use', 'symbol', 'text', 'tspan', 'clippath', 'mask',
	);

	public const ALLOWED_ATTRS = array(
		'class', 'id', 'href', 'src', 'srcset', 'sizes', 'alt', 'title', 'target', 'rel',
		'role', 'type', 'width', 'height', 'loading', 'decoding', 'datetime', 'colspan', 'rowspan',
		'open', 'media', 'itemprop', 'itemscope', 'itemtype',
		// tables and lists
		'scope', 'headers', 'abbr', 'span', 'start', 'reversed', 'value',
		// generic document semantics
		'lang', 'dir', 'hidden', 'download', 'hreflang', 'fetchpriority', 'translate', 'inert',
		'max', 'min', 'low', 'high', 'optimum', 'cite', 'disabled',
		// inline SVG geometry
		'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
		'd', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'rx', 'ry',
		'xmlns', 'preserveaspectratio', 'aria-hidden', 'focusable', 'transform', 'opacity',
		'fill-rule', 'clip-rule', 'clip-path', 'fill-opacity', 'stroke-opacity', 'stroke-dasharray',
		'stroke-dashoffset', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
		'x2', 'y2', 'dx', 'dy', 'text-anchor', 'font-size', 'font-weight', 'font-family', 'mask',
	);

	/**
	 * Schemes a link may use. An allowlist, because a list of the bad ones is
	 * only ever as long as the last thing somebody thought of.
	 */
	public const URL_SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	public const FORBIDDEN_SUBSTRINGS = array(
		'<?php', '<?=', '<?', '?>',
		'<script', '</script', '<iframe', '<object', '<embed', '<applet',
		'<style', '<link', '<meta', '<base', '<form', '<input', '<textarea', '<select',
		'javascript:', 'vbscript:', 'data:text/html', '-moz-binding', 'expression(',
	);

	/** @var string[] */
	private array $errors = array();
	/** @var string[] */
	private array $warnings = array();

	/**
	 * @return array{valid:bool,errors:string[],warnings:string[],ast:?TemplateAST}
	 */
	public function validate( string $source, ?PageSchema $schema = null ): array {
		$this->errors   = array();
		$this->warnings = array();

		if ( '' === trim( $source ) ) {
			$this->errors[] = 'Template is empty.';
			return $this->result( null );
		}

		if ( Sanitizer::too_large( $source ) ) {
			$this->errors[] = sprintf( 'Template is larger than the %d byte limit.', Sanitizer::MAX_ARTIFACT_BYTES );
			return $this->result( null );
		}

		// Before anything reads the markup, make sure everything that reads it
		// reads the same thing. See check_control_characters().
		$this->check_control_characters( $source );

		$this->check_forbidden_strings( $source );
		$this->check_landmarks( $source );
		$this->check_event_handlers( $source );
		$this->check_unescaped_filter( $source );

		$parser = new TemplateParser();
		$ast    = $parser->parse( $source );
		foreach ( $parser->errors() as $error ) {
			$this->errors[] = $error;
		}

		$this->check_tag_balance( $source );
		$this->check_html( $source );

		if ( $schema instanceof PageSchema ) {
			$this->check_field_references( $ast, $schema, null );
		}

		return $this->result( $this->errors ? null : $ast );
	}

	/**
	 * post_body prints without escaping, so it is allowed on exactly one path.
	 *
	 * Without this, {{post_body:hero.text}} would print an ACF field raw and
	 * hand anyone who can edit a field a way to put script on the page.
	 */
	private function check_unescaped_filter( string $source ): void {
		if ( ! preg_match_all( '/\{\{\s*post_body\s*:\s*([A-Za-z0-9_.@]+)\s*\}\}/', $source, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $path ) {
			if ( 'post.body' !== $path ) {
				$this->errors[] = sprintf(
					'{{post_body:%s}} is not allowed. post_body prints without escaping and works only on post.body, the article body WordPress itself rendered. Use {{html:%s}} for rich text in a field.',
					$path,
					$path
				);
			}
		}
	}

	private function check_forbidden_strings( string $source ): void {
		$lower = strtolower( $source );
		foreach ( self::FORBIDDEN_SUBSTRINGS as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				$this->errors[] = sprintf( 'Template contains a forbidden construct: "%s".', $needle );
			}
		}
	}

	private function check_event_handlers( string $source ): void {
		if ( preg_match_all( '/\bon[a-z]+\s*=/i', $source, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $match ) {
				$this->errors[] = sprintf( 'Inline event handler "%s" is not allowed.', trim( $match ) );
			}
		}
	}

	/**
	 * Tags that never take a closing tag.
	 */
	private const VOID_TAGS = array(
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
		'link', 'meta', 'param', 'source', 'track', 'wbr',
	);

	/**
	 * Report tags that are left open, or closed without ever being opened.
	 *
	 * The HTML parser below quietly repairs broken markup, so a deleted
	 * </div> used to pass the check and then wreck the page layout. This is
	 * the check that actually notices, and it names the line.
	 */
	private function check_tag_balance( string $source ): void {
		$clean = $this->blank_out( '/\{\{.*?\}\}/s', $source );
		$clean = $this->blank_out( '/<!--.*?-->/s', $clean );

		$stack = array();

		if ( ! preg_match_all( '/<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/s', $clean, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return;
		}

		foreach ( $matches as $match ) {
			$closing = '' !== $match[1][0];
			$tag     = strtolower( $match[2][0] );
			$line    = 1 + substr_count( substr( $clean, 0, (int) $match[0][1] ), "\n" );

			if ( in_array( $tag, self::VOID_TAGS, true ) ) {
				continue;
			}

			if ( ! $closing ) {
				if ( '/' !== substr( rtrim( $match[3][0] ), -1 ) ) {
					$stack[] = array( $tag, $line );
				}
				continue;
			}

			$depth = -1;
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ][0] === $tag ) {
					$depth = $i;
					break;
				}
			}

			if ( $depth < 0 ) {
				$this->errors[] = sprintf( 'Closing tag </%s> on line %d was never opened.', $tag, $line );
				continue;
			}

			// Anything still open inside it was left open.
			while ( count( $stack ) - 1 > $depth ) {
				$lost           = array_pop( $stack );
				$this->errors[] = sprintf(
					'<%s> opened on line %d is never closed (line %d closes </%s>).',
					$lost[0],
					$lost[1],
					$line,
					$tag
				);
			}

			array_pop( $stack );
		}

		foreach ( array_reverse( $stack ) as $open ) {
			$this->errors[] = sprintf( '<%s> opened on line %d is never closed.', $open[0], $open[1] );
		}
	}

	/**
	 * Replace every match with spaces, keeping the newlines, so line numbers
	 * found afterwards still point at the right line.
	 */
	private function blank_out( string $pattern, string $subject ): string {
		return (string) preg_replace_callback(
			$pattern,
			static function ( array $m ): string {
				return str_repeat( ' ', strlen( $m[0] ) - substr_count( $m[0], "\n" ) ) . str_repeat( "\n", substr_count( $m[0], "\n" ) );
			},
			$subject
		);
	}

	/**
	 * Parse the markup for real, with directives swapped for inert placeholders,
	 * then check every element and attribute against the allowlist.
	 */
	private function check_html( string $source ): void {
		$stripped = preg_replace( '/\{\{.*?\}\}/s', 'x', $source ) ?? $source;

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="aiwp-root">' . $stripped . '</div>',
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );
		$root  = $doc->getElementById( 'aiwp-root' );
		if ( ! $root instanceof DOMElement ) {
			$nodes = $xpath->query( '//div[@id="aiwp-root"]' );
			$root  = ( $nodes && $nodes->length ) ? $nodes->item( 0 ) : null;
		}
		if ( ! $root instanceof DOMElement ) {
			$this->errors[] = 'Template markup could not be parsed as HTML.';
			return;
		}

		$ids = array();
		$this->walk( $root, $ids );

		foreach ( $ids as $id => $count ) {
			if ( $count > 1 ) {
				$this->warnings[] = sprintf( 'Duplicate id "%s" appears %d times.', $id, $count );
			}
		}
	}

	/**
	 * @param array<string,int> $ids
	 */
	private function walk( DOMNode $node, array &$ids ): void {
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( ! in_array( $tag, self::ALLOWED_TAGS, true ) ) {
				$this->errors[] = sprintf( 'HTML element <%s> is not allowed.', $tag );
			}

			// <title> is a document element outside an svg and the accessible
			// name inside one. Only the second is anybody's business here.
			if ( in_array( $tag, array( 'title', 'desc' ), true ) && ! self::inside_svg( $child ) ) {
				$this->errors[] = sprintf(
					'<%s> belongs inside an <svg>, where it names the graphic. Outside one it is a document element and cannot go in a template.',
					$tag
				);
			}

			foreach ( iterator_to_array( $child->attributes ) as $attr ) {
				$name = strtolower( $attr->nodeName );

				$allowed = in_array( $name, self::ALLOWED_ATTRS, true )
					|| 0 === strpos( $name, 'aria-' )
					|| 0 === strpos( $name, 'data-aiwp-' );

				// tabindex="-1" only. A dialog has to be able to take focus —
				// the modal behavior focuses it when it holds nothing focusable,
				// and role="dialog" without it is a promise the keyboard cannot
				// keep. Any other value reorders the tab sequence of the whole
				// page, which is not a thing a template should be able to do.
				if ( 'tabindex' === $name ) {
					if ( '-1' !== trim( (string) $attr->nodeValue ) ) {
						$this->errors[] = sprintf(
							'tabindex on <%s> may only be "-1", which lets script move focus there. Any other value rewrites the tab order of the page.',
							$tag
						);
						continue;
					}

					$allowed = true;
				}

				if ( ! $allowed ) {
					$this->errors[] = sprintf( 'Attribute "%s" on <%s> is not allowed.', $name, $tag );
					continue;
				}

				if ( 'id' === $name ) {
					$ids[ $attr->nodeValue ] = ( $ids[ $attr->nodeValue ] ?? 0 ) + 1;
				}

				if ( in_array( $name, array( 'href', 'src' ), true ) ) {
					$this->check_url_attribute( $tag, $name, (string) $attr->nodeValue );
				}

				if ( 'data-aiwp-behavior' === $name ) {
					$this->check_behavior( (string) $attr->nodeValue );
				}

				if ( 'data-aiwp-menu' === $name ) {
					$this->check_menu( (string) $attr->nodeValue );
				}
			}

			if ( 'img' === $tag && ! $child->hasAttribute( 'alt' ) ) {
				$this->warnings[] = 'An <img> has no alt attribute.';
			}

			$this->walk( $child, $ids );
		}
	}

	/**
	 * Characters that make the validator and the browser read different markup.
	 *
	 * Everything here rests on one assumption: what this class inspects is what
	 * the visitor's browser will get. A null byte breaks that assumption. This
	 * class inspects the markup with DOMDocument, which truncates an attribute
	 * at the first null — so href="jav\0ascript:alert(1)" arrives here as
	 * href="jav" and looks harmless. The renderer prints the template as
	 * written, the browser throws the null away, and the link runs.
	 *
	 * No honest template needs one. Tab, newline and carriage return are
	 * ordinary formatting and stay allowed; the rest are refused outright,
	 * which closes the whole class of trick rather than one spelling of it.
	 */
	/**
	 * Landmarks the plugin already provides.
	 *
	 * Every page is rendered inside <main class="aiwp-page">. A template that
	 * opens with its own <main> — which is the natural thing to write, and
	 * which the allowlist permitted — produces two main landmarks in one
	 * document. That is invalid HTML, and a screen reader offers the visitor a
	 * choice of two "main" regions, neither of which is wrong. Nothing looks
	 * broken, so nobody finds it.
	 */
	private function check_landmarks( string $source ): void {
		if ( ! preg_match( '/<main\b/i', $source ) ) {
			return;
		}

		$this->warnings[] = 'The page is already rendered inside <main>, so a template does not need to open one. '
			. 'Yours is used as the page element rather than nested inside a second one, which is what you want, '
			. 'but a <div> says more plainly that the landmark is not yours to declare.';
	}

	private function check_control_characters( string $source ): void {
		if ( ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $source, $found, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$this->errors[] = sprintf(
			'The template contains a control character (0x%02X at byte %d). '
				. 'It is invisible, and it makes this check and the browser read different markup, so it is refused. '
				. 'Tabs and line breaks are fine.',
			ord( $found[0][0] ),
			(int) $found[0][1]
		);
	}

	/** Whether a node sits inside an svg. */
	private static function inside_svg( DOMElement $node ): bool {
		for ( $up = $node->parentNode; $up instanceof DOMElement; $up = $up->parentNode ) {
			if ( 'svg' === strtolower( $up->tagName ) ) {
				return true;
			}
		}

		return false;
	}

	private function check_url_attribute( string $tag, string $name, string $value ): void {
		$value = self::normalise_url( $value );

		if ( '' === $value || 'x' === $value ) {
			return; // placeholder from a directive
		}

		// No scheme at all: a relative path, a fragment, a query. Nothing to do.
		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $value, $found ) ) {
			return;
		}

		if ( ! in_array( strtolower( $found[1] ), self::URL_SCHEMES, true ) ) {
			$this->errors[] = sprintf(
				'Unsafe URL scheme "%s:" in the %s attribute of <%s>. Allowed: %s, or a path on this site.',
				strtolower( $found[1] ),
				$name,
				$tag,
				implode( ', ', self::URL_SCHEMES )
			);
		}
	}

	/**
	 * A URL as the browser will read it.
	 *
	 * A browser throws away tabs, newlines and other control characters before
	 * it works out the scheme, so "java&#9;script:alert(1)" runs. Anything that
	 * checks the scheme without doing the same is reading a different string
	 * from the one that will be followed.
	 */
	public static function normalise_url( string $value ): string {
		// C0 controls and DEL, plus the ordinary spaces around the outside.
		$stripped = preg_replace( '/[\x00-\x20\x7f]+/', '', $value );

		return trim( (string) $stripped );
	}

	private function check_menu( string $value ): void {
		if ( ! \AIWP\Designer\Chrome\MenuRenderer::is_location( trim( $value ) ) ) {
			$this->errors[] = sprintf(
				'Unknown menu location "%s". Available: %s.',
				$value,
				implode( ', ', array_keys( \AIWP\Designer\Chrome\MenuRenderer::LOCATIONS ) )
			);
		}
	}

	private function check_behavior( string $value ): void {
		$known = \AIWP\Designer\Rendering\AssetManager::BEHAVIORS;
		foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $behavior ) {
			if ( '' === $behavior ) {
				continue;
			}
			if ( ! in_array( $behavior, $known, true ) ) {
				$this->errors[] = sprintf( 'Unknown behavior "%s". Available: %s.', $behavior, implode( ', ', $known ) );
			}
		}
	}

	/**
	 * Every {{...}} must point at a field the schema actually defines.
	 */
	/** Field types that can actually hold markup. */
	private const RICH_TYPES = array( 'wysiwyg' );

	/**
	 * {{html:...}} on a field that stores plain text can never print markup.
	 *
	 * A textarea is sanitised to plain text on the way in, which is right, so
	 * asking for html back out silently renders the tags as nothing. It looks
	 * like the content was lost. This says so at save time instead.
	 */
	private function check_filter_matches_type( TemplateAST $node, PageSchema $schema, ?string $each_path ): void {
		if ( TemplateAST::INTERP !== $node->kind || 'html' !== $node->filter ) {
			return;
		}

		$path = $node->path;

		if ( 0 === strpos( $path, '@item' ) ) {
			if ( null === $each_path ) {
				return;
			}
			$sub   = substr( $path, 6 );
			$field = '' === $sub ? null : $schema->sub_field( $each_path, $sub );
		} else {
			$field = $schema->field( $path );
		}

		if ( null === $field || in_array( (string) $field['type'], self::RICH_TYPES, true ) ) {
			return;
		}

		$this->errors[] = sprintf(
			'{{html:%s}} will print nothing, because "%s" is a %s field and stores plain text with the tags removed. Use {{text:%s}}, or make the field type wysiwyg if it really needs markup (line %d).',
			$path,
			$path,
			(string) $field['type'],
			$path,
			$node->line
		);
	}

	private function check_field_references( TemplateAST $node, PageSchema $schema, ?string $each_path ): void {
		foreach ( $node->children as $child ) {
			$path = $child->path;

			if ( TemplateAST::EACH === $child->kind ) {
				if ( ! $schema->is_repeater( $path ) ) {
					$this->errors[] = sprintf( '{{#each:%s}} is not an aiwp_repeater field.', $path );
				}
				$this->check_field_references( $child, $schema, $path );
				continue;
			}

			if ( TemplateAST::TEXT === $child->kind ) {
				continue;
			}

			if ( 0 === strpos( $path, '@item' ) ) {
				if ( null === $each_path ) {
					$this->errors[] = sprintf( '"%s" used outside an {{#each}} block (line %d).', $path, $child->line );
					continue;
				}
				$sub = substr( $path, 6 ); // strip "@item."
				if ( '' !== $sub && ! $schema->has_subfield( $each_path, $sub ) ) {
					$this->errors[] = sprintf( 'Repeater "%s" has no subfield "%s" (line %d).', $each_path, $sub, $child->line );
				}
			} elseif ( ! $schema->has_field( $path ) ) {
				$this->errors[] = sprintf( 'Template references unknown field "%s" (line %d).', $path, $child->line );
			}

			$this->check_filter_matches_type( $child, $schema, $each_path );

			if ( $child->children ) {
				$this->check_field_references( $child, $schema, $each_path );
			}
		}
	}

	/**
	 * @return array{valid:bool,errors:string[],warnings:string[],ast:?TemplateAST}
	 */
	private function result( ?TemplateAST $ast ): array {
		return array(
			'valid'    => empty( $this->errors ),
			'errors'   => array_values( array_unique( $this->errors ) ),
			'warnings' => array_values( array_unique( $this->warnings ) ),
			'ast'      => $ast,
		);
	}
}
