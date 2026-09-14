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

		return self::as_page_element( $html, (string) $this->pages->uuid( $page_id ) );
	}

	/**
	 * The page's outermost element, carrying the class and the scope id.
	 *
	 * Writing <main> around a page is the natural thing to do and the
	 * allowlist permits it, so templates do. Wrapping that in another <main>
	 * gives a document two main landmarks: invalid HTML, and a screen reader
	 * offering a choice of two "main" regions. Nothing looks broken, so nobody
	 * finds it.
	 *
	 * When the template already provides the element, it becomes the page
	 * element instead of being nested inside a second one. Its own classes are
	 * kept, because the page's CSS is written against them.
	 */
	public static function as_page_element( string $html, string $uuid ): string {
		$trimmed = trim( $html );

		if ( preg_match( '/^<main\b([^>]*)>/i', $trimmed, $open )
			&& 1 === preg_match_all( '/<main\b/i', $trimmed )
			&& str_ends_with( strtolower( $trimmed ), '</main>' ) ) {

			$attributes = (string) $open[1];

			if ( preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $class ) ) {
				$attributes = str_replace(
					$class[0],
					sprintf( 'class="aiwp-page %s"', esc_attr( trim( (string) $class[2] ) ) ),
					$attributes
				);
			} else {
				$attributes .= ' class="aiwp-page"';
			}

			return sprintf(
				'<main%s data-aiwp-page="%s">%s',
				rtrim( $attributes ),
				esc_attr( $uuid ),
				substr( $trimmed, strlen( $open[0] ) )
			);
		}

		return sprintf(
			'<main class="aiwp-page" data-aiwp-page="%s">%s</main>',
			esc_attr( $uuid ),
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
