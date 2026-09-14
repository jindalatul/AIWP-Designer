<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Pages\PageRepository;

/**
 * Whether this site made decisions, or took what it was given.
 *
 * The plugin only ever sees one site, so it cannot tell you that your plumber
 * looks like somebody else's plumber. What it can measure is the thing that
 * actually causes that: a site that left the starting values alone will look
 * like every other site that left the starting values alone. Those values are
 * a place to begin from, not a design.
 *
 * It measures the same way for the second half of the rule: pages built to one
 * shape. A site where every page is the same page with different words is
 * boring in a way no single page review can see, because every page passes.
 *
 * Nothing here has an opinion about what the site should look like. It only
 * says what was decided and what was left. A site that deliberately keeps a
 * default is free to — it will simply be told that is what it did.
 */
final class SiteVariety {

	/**
	 * Below this share of decisions made, the site is mostly the starting
	 * point wearing a different colour.
	 */
	private const DECIDED_ENOUGH = 0.5;

	private DesignSystemRepository $design;
	private PageRepository $pages;
	private ?\AIWP\Designer\Chrome\ChromeManager $chrome;
	private ?\AIWP\Designer\Articles\ArticleManager $articles;

	public function __construct(
		DesignSystemRepository $design,
		PageRepository $pages,
		?\AIWP\Designer\Chrome\ChromeManager $chrome = null,
		?\AIWP\Designer\Articles\ArticleManager $articles = null
	) {
		$this->design   = $design;
		$this->pages    = $pages;
		$this->chrome   = $chrome;
		$this->articles = $articles;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function check(): array {
		$system = $this->design->current();

		$untouched = $this->untouched( $system );
		$silent    = $this->unwritten_decisions( $system );
		$shapes    = $this->page_shapes();
		$library   = $this->library_use();

		$findings = array_merge(
			$this->say_untouched( $untouched ),
			$this->say_silent( $silent ),
			$this->say_shapes( $shapes ),
			$this->say_library( $library )
		);

		return array(
			'ok'            => true,
			'decided'       => $untouched['decided'],
			'of'            => $untouched['total'],
			'style_written' => 7 - count( $silent ),
			'pages'         => $shapes['pages'],
			'distinct_shapes' => $shapes['distinct'],
			'findings'      => $findings,
			'reads_as'      => $this->verdict( $untouched, $silent, $shapes ),
		);
	}

	/**
	 * How many token values are still exactly what the plugin shipped.
	 *
	 * @return array{decided:int,total:int,groups:array<string,array<int,string>>}
	 */
	private function untouched( DesignSystem $system ): array {
		$defaults = DesignSystem::defaults()->tokens();
		$current  = $system->tokens();

		$decided = 0;
		$total   = 0;
		$groups  = array();

		foreach ( array( 'colors', 'typography', 'spacing', 'radius', 'motion' ) as $group ) {
			foreach ( (array) ( $defaults[ $group ] ?? array() ) as $key => $shipped ) {
				if ( ! is_scalar( $shipped ) ) {
					continue;
				}

				++$total;
				$mine = ( (array) ( $current[ $group ] ?? array() ) )[ $key ] ?? null;

				if ( is_scalar( $mine ) && (string) $mine !== (string) $shipped ) {
					++$decided;
					continue;
				}

				$groups[ $group ][] = (string) $key;
			}
		}

		return array(
			'decided' => $decided,
			'total'   => $total,
			'groups'  => $groups,
		);
	}

	/**
	 * The seven decisions that are written in words, and left blank.
	 *
	 * @return string[]
	 */
	private function unwritten_decisions( DesignSystem $system ): array {
		$style = (array) ( $system->tokens()['style'] ?? array() );
		$out   = array();

		foreach ( array( 'personality', 'layout', 'surface', 'motion', 'buttons', 'imagery', 'signature' ) as $name ) {
			if ( '' === trim( (string) ( $style[ $name ] ?? '' ) ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * How many different shapes the pages of this site are built to.
	 *
	 * The shape is the run of block-level elements a reader meets, with the
	 * words taken out. Two pages with the same shape are the same page.
	 *
	 * @return array{pages:int,distinct:int,same:array<string,array<int,string>>}
	 */
	private function page_shapes(): array {
		$shapes = array();

		foreach ( $this->pages->content_page_ids() as $page_id ) {
			$template = $this->pages->template( $page_id );

			if ( '' === trim( $template ) ) {
				continue;
			}

			$shape = $this->shape_of( $template );

			if ( '' === $shape ) {
				continue;
			}

			$shapes[ $shape ][] = (string) get_the_title( $page_id );
		}

		$same = array_filter( $shapes, static fn( array $titles ): bool => count( $titles ) > 1 );

		return array(
			'pages'    => array_sum( array_map( 'count', $shapes ) ),
			'distinct' => count( $shapes ),
			'same'     => $same,
		);
	}

	private function shape_of( string $template ): string {
		if ( ! preg_match_all( '/<(section|article|header|footer|aside|h1|h2|h3|ul|ol|table|figure|form|blockquote)\b/i', $template, $found ) ) {
			return '';
		}

		return strtolower( implode( ',', $found[1] ) );
	}

	/**
	 * Components the site wrote and never used.
	 *
	 * @return array{have:int,used:int,unused:array<int,string>}
	 */
	private function library_use(): array {
		$library = $this->design->components();
		$have    = $library->to_array();

		if ( array() === $have ) {
			return array( 'have' => 0, 'used' => 0, 'unused' => array() );
		}

		$markup = $this->all_markup();
		$unused = array();

		foreach ( $have as $component ) {
			// One component owns several class names. It counts as used if the
			// site reaches for any of them; counting class names instead says
			// "21 of 6 components", which is not a sentence.
			if ( ! $this->component_is_used( (array) $component, $markup ) ) {
				$unused[] = (string) ( $component['name'] ?? $component['id'] ?? '?' );
			}
		}

		return array(
			'have'   => count( $have ),
			'used'   => count( $have ) - count( $unused ),
			'unused' => $unused,
		);
	}

	/**
	 * @param array<string,mixed> $component
	 */
	private function component_is_used( array $component, string $markup ): bool {
		if ( ! preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', (string) ( $component['css'] ?? '' ), $found ) ) {
			// No CSS of its own to look for. Nothing to say either way.
			return true;
		}

		foreach ( $found[1] as $class ) {
			if ( false !== strpos( $markup, (string) $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every piece of markup this site has.
	 *
	 * A header or footer component lives in the chrome, not in a page
	 * template. Looking only at pages reports the whole header and footer as
	 * "never used", which is both wrong and the kind of wrong that stops
	 * anyone believing the rest of the report.
	 */
	private function all_markup(): string {
		$markup = '';

		foreach ( $this->pages->content_page_ids() as $page_id ) {
			$markup .= $this->pages->template( $page_id );
		}

		if ( $this->chrome instanceof \AIWP\Designer\Chrome\ChromeManager ) {
			$markup .= $this->chrome->header_template() . $this->chrome->footer_template();
		}

		if ( $this->articles instanceof \AIWP\Designer\Articles\ArticleManager ) {
			$markup .= $this->articles->template_for( '' )->template();
			$markup .= $this->articles->archive()->template();
		}

		return $markup;
	}

	// -- how it says it ------------------------------------------------------

	/**
	 * @param array{decided:int,total:int,groups:array<string,array<int,string>>} $untouched
	 * @return array<int,array<string,string>>
	 */
	private function say_untouched( array $untouched ): array {
		if ( 0 === $untouched['total'] ) {
			return array();
		}

		$share = $untouched['decided'] / $untouched['total'];

		if ( $share >= self::DECIDED_ENOUGH ) {
			return array();
		}

		$left = array();
		foreach ( $untouched['groups'] as $group => $keys ) {
			$left[] = sprintf( '%s (%d)', $group, count( $keys ) );
		}

		return array(
			$this->finding(
				'mostly_the_starting_point',
				sprintf( '%d of %d design values are still exactly what the plugin ships.', $untouched['total'] - $untouched['decided'], $untouched['total'] ),
				'Still shipped: ' . implode( ', ', $left ) . '.',
				'Those values exist so a site has somewhere to start, not because they suit this business. '
					. 'Any site that leaves them alone ends up looking like any other site that leaves them alone. '
					. 'Change what this business gives you a reason to change.'
			),
		);
	}

	/**
	 * @param string[] $silent
	 * @return array<int,array<string,string>>
	 */
	private function say_silent( array $silent ): array {
		if ( array() === $silent ) {
			return array();
		}

		return array(
			$this->finding(
				'decision_not_written_down',
				sprintf( '%d of the 7 style decisions are blank: %s.', count( $silent ), implode( ', ', $silent ) ),
				'They are read back by every page that gets built after this one.',
				'A decision nobody wrote down is a decision each page makes again on its own, differently. '
					. 'Write what this site does, in words, even if the answer is "none".'
			),
		);
	}

	/**
	 * @param array{pages:int,distinct:int,same:array<string,array<int,string>>} $shapes
	 * @return array<int,array<string,string>>
	 */
	private function say_shapes( array $shapes ): array {
		if ( array() === $shapes['same'] ) {
			return array();
		}

		$out = array();

		foreach ( $shapes['same'] as $titles ) {
			$out[] = $this->finding(
				'same_page_twice',
				sprintf( '%s are built to the same shape.', $this->list_of( $titles ) ),
				'The same run of sections in the same order, with different words in them.',
				'Two pages doing different jobs that look identical make a site feel like a template. '
					. 'Ask what each page is actually for, and let that change the shape.'
			);
		}

		return $out;
	}

	/**
	 * @param array{have:int,used:int,unused:array<int,string>} $library
	 * @return array<int,array<string,string>>
	 */
	private function say_library( array $library ): array {
		if ( 0 === $library['have'] || array() === $library['unused'] ) {
			return array();
		}

		return array(
			$this->finding(
				'components_never_used',
				sprintf(
					'%d of the %d components in this site\'s library are never used.',
					count( $library['unused'] ),
					$library['have']
				),
				'Never placed on a page: ' . implode( ', ', array_slice( $library['unused'], 0, 8 ) ) . '.',
				'Either the pages should be reaching for them, or they were written and forgotten. '
					. 'A library nobody uses is a library that stops being true.'
			),
		);
	}

	/**
	 * @param array{decided:int,total:int,groups:array<string,array<int,string>>} $untouched
	 * @param string[]                                                            $silent
	 * @param array{pages:int,distinct:int,same:array<string,array<int,string>>}  $shapes
	 */
	private function verdict( array $untouched, array $silent, array $shapes ): string {
		$share = $untouched['total'] > 0 ? $untouched['decided'] / $untouched['total'] : 0.0;

		if ( $share < 0.25 && count( $silent ) > 3 ) {
			return 'This is close to the plugin\'s starting values with the words changed. Another site built the same way would look like this one.';
		}

		if ( array() !== $shapes['same'] && $shapes['distinct'] < 2 ) {
			return 'Every page here is the same page. The site will read as one template filled in repeatedly.';
		}

		if ( array() === $shapes['same'] && $share >= self::DECIDED_ENOUGH && array() === $silent ) {
			return 'This site made its own decisions and its pages are not copies of each other.';
		}

		return 'Some of this site is decided and some of it is still the starting point. What is listed below is the part that was left.';
	}

	/** @param string[] $titles */
	private function list_of( array $titles ): string {
		$titles = array_map( static fn( string $t ): string => '"' . $t . '"', $titles );

		if ( count( $titles ) < 3 ) {
			return implode( ' and ', $titles );
		}

		$last = array_pop( $titles );

		return implode( ', ', $titles ) . ' and ' . $last;
	}

	/** @return array<string,string> */
	private function finding( string $id, string $what, string $detail, string $why ): array {
		return array(
			'id'     => $id,
			'what'   => $what,
			'detail' => $detail,
			'why'    => $why,
		);
	}
}
