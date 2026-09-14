<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Rendering\PageRenderer;

/**
 * The page as a reader meets it.
 *
 * Everything else checks the parts: the template parses, the CSS is scoped,
 * the schema has no duplicate ids. All of that can pass while the page itself
 * says almost nothing, repeats a sentence three times, or has a band of empty
 * space where a field was left blank. Nobody finds out until somebody loads
 * the page, and the one thing that never happens is the AI loading its own
 * page.
 *
 * So this reads the finished page back: what it actually says, in order, and
 * the handful of things you can only know after it has been rendered.
 */
final class PageInspector {

	/** Below this, a page has been built but not written. */
	private const THIN_WORDS = 40;

	/** Text that was meant to be replaced and was not. */
	private const PLACEHOLDER = array(
		'lorem ipsum',
		'dolor sit amet',
		'todo',
		'tbd',
		'coming soon',
		'your headline here',
		'your text here',
		'placeholder',
		'xxx',
		'foo bar',
	);

	private PageRepository $pages;
	private FieldValueManager $values;
	private PageRenderer $renderer;
	private ?\AIWP\Designer\Chrome\ChromeManager $chrome;
	private ?\AIWP\Designer\Design\DesignSystemRepository $design;

	public function __construct(
		PageRepository $pages,
		FieldValueManager $values,
		PageRenderer $renderer,
		?\AIWP\Designer\Chrome\ChromeManager $chrome = null,
		?\AIWP\Designer\Design\DesignSystemRepository $design = null
	) {
		$this->pages    = $pages;
		$this->values   = $values;
		$this->renderer = $renderer;
		$this->chrome   = $chrome;
		$this->design   = $design;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function look( int $page_id ): array {
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return array(
				'ok'    => false,
				'error' => 'That page is not an AIWP page.',
			);
		}

		$schema = $this->pages->schema( $page_id );

		if ( ! $schema instanceof PageSchema ) {
			return array(
				'ok'    => false,
				'error' => 'That page has no field model yet.',
			);
		}

		$template = $this->pages->template( $page_id );
		$content  = $this->values->read( $page_id, $schema, $this->pages->uuid( $page_id ) );
		$html     = $this->renderer->render( $page_id );

		$problems = array_merge(
			$this->gaps( $schema, $content, $template ),
			$this->unused( $schema, $template ),
			$this->placeholders( $html ),
			$this->repeats( $html ),
			$this->dead_links( $html ),
			$this->cta_twice( $html )
		);

		$reading = $this->reading( $html );
		$words   = str_word_count( self::as_text( $html ) );

		if ( $words < self::THIN_WORDS ) {
			$problems[] = $this->problem(
				'almost_nothing_on_it',
				sprintf( 'The whole page is %d words.', $words ),
				'It was built but not written. A visitor arrives at an empty room.'
			);
		}

		/*
		 * The craft findings come back here too.
		 *
		 * design_review has always known about loose leading, lopsided
		 * padding, off-palette colour and the rest, and running it was
		 * optional — so a page could be published having never been looked at
		 * by the one tool that can see those things. page_look is the step
		 * nothing can skip, because page_publish refuses without it. Putting
		 * the findings here means the unmissable step is also the one that
		 * reports them.
		 */
		$design = $this->design_findings( $page_id );

		return array(
			'ok'          => true,
			'page_id'     => $page_id,
			'version'     => $this->pages->active_version( $page_id ),
			'title'       => get_the_title( $page_id ),
			'status'      => get_post_status( $page_id ),
			'url'         => (string) get_permalink( $page_id ),
			'words'       => $words,
			// What the page says, in the order it says it. Read this before
			// deciding the page is finished.
			'reading'     => $reading,
			'problems'    => $problems,
			'design'      => $design,
			'looks_right' => array() === $problems && array() === $design['findings'],
			'next_step'   => array() === $problems && array() === $design['findings']
				? 'Nothing here needs fixing. Open the preview URL yourself if you want to see it laid out.'
				: 'Fix what is listed, under problems and under design, then look again.',
		);
	}

	/**
	 * What design_review says about this page, folded in.
	 *
	 * @return array{score:int,findings:array<int,array<string,mixed>>}
	 */
	private function design_findings( int $page_id ): array {
		if ( ! $this->design instanceof \AIWP\Designer\Design\DesignSystemRepository ) {
			return array( 'score' => 100, 'findings' => array() );
		}

		$review = ( new \AIWP\Designer\Design\DesignReviewer(
			$this->pages->authored_css( $page_id ),
			$this->pages->template( $page_id ),
			$this->design->current(),
			$this->design->authored_global_css() . "\n" . $this->design->components()->css(),
			$this->design->components()
		) )->review();

		return array(
			'score'    => (int) ( $review['score'] ?? 100 ),
			'findings' => (array) ( $review['findings'] ?? array() ),
		);
	}

