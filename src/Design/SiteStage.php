<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Plugin;

/**
 * Where a site has got to, and what it needs next.
 *
 * Every workflow in this plugin documents its own steps, and nothing described
 * the order they go in. So the order was whatever the AI happened to choose,
 * and it chose differently each time: a site got built with no record of the
 * business at all, a header was written before a single page existed so its
 * navigation had nothing to point at, and a component library was never
 * extracted because nothing said to.
 *
 * None of that shows up as an error. It shows up as a site that does not hang
 * together.
 *
 * This reads what exists and names the next thing. It refuses nothing — an
 * order that suits a particular site is the AI's to choose — but it means
 * nobody has to guess, and site_get_context says it on every call.
 */
final class SiteStage {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function read(): array {
		$done = $this->what_exists();

		foreach ( $this->steps() as $id => $step ) {
			if ( $step['done']( $done ) ) {
				continue;
			}

			return array(
				'at'        => $id,
				'next_step' => $step['do'],
				'why'       => $step['why'],
				'done'      => $this->finished_before( $id ),
				'order'     => array_keys( $this->steps() ),
			);
		}

		return array(
			'at'        => 'building_pages',
			'next_step' => 'The foundations are in place. Build the remaining pages from the brief, each one composed '
				. 'from the component library, and run design_review and page_look on each before publishing.',
			'why'       => 'Everything a page needs to look like the rest of the site already exists.',
			'done'      => array_keys( $this->steps() ),
			'order'     => array_keys( $this->steps() ),
		);
	}

	/**
	 * The order, and how to tell whether each part is done.
	 *
	 * @return array<string,array{done:callable,do:string,why:string}>
	 */
	private function steps(): array {
		return array(
			'business_and_brand' => array(
				'done' => static fn( array $d ): bool => $d['brand'],
				'do'   => 'site_set_brand: what the business does, who its customers are, how it should feel. Ask the owner '
					. 'rather than inventing answers.',
				'why'  => 'Every decision after this one refers back to it. Designing first and describing the business '
					. 'afterwards produces a site that could belong to anyone.',
			),
			'design_system'      => array(
				'done' => static fn( array $d ): bool => $d['design'],
				'do'   => 'design_create_system: colours, type, spacing, radius, motion, and the seven style decisions '
					. 'written in words. Motion belongs here, not at the end.',
				'why'  => 'Motion added page by page at the end is how one site ends up moving at three different '
					. 'speeds. It is a decision, made once.',
			),
			'homepage'           => array(
				'done' => static fn( array $d ): bool => $d['pages'] > 0,
				'do'   => 'Build the homepage. It is the page that decides what the rest of the site looks like, so it '
					. 'is worth more rounds than any other.',
				'why'  => 'A design system settled in the abstract does not survive contact with a real page. Build one, '
					. 'then let what actually emerged become the system.',
			),
			'component_library'  => array(
				'done' => static fn( array $d ): bool => $d['components'] > 0,
				'do'   => 'design_extract_components on the homepage, then design_set_components to keep what is worth '
					. 'reusing. Name each one and say when to use it.',
				'why'  => 'Without this every page writes its own version of a button and a card, and the site stops '
					. 'matching itself by about page four.',
			),
			'header_and_footer'  => array(
				'done' => static fn( array $d ): bool => $d['chrome'],
				'do'   => 'site_set_header_footer, then design_review with no page_id to check it on its own.',
				'why'  => 'It is on every page, so a fault here is a fault everywhere. Build it after the homepage, '
					. 'when the site\'s look is settled and there is a real page to see it against.',
			),
			'other_pages'        => array(
				'done' => static fn( array $d ): bool => $d['pages'] > 1,
				'do'   => 'Build the rest of the pages from the brief, each composed from the component library and each '
					. 'a different shape from the last.',
				'why'  => 'Two pages with the same run of sections make a site feel like a template. And a menu cannot '
					. 'be decided until there is something to put in it.',
			),
			'menu'               => array(
				'done' => static fn( array $d ): bool => $d['menu'],
				'do'   => 'site_set_menu, passing page_id for internal links so they follow a slug change.',
				'why'  => 'A menu can only be decided once the pages exist. Guessing it earlier means writing it twice.',
			),
		);
	}

	/** Whether any of the seven style decisions has been written. */
	private function style_decided(): bool {
		$style = (array) ( $this->plugin->design()->current()->tokens()['style'] ?? array() );

		foreach ( $style as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function what_exists(): array {
		$menus = $this->plugin->menus();

		return array(
			'brand'      => array() !== (array) get_option( 'aiwp_brand_inputs', array() ),
			/*
			 * Not "does a design system exist" — one always does, because the
			 * plugin ships defaults so nothing is ever unstyled. The question
			 * is whether anybody decided anything, and the seven style
			 * decisions are where that shows: they are written in words, they
			 * are blank until somebody writes them, and every page reads them
			 * back.
			 */
			'design'     => $this->style_decided(),
			'pages'      => count( $this->plugin->pages()->content_page_ids() ),
			'components' => $this->plugin->design()->components()->count(),
			'chrome'     => $this->plugin->chrome()->exists(),
			'menu'       => null !== $menus && array() !== array_filter( (array) get_nav_menu_locations() ),
		);
	}

	/**
	 * @return string[]
	 */
	private function finished_before( string $stop ): array {
		$out = array();

		foreach ( array_keys( $this->steps() ) as $id ) {
			if ( $id === $stop ) {
				break;
			}
			$out[] = $id;
		}

		return $out;
	}
}
