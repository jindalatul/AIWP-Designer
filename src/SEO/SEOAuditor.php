<?php
declare( strict_types = 1 );

namespace AIWP\Designer\SEO;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Rendering\PageRenderer;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Checks a page against the things a search engine and a reader both need.
 *
 * Two layers, on purpose. The first needs no opinion about the page — a title
 * exists and is a sensible length, there is one h1, images have alt text, the
 * canonical is right. Those run on every page of every site, whether anybody
 * wrote a brief or not.
 *
 * The second needs somebody to have said what the page is for. Where a brief
 * exists, the questions it promised to answer and the concepts it promised to
 * cover are checked against what the page actually says. Where one does not,
 * those checks are skipped and said to be skipped, rather than guessed at.
 */
final class SEOAuditor {

	/** Google truncates around here; these are the useful working bounds. */
	private const TITLE_MIN = 25;
	private const TITLE_MAX = 62;
	private const DESC_MIN  = 70;
	private const DESC_MAX  = 165;

	private PageRepository $pages;

	private PageRenderer $renderer;

	public function __construct( PageRepository $pages, PageRenderer $renderer ) {
		$this->pages    = $pages;
		$this->renderer = $renderer;
	}

	/** @var array<int,array<string,mixed>> */
	private array $findings = array();

	/**
	 * @return array<string,mixed>
	 */
	public function audit( int $post_id ): array {
		$this->findings = array();

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'error' => 'No such page.' );
		}

		$brief = PageBrief::for_post( $post_id );
		$html  = $this->html( $post );
		$text  = $this->plain( $html );
		$meta  = $this->meta( $post, $brief );

		$this->check_title( $meta['title'] );
		$this->check_description( $meta['description'] );
		$this->check_headings( $html );
		$this->check_images( $html );
		$this->check_links( $html, $post_id );
		$this->check_slug( $post );

		$skipped = array();

		if ( $brief->is_empty() ) {
			$skipped[] = 'Everything that needs a brief: the keyword, the questions this page promised to answer, and the concepts it promised to cover. Add one with page_set_brief.';
		} else {
			$this->check_keyword( $brief, $meta, $text, $html );
			$this->check_questions( $brief, $html, $text );
			$this->check_entities( $brief, $text );
		}

		usort(
			$this->findings,
			static function ( array $a, array $b ): int {
				$order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				return ( $order[ $a['severity'] ] ?? 3 ) <=> ( $order[ $b['severity'] ] ?? 3 );
			}
		);

		return array(
			'post_id'     => $post_id,
			'title'       => $meta['title'],
			'description' => $meta['description'],
			'meta_owner'  => $meta['owner'],
			'has_brief'   => ! $brief->is_empty(),
			'words'       => str_word_count( $text ),
			'findings'    => $this->findings,
			'counts'      => array(
				'high'   => $this->count( 'high' ),
				'medium' => $this->count( 'medium' ),
				'low'    => $this->count( 'low' ),
			),
			'not_checked' => array_merge(
				$skipped,
				array(
					'Whether the page is any good, or true. Read it.',
					'Real page speed. Core Web Vitals need a browser; performance_static_audit covers what can be measured without one.',
					'Anything about how this page ranks. Nothing here talks to a search engine.',
				)
			),
		);
	}

	// ------------------------------------------------------------- the checks

	private function check_title( string $title ): void {
		$length = mb_strlen( $title );

		if ( '' === trim( $title ) ) {
			$this->add( 'title_missing', 'high', 'The page has no title tag.',
				'A search result needs something to print.', 'Set meta_title in the brief, or give the page a title.' );
			return;
		}

		if ( $length > self::TITLE_MAX ) {
			$this->add( 'title_long', 'medium',
				sprintf( 'The title is %d characters; it will be cut off around %d.', $length, self::TITLE_MAX ),
				$title, 'Put what matters first, and cut the rest.' );
		} elseif ( $length < self::TITLE_MIN ) {
			$this->add( 'title_short', 'low',
				sprintf( 'The title is only %d characters.', $length ),
				$title, 'There is room for more. Say what the page is for.' );
		}
	}

	private function check_description( string $description ): void {
		$length = mb_strlen( $description );

		if ( '' === trim( $description ) ) {
			$this->add( 'description_missing', 'medium', 'The page has no meta description.',
				'A search engine will pick a sentence from the page instead, and it will pick badly.',
				'Set meta_description in the brief.' );
			return;
		}

		if ( $length > self::DESC_MAX ) {
			$this->add( 'description_long', 'low',
				sprintf( 'The description is %d characters and will be cut around %d.', $length, self::DESC_MAX ),
				$description, 'Trim it so the last sentence survives.' );
		} elseif ( $length < self::DESC_MIN ) {
			$this->add( 'description_short', 'low',
				sprintf( 'The description is only %d characters.', $length ),
				$description, 'There is room to say why somebody should click.' );
		}
	}

	private function check_headings( string $html ): void {
		$xpath = $this->xpath( $html );
		if ( null === $xpath ) {
			return;
		}

		$h1 = $xpath->query( '//h1' );
		$n  = $h1 ? $h1->length : 0;

		if ( 1 !== $n ) {
			$this->add( 'h1_count', 'high',
				sprintf( 'The page has %d h1 elements.', $n ),
				0 === $n ? 'Nothing states what the page is about.' : 'More than one first-level heading confuses the outline.',
				'Exactly one h1, and it should say what the page is.' );
		}

		$last     = 0;
		$problems = array();

		foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			$level = (int) substr( $heading->nodeName, 1 );
			if ( 0 !== $last && $level > $last + 1 ) {
				$problems[] = sprintf( 'h%d after an h%d', $level, $last );
			}
			$last = $level;
		}

		if ( array() !== $problems ) {
			$this->add( 'heading_skip', 'medium',
				sprintf( '%d heading(s) skip a level.', count( $problems ) ),
				implode( '; ', array_slice( $problems, 0, 6 ) ),
				'Go down one level at a time. To make a heading look smaller, change the CSS, not the level.' );
		}
	}

	private function check_images( string $html ): void {
		$xpath = $this->xpath( $html );
		if ( null === $xpath ) {
			return;
		}

		$missing = 0;
		$total   = 0;

		foreach ( $xpath->query( '//img' ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			++$total;
			if ( '' === trim( $img->getAttribute( 'alt' ) ) && 'true' !== $img->getAttribute( 'aria-hidden' ) ) {
				++$missing;
			}
		}

		if ( $missing > 0 ) {
			$this->add( 'image_alt', 'medium',
				sprintf( '%d of %d images have no alt text.', $missing, $total ),
				'A screen reader reads the file name instead, and a search engine learns nothing.',
				'Describe what the image shows. If it is decoration, mark it aria-hidden="true".' );
		}
	}

	private function check_links( string $html, int $post_id ): void {
		$xpath = $this->xpath( $html );
		if ( null === $xpath ) {
			return;
		}

		$home     = untrailingslashit( (string) home_url() );
		$internal = 0;
		$empty    = 0;

		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}

			$href = trim( $link->getAttribute( 'href' ) );
			$text = trim( (string) $link->textContent );

			if ( '' === $text && ! $link->getElementsByTagName( 'img' )->length ) {
				++$empty;
			}

			if ( '' === $href || '#' === $href[0] ) {
				continue;
			}

			if ( 0 === strpos( $href, '/' ) || 0 === strpos( $href, $home ) ) {
				++$internal;
			}
		}

		if ( 0 === $internal ) {
			$this->add( 'no_internal_links', 'medium', 'The page links nowhere else on the site.',
				'A page with no outward links is a dead end for a reader and for a crawler.',
				'Link to the pages this one naturally leads to.' );
		}

		if ( $empty > 0 ) {
			$this->add( 'empty_link_text', 'medium',
				sprintf( '%d link(s) have no text.', $empty ),
				'Nobody can tell where they go, by eye or by screen reader.',
				'Give every link words, or an image with alt text.' );
		}

		$incoming = $this->incoming_links( $post_id );
		if ( 0 === $incoming && 'publish' === get_post_status( $post_id ) ) {
			$this->add( 'orphan', 'medium', 'Nothing on this site links to this page.',
				'It can only be reached by knowing the address, which means it will be crawled rarely and found by nobody.',
				'Link to it from a page that already gets visits, or put it in the menu.' );
		}
	}

	private function check_slug( \WP_Post $post ): void {
		$slug = (string) $post->post_name;

		if ( strlen( $slug ) > 72 ) {
			$this->add( 'slug_long', 'low', sprintf( 'The address is %d characters long.', strlen( $slug ) ),
				'/' . $slug, 'Shorter addresses are easier to share and read.' );
		}

		if ( preg_match( '/^\d+$/', $slug ) || preg_match( '/(^|-)(page|post|untitled)(-|$)/', $slug ) ) {
			$this->add( 'slug_weak', 'low', 'The address says nothing about the page.',
				'/' . $slug, 'Use words somebody would recognise.' );
		}
	}

	private function check_keyword( PageBrief $brief, array $meta, string $text, string $html ): void {
		$keyword = strtolower( trim( (string) $brief->get( 'primary_keyword', '' ) ) );
		if ( '' === $keyword ) {
			return;
		}

		$xpath = $this->xpath( $html );
		$h1    = '';
		if ( null !== $xpath ) {
			$nodes = $xpath->query( '//h1' );
			$h1    = $nodes && $nodes->length ? strtolower( trim( (string) $nodes->item( 0 )->textContent ) ) : '';
		}

		$missing = array();
		if ( false === strpos( strtolower( $meta['title'] ), $keyword ) ) {
			$missing[] = 'the title';
		}
		if ( '' !== $h1 && false === strpos( $h1, $keyword ) ) {
			$missing[] = 'the h1';
		}
		if ( false === stripos( $text, $keyword ) ) {
			$missing[] = 'the page itself';
		}

		if ( array() === $missing ) {
			return;
		}

		$this->add( 'keyword_absent', in_array( 'the page itself', $missing, true ) ? 'high' : 'medium',
			sprintf( 'The brief\'s keyword "%s" does not appear in %s.', $keyword, implode( ' or ', $missing ) ),
			'A page that never says the thing it is about is hard to match to somebody looking for it.',
			'Use the phrase naturally where it belongs. Do not sprinkle it — once in the title and once early in the body does more than ten repetitions.' );
	}

	private function check_questions( PageBrief $brief, string $html, string $text ): void {
		$questions = (array) $brief->get( 'questions', array() );
		if ( array() === $questions ) {
			return;
		}

		$haystack  = strtolower( $text );
		$unanswered = array();

		foreach ( $questions as $question ) {
			if ( ! $this->covered( (string) $question, $haystack ) ) {
				$unanswered[] = (string) $question;
			}
		}

		if ( array() === $unanswered ) {
			return;
		}

		$this->add( 'questions_unanswered', count( $unanswered ) > count( $questions ) / 2 ? 'high' : 'medium',
			sprintf( '%d of %d questions in the brief look unanswered.', count( $unanswered ), count( $questions ) ),
			implode( ' | ', array_slice( $unanswered, 0, 5 ) ),
			'Answer each one somewhere on the page, ideally under a heading shaped like the question. That is also what an answer engine quotes.' );
	}

	private function check_entities( PageBrief $brief, string $text ): void {
		$entities = (array) $brief->get( 'entities', array() );
		if ( array() === $entities ) {
			return;
		}

		$haystack = strtolower( $text );
		$missing  = array();

		foreach ( $entities as $entity ) {
			if ( false === strpos( $haystack, strtolower( trim( (string) $entity ) ) ) ) {
				$missing[] = (string) $entity;
			}
		}

		if ( array() === $missing ) {
			return;
		}

		$this->add( 'entities_missing', 'medium',
			sprintf( '%d of %d concepts from the brief are not mentioned.', count( $missing ), count( $entities ) ),
			implode( ' | ', array_slice( $missing, 0, 8 ) ),
			'A page that never mentions a concept the subject requires reads as written by somebody who has not done the work. Cover it, or take it off the brief.' );
	}

	// ------------------------------------------------------------- utilities

	/**
	 * Is a question answered? Matched on its distinctive words rather than the
	 * whole sentence, because nobody writes a heading that repeats the question
	 * word for word and requiring that would fail every real page.
	 */
	private function covered( string $question, string $haystack ): bool {
		$stop  = array( 'what', 'why', 'how', 'when', 'where', 'which', 'who', 'do', 'does', 'did', 'is', 'are',
			'can', 'could', 'should', 'would', 'will', 'the', 'a', 'an', 'of', 'to', 'for', 'in', 'on', 'at',
			'my', 'i', 'we', 'you', 'your', 'our', 'it', 'and', 'or', 'if', 'be', 'been', 'have', 'has', 'me',
			'about', 'with', 'from', 'that', 'this', 'they', 'them', 'get', 'got', 'need', 'want', 'much', 'many' );

		$words = preg_split( '/[^a-z0-9]+/', strtolower( $question ) ) ?: array();
		$words = array_values( array_filter( $words, static fn( string $w ): bool => strlen( $w ) > 2 && ! in_array( $w, $stop, true ) ) );

		if ( array() === $words ) {
			return true;
		}

		$found = 0;
		foreach ( $words as $word ) {
			if ( false !== strpos( $haystack, $word ) ) {
				++$found;
			}
		}

		return $found / count( $words ) >= 0.6;
	}

	private function incoming_links( int $post_id ): int {
		$url = (string) get_permalink( $post_id );
		if ( '' === $url ) {
			return 0;
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$count = 0;

		foreach ( $this->pages->content_page_ids() as $other ) {
			if ( $other === $post_id ) {
				continue;
			}
			if ( false !== strpos( $this->renderer->render( $other ), $path ) ) {
				++$count;
			}
		}

		$chrome = \AIWP\Designer\Plugin::instance()->chrome();
		if ( $chrome->exists() && false !== strpos( $chrome->render( 'header' ) . $chrome->render( 'footer' ), $path ) ) {
			++$count;
		}

		return $count;
	}

	/**
	 * @return array{title:string,description:string,owner:string}
	 */
	private function meta( \WP_Post $post, PageBrief $brief ): array {
		$owner = MetaTags::owner();

		return array(
			'title'       => MetaTags::title_for( $post, $brief ),
			'description' => MetaTags::description_for( $post, $brief ),
			'owner'       => $owner,
		);
	}

	private function html( \WP_Post $post ): string {
		if ( $this->pages->is_aiwp_page( $post->ID ) ) {
			return $this->renderer->render( $post->ID );
		}

		return (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	private function plain( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	private function xpath( string $html ): ?DOMXPath {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? new DOMXPath( $doc ) : null;
	}

	private function count( string $severity ): int {
		return count( array_filter( $this->findings, static fn( array $f ): bool => $f['severity'] === $severity ) );
	}

	private function add( string $id, string $severity, string $title, string $detail, string $fix ): void {
		$this->findings[] = array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}
}