	/**
	 * Fields the template prints that have nothing in them.
	 *
	 * This is the one a person notices first and no other check can see: the
	 * template is valid, the schema is valid, and the page has a band of empty
	 * space in the middle of it.
	 *
	 * @param array<string,mixed> $content
	 * @return array<int,array<string,string>>
	 */
	private function gaps( PageSchema $schema, array $content, string $template ): array {
		$out = array();

		foreach ( array_keys( $schema->fields() ) as $path ) {
			if ( ! $this->template_prints( $template, (string) $path ) ) {
				continue;
			}

			if ( ! $this->is_blank( $this->value_at( $content, (string) $path ) ) ) {
				continue;
			}

			$out[] = $this->problem(
				'gap',
				sprintf( 'The page prints "%s" and it is empty.', $path ),
				'That leaves a hole where a visitor expects something. Write it, or take it out of the template.'
			);
		}

		return $out;
	}

	/**
	 * Fields that hold words the page never shows.
	 *
	 * Not wrong, but it is usually a rename that went half way: the content is
	 * written and the template still points at the old name.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function unused( PageSchema $schema, string $template ): array {
		$out = array();

		foreach ( array_keys( $schema->fields() ) as $path ) {
			if ( $this->template_prints( $template, (string) $path ) ) {
				continue;
			}

			$out[] = $this->problem(
				'never_shown',
				sprintf( '"%s" exists but the template never prints it.', $path ),
				'Either the template means to use it and does not, or the field is left over. Whoever fills it in will wonder where it went.'
			);
		}

		return $out;
	}

	/**
	 * Standing text that was meant to be replaced.
	 *
	 * Read off the finished page rather than the stored content, because the
	 * commonest kind is typed straight into the template and never reaches a
	 * field at all.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function placeholders( string $html ): array {
		$out  = array();
		$text = strtolower( self::as_text( $html ) );

		$found = array();

		foreach ( self::PLACEHOLDER as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				$found[] = $needle;
			}
		}

		if ( array() === $found ) {
			return $out;
		}

		// One sentence of filler trips several of these. Say it once.
		$out[] = $this->problem(
			'placeholder_left_in',
			sprintf( 'The page still says "%s".', implode( '", "', $found ) ),
			'Standing text that was meant to be replaced. On a live page it reads as unfinished.'
		);

		return $out;
	}

	/**
	 * The same sentence printed more than once.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function repeats( string $html ): array {
		$parts = preg_split( '/(?<=[.!?])\s+|\n+/', self::as_text( $html ) ) ?: array();
		$seen  = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			// Short lines repeat for good reasons: labels, buttons, prices.
			if ( strlen( $part ) < 40 ) {
				continue;
			}

			/*
			 * Only a sentence counts.
			 *
			 * Three campus cards listing the same office hours is a
			 * comparison doing its job, not a page repeating itself, and
			 * flagging it teaches people to skim the list. A value in a table
			 * has no full stop; a sentence somebody wrote twice does.
			 */
			if ( ! preg_match( '/[.!?]["\')\]]?$/u', $part ) ) {
				continue;
			}

