<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Security\Sanitizer;

/**
 * Validates AI-written CSS and scopes page CSS to its own page.
 *
 * Scoping is done here rather than trusting the AI to remember: every page rule
 * is rewritten so it cannot reach outside [data-aiwp-page="UUID"].
 */
final class CSSValidator {

	public const FORBIDDEN = array(
		'@import', 'expression(', 'javascript:', 'vbscript:', '-moz-binding', 'behavior:', '</style', '<script',
	);

	/** Selectors that would leak out of the page no matter what follows. */
	public const GLOBAL_SELECTORS = array( '*', 'html', ':root', 'body' );

	/**
	 * Whether a selector may also match the scope element itself, rather than only
	 * its descendants. The shared header and footer need this: the element
	 * carrying the scope attribute is the one an author styles.
	 */
	private bool $match_root = false;

	/** @var string[] */
	private array $errors = array();
	/** @var string[] */
	private array $warnings = array();

	/**
	 * @return array{valid:bool,errors:string[],warnings:string[],css:string}
	 */
	public function validate( string $css, bool $is_global = false ): array {
		$this->errors   = array();
		$this->warnings = array();

		if ( Sanitizer::too_large( $css ) ) {
			$this->errors[] = sprintf( 'CSS is larger than the %d byte limit.', Sanitizer::MAX_ARTIFACT_BYTES );
			return $this->result( '' );
		}

		/*
		 * Control characters go before anything reads the CSS, for the same
		 * reason the template refuses them: they are invisible, and they let a
		 * value read one way here and another way in the browser.
		 */
		if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $css, $found, PREG_OFFSET_CAPTURE ) ) {
			$this->errors[] = sprintf(
				'CSS contains a control character (0x%02X at byte %d). It is invisible, and it makes this check '
					. 'and the browser read different CSS, so it is refused. Tabs and line breaks are fine.',
				ord( $found[0][0] ),
				(int) $found[0][1]
			);

			return $this->result( '' );
		}

		// Comments are removed before anything else looks at the CSS. A comment
		// holding a comma or a brace would otherwise be read as part of a selector
		// and corrupt the stylesheet, and a comment mentioning @import would be
		// rejected for no reason.
		$css = $this->strip_comments( $css );

		$lower = strtolower( $css );
		foreach ( self::FORBIDDEN as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				$this->errors[] = sprintf( 'CSS contains a forbidden construct: "%s".', $needle );
			}
		}

		$this->check_urls( $css );

		if ( ! $is_global ) {
			$this->warn_global_selectors( $css );
		}

		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			$this->errors[] = 'CSS braces are unbalanced.';
		}

		return $this->result( $css );
	}

	/**
	 * Validate, then rewrite every selector so it lives under the page scope.
	 *
	 * @return array{valid:bool,errors:string[],warnings:string[],css:string}
	 */
	public function validate_and_scope( string $css, string $page_uuid ): array {
		return $this->validate_and_scope_to( $css, '[data-aiwp-page="' . $page_uuid . '"]' );
	}

	/**
	 * Validate, then rewrite every selector so it lives under an arbitrary scope.
	 *
	 * @return array{valid:bool,errors:string[],warnings:string[],css:string}
	 */
	public function validate_and_scope_to( string $css, string $scope, bool $match_root = false ): array {
		$result = $this->validate( $css, false );
		if ( ! $result['valid'] ) {
			return $result;
		}

		$this->match_root = $match_root;

		// validate() returns the comment-stripped CSS; scope that, not the original.
		$result['css'] = $this->scope_css( $result['css'], $scope );

		return $result;
	}

	/**
	 * Lay minified CSS back out so a person can read and edit it.
	 *
	 * Scoped CSS is stored without line breaks, because that is the file the
	 * browser downloads. When the version predates authored CSS being kept
	 * separately, this is what the editor shows instead of one enormous line.
	 */
	public static function format( string $css ): string {
		$css = trim( $css );

		if ( '' === $css || false !== strpos( $css, "\n" ) && substr_count( $css, "\n" ) > substr_count( $css, '}' ) / 2 ) {
			// Already laid out by hand; leave it exactly as it is.
			return $css;
		}

		$out    = '';
		$indent = 0;
		$quote  = '';
		$length = strlen( $css );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				$out .= $char;
				if ( '\\' === $char && $i + 1 < $length ) {
					$out .= $css[ ++$i ];
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				$out  .= $char;
				continue;
			}

			switch ( $char ) {
				case '{':
					$out .= ' {' . "\n" . str_repeat( '  ', ++$indent );
					break;

				case '}':
					$indent = max( 0, $indent - 1 );
					$out    = rtrim( $out ) . "\n" . str_repeat( '  ', $indent ) . '}' . "\n";
					if ( 0 === $indent ) {
						$out .= "\n";
					} else {
						$out .= str_repeat( '  ', $indent );
					}
					break;

				case ';':
					$out .= ';' . "\n" . str_repeat( '  ', $indent );
					break;

				case ',':
					// A comma between selectors starts a new line; one inside a value
					// does not.
					$out .= ( 0 === $indent ) ? ',' . "\n" : ', ';
					break;

				default:
					if ( ( "\n" === $char || ' ' === $char ) && ( '' === $out || in_array( substr( $out, -1 ), array( "\n", ' ' ), true ) ) ) {
						break;
					}
					$out .= $char;
			}
		}

		return trim( (string) preg_replace( '/\n{3,}/', "\n\n", $out ) ) . "\n";
	}

	/**
	 * Remove every comment, leaving text inside quotes alone.
	 *
	 * A comment becomes a space, and its newlines are kept, so a line number
	 * found in the stripped CSS still points at the right line of the original.
	 */
	public static function strip_comments( string $css ): string {
		$out    = '';
		$length = strlen( $css );
		$quote  = '';
		$i      = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '' !== $quote ) {
				$out .= $char;
				if ( '\\' === $char && $i + 1 < $length ) {
					$out .= $css[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				++$i;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				$out  .= $char;
				++$i;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$end     = strpos( $css, '*/', $i + 2 );
				$stop    = false === $end ? $length : $end + 2;
				$comment = substr( $css, $i, $stop - $i );
				$i       = $stop;
				$out    .= ' ' . str_repeat( "\n", substr_count( $comment, "\n" ) );
				continue;
			}

			$out .= $char;
			++$i;
		}

		return $out;
	}

	private function check_urls( string $css ): void {
		if ( ! preg_match_all( '/url\(\s*([^)]*)\s*\)/i', $css, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $raw ) {
			$url = trim( $raw, " \t\n\r\"'" );
			if ( '' === $url ) {
				continue;
			}
			// The browser drops whitespace inside a URL before it reads the
			// scheme, so "java<tab>script:" is javascript: to everyone but a
			// check that skips this step.
			$url = (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $url );
			if ( preg_match( '/^(javascript|vbscript):/i', $url ) ) {
				$this->errors[] = 'CSS url() uses an unsafe scheme.';
				continue;
			}
			if ( 0 === stripos( $url, 'data:' ) && ! preg_match( '#^data:image/(png|jpe?g|gif|webp|svg\+xml);#i', $url ) ) {
				$this->errors[] = 'CSS url() uses a data: URI that is not an image.';
				continue;
			}
			if ( preg_match( '#^(https?:)?//#i', $url ) ) {
				$this->warnings[] = sprintf( 'CSS loads a remote asset: %s', $url );
			}
		}
	}

	private function warn_global_selectors( string $css ): void {
		foreach ( $this->top_level_selectors( $css ) as $selector ) {
			$trimmed = trim( $selector );
			foreach ( self::GLOBAL_SELECTORS as $global ) {
				if ( $trimmed === $global || 0 === strpos( $trimmed, $global . ' ' ) ) {
					$this->warnings[] = sprintf( 'Selector "%s" would leak outside the page; it has been scoped.', $trimmed );
				}
			}
		}
	}

	/**
	 * @return string[]
	 */
	private function top_level_selectors( string $css ): array {
		$selectors = array();
		foreach ( $this->split_blocks( $css ) as $block ) {
			if ( 'rule' !== $block['kind'] ) {
				continue;
			}
			foreach ( $this->split_selector_list( $block['prelude'] ) as $selector ) {
				$selectors[] = $selector;
			}
		}
		return $selectors;
	}

	/**
	 * Break CSS into top-level at-rules and rule sets.
	 *
	 * @return array<int,array{kind:string,prelude:string,body:string,raw:string}>
	 */
	private function split_blocks( string $css ): array {
		$blocks  = array();
		$length  = strlen( $css );
		$i       = 0;
		$prelude = '';

		while ( $i < $length ) {
			$char = $css[ $i ];

			if ( '{' === $char ) {
				$depth = 1;
				$start = $i + 1;
				$j     = $start;
				while ( $j < $length && $depth > 0 ) {
					if ( '{' === $css[ $j ] ) {
						++$depth;
					} elseif ( '}' === $css[ $j ] ) {
						--$depth;
					}
					++$j;
				}
				$body = substr( $css, $start, max( 0, $j - $start - 1 ) );

				$trimmed_prelude = trim( $prelude );
				$kind            = 0 === strpos( $trimmed_prelude, '@' ) ? 'at' : 'rule';

				$blocks[] = array(
					'kind'    => $kind,
					'prelude' => $trimmed_prelude,
					'body'    => $body,
					'raw'     => $prelude . '{' . $body . '}',
				);

				$prelude = '';
				$i       = $j;
				continue;
			}

			if ( ';' === $char && 0 === strpos( trim( $prelude ), '@' ) ) {
				$blocks[] = array(
					'kind'    => 'statement',
					'prelude' => trim( $prelude ),
					'body'    => '',
					'raw'     => $prelude . ';',
				);
				$prelude  = '';
				++$i;
				continue;
			}

			$prelude .= $char;
			++$i;
		}

		return $blocks;
	}

	/**
	 * Split "a, b:is(c, d)" into ["a", "b:is(c, d)"].
	 *
	 * @return string[]
	 */
	private function split_selector_list( string $selectors ): array {
		$out   = array();
		$buf   = '';
		$depth = 0;

		for ( $i = 0, $len = strlen( $selectors ); $i < $len; $i++ ) {
			$char = $selectors[ $i ];
			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			}

			if ( ',' === $char && 0 === $depth ) {
				$out[] = trim( $buf );
				$buf   = '';
				continue;
			}
			$buf .= $char;
		}

		if ( '' !== trim( $buf ) ) {
			$out[] = trim( $buf );
		}

		return $out;
	}

	private function scope_css( string $css, string $scope ): string {
		$out = '';

		foreach ( $this->split_blocks( $css ) as $block ) {
			if ( 'statement' === $block['kind'] ) {
				// @import is already rejected; anything else harmless is kept.
				$out .= $block['raw'];
				continue;
			}

			if ( 'at' === $block['kind'] ) {
				$at = strtolower( strtok( ltrim( $block['prelude'] ), " \t(" ) ?: '' );

				// Bodies of these hold declarations or frames, not selectors.
				if ( in_array( $at, array( '@keyframes', '@-webkit-keyframes', '@font-face', '@page', '@property', '@counter-style' ), true ) ) {
					$out .= $block['prelude'] . '{' . $block['body'] . '}';
					continue;
				}

				// @media / @supports / @container hold nested rules.
				$out .= $block['prelude'] . '{' . $this->scope_css( $block['body'], $scope ) . '}';
				continue;
			}

			$scoped = array();
			foreach ( $this->split_selector_list( $block['prelude'] ) as $selector ) {
				foreach ( $this->scope_selector( $selector, $scope ) as $one ) {
					$scoped[] = $one;
				}
			}

			if ( array() === $scoped ) {
				continue;
			}

			$out .= implode( ',', $scoped ) . '{' . $block['body'] . '}';
		}

		return $out;
	}

	/**
	 * @return string[] One selector, or two when the scope element itself can match.
	 */
	private function scope_selector( string $selector, string $scope ): array {
		$selector = trim( $selector );

		if ( '' === $selector ) {
			return array();
		}

		// Already scoped.
		if ( false !== strpos( $selector, $scope ) ) {
			return array( $selector );
		}

		// Anything that targets the document root becomes the scope container itself.
		if ( in_array( $selector, array( '*', 'html', ':root', 'body', 'body *', 'html body', '.aiwp-page' ), true ) ) {
			return array( '*' === $selector || 'body *' === $selector ? $scope . ' *' : $scope );
		}

		// "body .thing" -> scope .thing
		$selector = preg_replace( '/^(html|body)\s+/i', '', $selector ) ?? $selector;

		$scoped = array( $scope . ' ' . $selector );

		// A class or attribute selector may describe the scope element itself. The
		// shared header and footer are styled that way, so emit both forms.
		if ( $this->match_root && preg_match( '/^[.\[]/', $selector ) ) {
			array_unshift( $scoped, $scope . $selector );
		}

		return $scoped;
	}

	/**
	 * @return array{valid:bool,errors:string[],warnings:string[],css:string}
	 */
	private function result( string $css ): array {
		return array(
			'valid'    => empty( $this->errors ),
			'errors'   => array_values( array_unique( $this->errors ) ),
			'warnings' => array_values( array_unique( $this->warnings ) ),
			'css'      => $css,
		);
	}
}
