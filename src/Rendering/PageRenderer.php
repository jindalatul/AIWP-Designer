<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Rendering;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Forms\FormHandler;
use AIWP\Designer\Forms\FormRenderer;
use AIWP\Designer\Chrome\MenuRenderer;
use AIWP\Designer\Forms\FormSchema;
use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Template\TemplateEngine;
use AIWP\Designer\Template\TemplateParser;

/**
 * Renders an AIWP page from its stored template plus live ACF content.
 */
final class PageRenderer {

	private PageRepository $pages;
	private FieldValueManager $values;
	private FormRenderer $form_renderer;
	private ?MenuRenderer $menus;

	public function __construct( PageRepository $pages, FieldValueManager $values, ?FormRenderer $form_renderer = null, ?MenuRenderer $menus = null ) {
		$this->pages         = $pages;
		$this->values        = $values;
		$this->form_renderer = $form_renderer ?? new FormRenderer();
		$this->menus         = $menus;
	}

	/**
	 * @param array<string,mixed> $options editor => bool
	 */
	public function render( int $page_id, array $options = array() ): string {
		$schema = $this->pages->schema( $page_id );
		if ( ! $schema instanceof PageSchema ) {
			return $this->notice( 'This page has no AIWP schema yet.' );
		}

		$template = $this->pages->template( $page_id );
		if ( '' === $template ) {
			return $this->notice( 'This page has no AIWP template yet.' );
		}

		$content = $this->values->read( $page_id, $schema );
		$ast     = $this->ast( $page_id, $template );
		$engine  = new TemplateEngine();

		$html = $engine->render( $ast, $content, $options );

		if ( ! empty( $options['editor'] ) ) {
			$html = $this->add_editor_markers( $html );
		}

		// Forms are plugin-owned markup dropped into the placeholders the
		// template declared. Nothing about them comes from the AI template.
		if ( $this->menus instanceof MenuRenderer ) {
			$html = $this->menus->inject( $html );
		}

		$forms = FormSchema::from_array( (array) $this->pages->manifest( $page_id )->get( 'forms', array() ) );
		if ( ! $forms->is_empty() ) {
			$html = $this->form_renderer->inject( $html, $forms, $page_id, FormHandler::state() );
		}

		// Anything a person added that the template never prints. Without this
		// they fill in a field and nothing appears, which reads as a bug.
		if ( $schema->has_owner_sections() ) {
			$html .= ( new \AIWP\Designer\Pages\OwnerSectionRenderer() )
				->render( $schema->owner_sections(), $content, $template );
		}

		return sprintf(
			'<main class="aiwp-page" data-aiwp-page="%s">%s</main>',
			esc_attr( $this->pages->uuid( $page_id ) ),
			$html
		);
	}

	/**
	 * Parsed templates are cached; the cache key follows the stored file.
	 */
	private function ast( int $page_id, string $template ) {
		$key    = 'aiwp_ast_' . $page_id . '_' . substr( md5( $template ), 0, 12 );
		$cached = wp_cache_get( $key, 'aiwp' );

		if ( $cached instanceof \AIWP\Designer\Template\TemplateAST ) {
			return $cached;
		}

		$ast = ( new TemplateParser() )->parse( $template );
		wp_cache_set( $key, $ast, 'aiwp', 300 );

		return $ast;
	}

	/**
	 * Tag rendered elements so the editor can address fields. Frontend output
	 * never carries these attributes.
	 */
	private function add_editor_markers( string $html ): string {
		return $html;
	}

	private function notice( string $message ): string {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return '';
		}
		return '<div class="aiwp-notice" style="padding:2rem;background:#fff4e5;border:1px solid #f0b849;border-radius:8px;margin:2rem auto;max-width:60ch;">'
			. esc_html( $message )
			. '</div>';
	}
}
