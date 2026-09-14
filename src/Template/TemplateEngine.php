<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * Walks a validated AST and produces HTML. No eval, no generated PHP.
 */
final class TemplateEngine {

	private const MAX_ROWS = 500;

	/**
	 * @param array<string,mixed> $content
	 * @param array<string,mixed> $options editor => bool, field_ids => array<string,string>
	 */
	public function render( TemplateAST $ast, array $content, array $options = array() ): string {
		$resolver = new FieldResolver( $content );
		return $this->render_nodes( $ast->children, $resolver, $options, null );
	}

	/**
	 * Parse + render in one step. Callers that already have an AST should use render().
	 *
	 * @param array<string,mixed> $content
	 */
	public function render_source( string $source, array $content, array $options = array() ): string {
		$parser = new TemplateParser();
		$ast    = $parser->parse( $source );
		return $this->render( $ast, $content, $options );
	}

	/**
	 * @param TemplateAST[]       $nodes
	 * @param array<string,mixed> $options
	 */
	private function render_nodes( array $nodes, FieldResolver $resolver, array $options, ?string $each_path ): string {
		$out = '';

		foreach ( $nodes as $node ) {
			switch ( $node->kind ) {
				case TemplateAST::TEXT:
					$out .= $node->value;
					break;

				case TemplateAST::INTERP:
					$out .= OutputEscaper::escape( $node->filter, $resolver->resolve( $node->path ) );
					break;

				case TemplateAST::IF_:
					if ( $resolver->is_truthy( $node->path ) ) {
						$out .= $this->render_nodes( $node->children, $resolver, $options, $each_path );
					}
					break;

				case TemplateAST::EACH:
					$rows = $resolver->rows( $node->path );
					$i    = 0;
					foreach ( $rows as $index => $row ) {
						if ( ++$i > self::MAX_ROWS ) {
							break;
						}
						$row_resolver = $resolver->with_row( $row );
						$row_html     = $this->render_nodes( $node->children, $row_resolver, $options, $node->path );

						if ( ! empty( $options['editor'] ) ) {
							$row_html = $this->mark_row( $row_html, $node->path, (int) $index );
						}

						$out .= $row_html;
					}
					break;
			}
		}

		return $out;
	}

	private function mark_row( string $html, string $path, int $index ): string {
		// Tag the first element of each row so the editor can address it.
		return preg_replace(
			'/<([a-z0-9]+)/i',
			'<$1 data-aiwp-repeater="' . esc_attr( $path ) . '" data-aiwp-row="' . $index . '"',
			$html,
			1
		) ?? $html;
	}
}
