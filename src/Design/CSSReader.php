<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

/**
 * Pulls declarations out of a stylesheet so they can be counted and compared.
 *
 * This is not a CSS engine. It does not resolve the cascade and it cannot tell
 * you what colour a paragraph ends up. It reads what was written: which
 * selectors exist, which properties they set, and what value each one got.
 * That is enough to see whether a stylesheet keeps to its own design system.
 */
final class CSSReader {

	/**
	 * One declaration as it was written.
	 *
	 * @var array<int,array{selector:string,property:string,value:string,media:string,line:int}>
	 */
	private array $declarations = array();

	/** @var array<int,array{selector:string,media:string,declarations:array<string,string>}> */
	private array $rules = array();

	private int $media_queries = 0;

	private int $keyframes = 0;

	public function __construct( string $css ) {
		$this->parse( CSSValidator::strip_comments( $css ) );
	}

	/**
	 * @return array<int,array{selector:string,property:string,value:string,media:string,line:int}>
	 */
	public function declarations(): array {
		return $this->declarations;
	}

	/**
	 * @return array<int,array{selector:string,media:string,declarations:array<string,string>}>
	 */
	public function rules(): array {
		return $this->rules;
	}

	public function media_queries(): int {
		return $this->media_queries;
	}

	public function keyframes(): int {
		return $this->keyframes;
	}

	/**
	 * Every value written for one property, in source order.
	 *
	 * @return string[]
	 */
	public function values_of( string $property ): array {
		$out = array();
		foreach ( $this->declarations as $declaration ) {
			if ( $declaration['property'] === $property ) {
				$out[] = $declaration['value'];
			}
		}
		return $out;
	}

	public function has_selector_containing( string $needle ): bool {
		foreach ( $this->rules as $rule ) {
			if ( false !== stripos( $rule['selector'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk the stylesheet, tracking nesting so at-rules do not become selectors.
	 */
	private function parse( string $css ): void {
		$length  = strlen( $css );
		$buffer  = '';
		$stack   = array();
		$media   = '';
		$line    = 1;
		$start   = 1;
		$quote   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( "\n" === $char ) {
				++$line;
			}

			if ( '' !== $quote ) {
				$buffer .= $char;
				if ( $char === $quote && ( $i === 0 || '\\' !== $css[ $i - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( '{' === $char ) {
				$head = trim( $buffer );
				$buffer = '';

				if ( 0 === strpos( $head, '@' ) ) {
					$stack[] = array( 'at', $head );
					if ( 0 === stripos( $head, '@media' ) || 0 === stripos( $head, '@supports' ) || 0 === stripos( $head, '@container' ) ) {
						++$this->media_queries;
						$media = $head;
					}
					if ( 0 === stripos( $head, '@keyframes' ) || false !== stripos( $head, 'keyframes' ) ) {
						++$this->keyframes;
					}
					continue;
				}

				$stack[] = array( 'rule', $head );
				$start   = $line;
				continue;
			}

			if ( '}' === $char ) {
				$frame = array_pop( $stack );

				if ( is_array( $frame ) && 'rule' === $frame[0] ) {
					$this->add_rule( (string) $frame[1], trim( $buffer ), $media, $start );
				}

				$buffer = '';

				if ( is_array( $frame ) && 'at' === $frame[0] && '' !== $media && $frame[1] === $media ) {
					$media = '';
				}

				continue;
			}

			$buffer .= $char;
		}
	}

	private function add_rule( string $selector, string $body, string $media, int $line ): void {
		$selector = trim( preg_replace( '/\s+/', ' ', $selector ) ?? $selector );
		if ( '' === $selector || '' === $body ) {
			return;
		}

		$declarations = array();

		foreach ( $this->split_declarations( $body ) as $piece ) {
			$colon = strpos( $piece, ':' );
			if ( false === $colon ) {
				continue;
			}

			$property = strtolower( trim( substr( $piece, 0, $colon ) ) );
			$value    = trim( substr( $piece, $colon + 1 ) );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			$declarations[ $property ] = $value;

			$this->declarations[] = array(
				'selector' => $selector,
				'property' => $property,
				'value'    => $value,
				'media'    => $media,
				'line'     => $line,
			);
		}

		if ( array() !== $declarations ) {
			$this->rules[] = array(
				'selector'     => $selector,
				'media'        => $media,
				'declarations' => $declarations,
			);
		}
	}

	/**
	 * Split on semicolons that are not inside brackets or quotes, so
	 * `grid-template-areas` and `clamp(1rem, 2vw, 3rem)` stay whole.
	 *
	 * @return string[]
	 */
	private function split_declarations( string $body ): array {
		$out   = array();
		$piece = '';
		$depth = 0;
		$quote = '';

		for ( $i = 0, $n = strlen( $body ); $i < $n; $i++ ) {
			$char = $body[ $i ];

			if ( '' !== $quote ) {
				$piece .= $char;
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote  = $char;
				$piece .= $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( ';' === $char && 0 === $depth ) {
				$out[] = $piece;
				$piece = '';
				continue;
			}

			$piece .= $char;
		}

		if ( '' !== trim( $piece ) ) {
			$out[] = $piece;
		}

		return $out;
	}
}
