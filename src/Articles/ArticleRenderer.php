<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Template\TemplateEngine;
use AIWP\Designer\Template\TemplateParser;
use AIWP\Designer\Template\TemplateAST;

/**
 * Renders one post through the article design.
 */
final class ArticleRenderer {

	private ArticleTemplate $template;

	private FieldValueManager $values;

	public function __construct( ArticleTemplate $template, FieldValueManager $values ) {
		$this->template = $template;
		$this->values   = $values;
	}

	public function render( \WP_Post $post ): string {
		$source = $this->template->template();
		if ( '' === trim( $source ) ) {
			return '';
		}

		$key = 'aiwp_article_ast_' . $this->template->kind() . '_' . substr( md5( $source ), 0, 12 );
		$ast = wp_cache_get( $key, 'aiwp' );

		if ( ! $ast instanceof TemplateAST ) {
			$ast = ( new TemplateParser() )->parse( $source );
			wp_cache_set( $key, $ast, 'aiwp', 300 );
		}

		$content = array( 'post' => ArticleContent::for_post( $post ) );

		$schema = $this->template->schema();
		if ( null !== $schema ) {
			$content = array_merge(
				$this->values->read( $post->ID, $schema, $this->template->field_uuid() ),
				$content
			);
		}

		$html = ( new TemplateEngine() )->render( $ast, $content );

		return sprintf(
			'<article class="aiwp-article" data-aiwp-article="%s">%s</article>',
			esc_attr( $this->template->kind() ),
			$html
		);
	}
}