			$key           = strtolower( (string) preg_replace( '/\s+/', ' ', $part ) );
			$seen[ $key ]  = ( $seen[ $key ] ?? 0 ) + 1;
		}

		$out = array();

		foreach ( $seen as $sentence => $times ) {
			if ( $times < 2 ) {
				continue;
			}

			$out[] = $this->problem(
				'said_twice',
				sprintf( '"%s" appears %d times.', $this->shorten( $sentence ), $times ),
				'A reader going down the page meets the same sentence again and stops trusting it.'
			);
		}

		return $out;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function dead_links( string $html ): array {
		if ( ! preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $html, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		$dead = 0;

		foreach ( $found as $link ) {
			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $link[0], $href ) ) {
				++$dead;
				continue;
			}

			$value = trim( $href[2] );

			if ( '' === $value || '#' === $value ) {
				++$dead;
			}
		}

		if ( 0 === $dead ) {
			return array();
		}

		return array(
			$this->problem(
				'link_goes_nowhere',
				sprintf( '%d link(s) on this page go nowhere.', $dead ),
				'An empty href or "#". A visitor clicks and the page does not move.'
			),
		);
	}

	/**
	 * A call to action the visitor has already been given.
	 *
	 * The shared header or footer carries a button, the page ends with the
	 * same button, and somebody scrolling meets "Get a fixed price" twice
	 * within one screen. Every page passes on its own, because nothing looking
	 * at a page can see the chrome around it.
	 *
	 * Matched on the link and its words together, so a footer that merely
	 * links to the same page with different words is left alone — that is
	 * navigation, not a repeated ask.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function cta_twice( string $html ): array {
		if ( ! $this->chrome instanceof \AIWP\Designer\Chrome\ChromeManager || ! $this->chrome->exists() ) {
			return array();
		}

		// The rendered chrome, not the template. A template still says
		// {{text:nav.cta}}, which matches nothing and quietly finds no
		// duplicates at all.
		$around = $this->links_in( $this->chrome->render( 'header' ) . $this->chrome->render( 'footer' ) );

		if ( array() === $around ) {
			return array();
		}

		$out = array();

		foreach ( $this->links_in( $html ) as $key => $words ) {
			if ( ! isset( $around[ $key ] ) ) {
				continue;
			}

			$out[] = $this->problem(
				'asked_twice',
				sprintf( '"%s" is on this page and in the header or footer.', $words ),
				'A visitor is given the same instruction twice, often within one screen, which makes both of them '
					. 'read as decoration. Keep whichever is better placed and drop the other, or give the page one '
					. 'that says something the shared one cannot.'
			);
		}

		return $out;
	}

	/**
	 * Links as "words at destination", so the same ask can be recognised.
	 *
	 * @return array<string,string> key => the words, for the message
	 */
	private function links_in( string $markup ): array {
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $markup, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();

		foreach ( $found as $link ) {
			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $link[1], $href ) ) {
				continue;
			}

			$words = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $link[2] ) ) );
			$where = trim( $href[2] );

			// A menu placeholder has no words yet, and a bare anchor is not an ask.
			if ( '' === $words || '' === $where || '#' === $where ) {
				continue;
			}

			$key = strtolower( $words ) . '@' . rtrim( (string) wp_parse_url( $where, PHP_URL_PATH ) ?: $where, '/' );

			$out[ $key ] = $words;
		}

		return $out;
	}

	/**
	 * The page as words, in the order a reader meets them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function reading( string $html ): array {
		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $doc );
		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//p|//li|//blockquote|//figcaption|//button|//a[@class]' );

		if ( ! $nodes ) {
			return array();
		}

		$out  = array();
		$last = '';

		foreach ( $nodes as $node ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $node->textContent ) );

			if ( '' === $text || $text === $last ) {
				continue;
			}

			$last  = $text;
			$out[] = array(
				'as'   => $node->nodeName,
				'says' => $this->shorten( $text, 220 ),
			);

			if ( count( $out ) >= 120 ) {
				break;
			}
		}

		return $out;
	}

	// -- small helpers -------------------------------------------------------

	/** @return array<string,string> */
	private function problem( string $id, string $what, string $why ): array {
		return array(
			'id'   => $id,
			'what' => $what,
			'why'  => $why,
		);
	}

	private function template_prints( string $template, string $path ): bool {
		$quoted = preg_quote( $path, '/' );

		// {{text:hero.h1}}, {{#if:hero.h1}}, {{#each:hero.cards}} and @item under it.
		return 1 === preg_match( '/\{\{[#\/]?[a-z_]*:?' . $quoted . '(\.|\}|\s)/i', $template );
	}

	private function value_at( array $content, string $path ): mixed {
		$value = $content;

		foreach ( explode( '.', $path ) as $step ) {
			if ( ! is_array( $value ) || ! array_key_exists( $step, $value ) ) {
				return null;
			}
			$value = $value[ $step ];
		}

		return $value;
	}

	private function is_blank( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === array_filter( $value, fn( $item ): bool => ! $this->is_blank( $item ) );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return false;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Markup as readable text.
	 *
	 * Every tag becomes a space. Stripping them outright runs the end of one
	 * element into the start of the next — "…sentence.A real headline…" — and
	 * anything that splits on sentences then sees one long sentence and finds
	 * nothing.
	 */
	private static function as_text( string $html ): string {
		$spaced = preg_replace( '/<[^>]*>/', ' ', $html ) ?? $html;

		return trim( (string) preg_replace( '/[ \t]+/', ' ', wp_strip_all_tags( $spaced ) ) );
	}

	private function shorten( string $text, int $limit = 90 ): string {
		return strlen( $text ) <= $limit ? $text : substr( $text, 0, $limit - 1 ) . '…';
	}
}
