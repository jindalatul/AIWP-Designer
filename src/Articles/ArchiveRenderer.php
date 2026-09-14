<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\Template\TemplateAST;
use AIWP\Designer\Template\TemplateEngine;
use AIWP\Designer\Template\TemplateParser;

/**
 * Renders whatever list WordPress is showing through the archive design.
 */
final class ArchiveRenderer {

	private ArchiveTemplate $template;

	public function __construct( ArchiveTemplate $template ) {
		$this->template = $template;
	}

	public function render(): string {
		$source = $this->template->template();
		if ( '' === trim( $source ) ) {
			return '';
		}

		$key = 'aiwp_archive_ast_' . substr( md5( $source ), 0, 12 );
		$ast = wp_cache_get( $key, 'aiwp' );

		if ( ! $ast instanceof TemplateAST ) {
			$ast = ( new TemplateParser() )->parse( $source );
			wp_cache_set( $key, $ast, 'aiwp', 300 );
		}

		$html = ( new TemplateEngine() )->render( $ast, array( 'archive' => ArchiveContent::current() ) );

		return sprintf( '<div class="aiwp-archive" data-aiwp-archive="1">%s</div>', $html );
	}
}
