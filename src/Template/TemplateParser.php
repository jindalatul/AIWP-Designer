<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * Turns .aiwp source into an AST.
 *
 * This is a real tokenizer plus a block stack. It is never `eval`ed and never
 * compiled to PHP.
 */
final class TemplateParser {

	public const FILTERS = array(
		'text', 'attr', 'url', 'html',
		'image_url', 'image_srcset', 'image_alt', 'image_width', 'image_height',
		// Prints WordPress's own the_content output for an article body.
		// TemplateValidator allows it on post.body and nowhere else, because it
		// is the one filter that does not escape.
		'post_body',
	);

	/** @var string[] */
	private array $errors = array();

	/**
	 * @return TemplateAST Root node (kind = text, children = document)
	 */
	public function parse( string $source ): TemplateAST {
		$this->errors = array();

		$root  = new TemplateAST( TemplateAST::TEXT );
		$stack = array( $root );

		$offset = 0;
		$length = strlen( $source );

		while ( $offset < $length ) {
			$open = strpos( $source, '{{', $offset );

			if ( false === $open ) {
				$this->append_text( $stack, substr( $source, $offset ) );
				break;
			}

			if ( $open > $offset ) {
				$this->append_text( $stack, substr( $source, $offset, $open - $offset ) );
			}

			$close = strpos( $source, '}}', $open );
			if ( false === $close ) {
				$this->errors[] = 'Unclosed "{{" at line ' . $this->line_at( $source, $open ) . '.';
				$this->append_text( $stack, substr( $source, $open ) );
				break;
			}

			$raw  = trim( substr( $source, $open + 2, $close - $open - 2 ) );
			$line = $this->line_at( $source, $open );
			$this->handle_directive( $stack, $raw, $line );

			$offset = $close + 2;
		}

		while ( count( $stack ) > 1 ) {
			$unclosed = array_pop( $stack );
			$this->errors[] = sprintf( 'Unclosed {{#%s:%s}} block (line %d).', $unclosed->kind, $unclosed->path, $unclosed->line );
		}

		return $root;
	}

	/**
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @param TemplateAST[] $stack
	 */
	private function handle_directive( array &$stack, string $raw, int $line ): void {
		$current = $stack[ count( $stack ) - 1 ];

		// Closing tags.
		if ( '/if' === $raw || '/each' === $raw ) {
			$expected = '/if' === $raw ? TemplateAST::IF_ : TemplateAST::EACH;
			if ( count( $stack ) < 2 || $current->kind !== $expected ) {
				$this->errors[] = sprintf( 'Unexpected {{%s}} on line %d.', $raw, $line );
				return;
			}
			array_pop( $stack );
			return;
		}

		// Opening blocks.
		if ( 0 === strpos( $raw, '#if:' ) || 0 === strpos( $raw, '#each:' ) ) {
			$is_if = 0 === strpos( $raw, '#if:' );
			$path  = substr( $raw, $is_if ? 4 : 6 );
			$path  = $this->normalise_path( $path, $line );
			if ( null === $path ) {
				return;
			}
			$node = TemplateAST::block( $is_if ? TemplateAST::IF_ : TemplateAST::EACH, $path, $line );
			$current->add( $node );
			$stack[] = $node;
			return;
		}

		// Interpolation.
		$parts = explode( ':', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			$this->errors[] = sprintf( 'Malformed directive "{{%s}}" on line %d.', $raw, $line );
			return;
		}

		$filter = strtolower( trim( $parts[0] ) );
		if ( ! in_array( $filter, self::FILTERS, true ) ) {
			$this->errors[] = sprintf( 'Unknown filter "%s" on line %d. Allowed: %s.', $filter, $line, implode( ', ', self::FILTERS ) );
			return;
		}

		$path = $this->normalise_path( trim( $parts[1] ), $line );
		if ( null === $path ) {
			return;
		}

		$current->add( TemplateAST::interp( $filter, $path, $line ) );
	}

	/**
	 * @param TemplateAST[] $stack
	 */
	private function append_text( array $stack, string $text ): void {
		if ( '' === $text ) {
			return;
		}
		$stack[ count( $stack ) - 1 ]->add( TemplateAST::text( $text ) );
	}

	private function normalise_path( string $path, int $line ): ?string {
		$path = trim( $path );

		// @item and @item.sub inside an each block.
		if ( ! preg_match( '/^@?[A-Za-z0-9_]+(\.[A-Za-z0-9_]+){0,2}$/', $path ) ) {
			$this->errors[] = sprintf( 'Invalid field path "%s" on line %d.', $path, $line );
			return null;
		}

		return $path;
	}

	private function line_at( string $source, int $offset ): int {
		return substr_count( substr( $source, 0, $offset ), "\n" ) + 1;
	}
}
