<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Performance;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Rendering\PageRenderer;
use DOMDocument;
use DOMXPath;

/**
 * Deterministic page checks. No headless browser, no hosted service.
 */
final class StaticAuditor {

	private PageRepository $pages;
	private PageRenderer $renderer;

	public function __construct( PageRepository $pages, PageRenderer $renderer ) {
		$this->pages    = $pages;
		$this->renderer = $renderer;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function audit( int $page_id ): array {
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return array(
				'status' => 'error',
				'error'  => 'That page is not an AIWP page.',
			);
		}

		$html = $this->renderer->render( $page_id );
		$css  = $this->pages->css( $page_id );

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );

		$elements    = $xpath->query( '//*' );
		$images      = $xpath->query( '//img' );
		$headings    = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		$behaviors   = $xpath->query( '//*[@data-aiwp-behavior]' );
		$missing_alt = 0;
		$missing_dim = 0;
		$oversized   = 0;

		foreach ( $images as $image ) {
			if ( ! $image->hasAttribute( 'alt' ) ) {
				++$missing_alt;
			}
			if ( ! $image->hasAttribute( 'width' ) || ! $image->hasAttribute( 'height' ) ) {
				++$missing_dim;
			}
		}

		foreach ( $this->image_attachment_ids( $page_id ) as $attachment_id ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && ( ( $meta['width'] ?? 0 ) > 2600 || ( $meta['height'] ?? 0 ) > 2600 ) ) {
				++$oversized;
			}
		}

		$ids        = array();
		$duplicates = 0;
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$id = $node->getAttribute( 'id' );
			if ( isset( $ids[ $id ] ) ) {
				++$duplicates;
			}
			$ids[ $id ] = true;
		}

		$h1 = $xpath->query( '//h1' );

		$words        = str_word_count( wp_strip_all_tags( $html ) );
		$target_words = absint( $this->pages->manifest( $page_id )->get( 'target_words', 0 ) );

		$checks = array(
			'words'               => $words,
			'target_words'        => $target_words,
			'html_bytes'          => strlen( $html ),
			'css_bytes'           => strlen( $css ),
			'dom_nodes'           => $elements ? $elements->length : 0,
			'images'              => $images ? $images->length : 0,
			'headings'            => $headings ? $headings->length : 0,
			'h1_count'            => $h1 ? $h1->length : 0,
			'behaviors'           => $behaviors ? $behaviors->length : 0,
			'missing_alt'         => $missing_alt,
			'missing_dimensions'  => $missing_dim,
			'oversized_images'    => $oversized,
			'duplicate_ids'       => $duplicates,
			'css_selectors'       => substr_count( $css, '{' ),
			'invalid_field_refs'  => $this->invalid_field_references( $page_id ),
		);

		$warnings = array();

		if ( $checks['missing_alt'] > 0 ) {
			$warnings[] = sprintf( '%d image(s) have no alt text.', $checks['missing_alt'] );
		}
		if ( $checks['missing_dimensions'] > 0 ) {
			$warnings[] = sprintf( '%d image(s) have no width/height, which causes layout shift.', $checks['missing_dimensions'] );
		}
		if ( $checks['oversized_images'] > 0 ) {
			$warnings[] = sprintf( '%d image(s) are larger than 2600px and should be resized.', $checks['oversized_images'] );
		}
		if ( $checks['duplicate_ids'] > 0 ) {
			$warnings[] = sprintf( '%d duplicate id attribute(s).', $checks['duplicate_ids'] );
		}
		if ( 1 !== $checks['h1_count'] ) {
			$warnings[] = sprintf( 'The page has %d <h1> elements; it should have exactly one.', $checks['h1_count'] );
		}
		if ( $checks['css_bytes'] > 40000 ) {
			$warnings[] = sprintf( 'Page CSS is %d bytes, which is large for one page.', $checks['css_bytes'] );
		}
		if ( $checks['dom_nodes'] > 1200 ) {
			$warnings[] = sprintf( 'The page renders %d elements, which is heavy.', $checks['dom_nodes'] );
		}
		// A page that shipped at half the length it was planned at passes every
		// other check here, because nothing else knows how long it was meant to
		// be. Every page on the first real site built with this plugin was short
		// against its brief, and nothing said so.
		if ( $target_words > 0 ) {
			$share = $words / $target_words;

			if ( $share < 0.85 ) {
				$warnings[] = sprintf(
					'The page is %d words against a target of %d (%d%%). It is missing about %d words. Check the brief for what is not covered yet, rather than padding what is.',
					$words,
					$target_words,
					(int) round( $share * 100 ),
					$target_words - $words
				);
			} elseif ( $share > 1.6 ) {
				$warnings[] = sprintf(
					'The page is %d words against a target of %d (%d%%). That is fine if every part earns its place; it is a problem if the page repeats itself.',
					$words,
					$target_words,
					(int) round( $share * 100 )
				);
			}
		}

		if ( $checks['invalid_field_refs'] > 0 ) {
			$warnings[] = sprintf( '%d template field reference(s) do not exist in the schema.', $checks['invalid_field_refs'] );
		}

		return array(
			'status'   => array() === $warnings ? 'pass' : 'warning',
			'checks'   => $checks,
			'warnings' => $warnings,
		);
	}

	/**
	 * @return int[]
	 */
	private function image_attachment_ids( int $page_id ): array {
		$schema = $this->pages->schema( $page_id );
		if ( null === $schema ) {
			return array();
		}

		$ids    = array();
		$values = ( new \AIWP\Designer\ACF\FieldValueManager() )->read( $page_id, $schema );

		array_walk_recursive(
			$values,
			static function ( $value ) use ( &$ids ): void {
				if ( is_numeric( $value ) && (int) $value > 0 && wp_attachment_is_image( (int) $value ) ) {
					$ids[] = (int) $value;
				}
			}
		);

		return array_unique( $ids );
	}

	private function invalid_field_references( int $page_id ): int {
		$schema = $this->pages->schema( $page_id );
		if ( null === $schema ) {
			return 0;
		}

		$result = ( new \AIWP\Designer\Template\TemplateValidator() )->validate( $this->pages->template( $page_id ), $schema );

		$count = 0;
		foreach ( $result['errors'] as $error ) {
			if ( false !== strpos( $error, 'unknown field' ) || false !== strpos( $error, 'no subfield' ) ) {
				++$count;
			}
		}

		return $count;
	}
}
