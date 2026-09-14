<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

/**
 * Finds the components hiding inside a page's CSS.
 *
 * The intended way to build a site is: design the first page properly, then
 * build the rest in that language. That only works if the language written into
 * the first page can be got back out. Otherwise everything the homepage
 * invented stays locked in the homepage's own stylesheet and page two starts
 * from nothing.
 *
 * This proposes. It does not decide. It has no idea what a thing is for, so it
 * reports what it found and the AI names it, writes its use_when line, and
 * stores it. Naming is the part that makes a library usable, and naming needs
 * to know what the business does.
 */
final class ComponentExtractor {

	/** A rule needs this many defining properties before it looks like a component. */
	private const SURFACE_PROPERTIES = array(
		'background', 'background-color', 'border', 'border-top', 'border-bottom',
		'border-left', 'border-right', 'border-width', 'border-radius',
		'box-shadow', 'padding', 'color',
	);

	/*
	 * A component does not have to be a box.
	 *
	 * Looking only for background, border, radius and shadow finds cards and
	 * buttons and nothing else — so a site whose language is typographic
	 * reports that it has no components at all, and then gets told off for
	 * having none. On a bindery whose design system says "nothing but paper
	 * and space", that was every rule on the page.
	 *
	 * A figure set large in a colour beside a caption is a component. It is
	 * reused, it carries meaning, and another page should be able to reach for
	 * it. These are what says so.
	 */
	private const TYPOGRAPHIC_PROPERTIES = array(
		'font-family', 'font-size', 'font-weight', 'letter-spacing',
		'text-transform', 'line-height', 'font-style', 'text-decoration',
		'text-decoration-thickness', 'text-underline-offset',
	);

	private CSSReader $css;

	private ComponentLibrary $existing;

	public function __construct( string $css, ?ComponentLibrary $existing = null ) {
		$this->css      = new CSSReader( $css );
		$this->existing = $existing ?? ComponentLibrary::empty();
	}

	/**
	 * Blocks of rules that share a class prefix and look like a piece of design.
	 *
	 * @return array<int,array{suggested_id:string,base_class:string,selectors:string[],css:string,has_states:bool,already_in_library:bool}>
	 */
	public function propose(): array {
		$known  = array_flip( $this->existing->class_names() );
		$groups = array();

		foreach ( $this->css->rules() as $rule ) {
			$base = $this->base_class( $rule['selector'] );
			if ( '' === $base ) {
				continue;
			}

			if ( ! isset( $groups[ $base ] ) ) {
				$groups[ $base ] = array(
					'selectors' => array(),
					'rules'     => array(),
					'surface'   => 0,
					'type'      => 0,
					'states'    => false,
				);
			}

			$groups[ $base ]['selectors'][] = $rule['selector'];
			$groups[ $base ]['rules'][]     = $rule;

			foreach ( self::SURFACE_PROPERTIES as $property ) {
				if ( isset( $rule['declarations'][ $property ] ) ) {
					++$groups[ $base ]['surface'];
				}
			}

			foreach ( self::TYPOGRAPHIC_PROPERTIES as $property ) {
				if ( isset( $rule['declarations'][ $property ] ) ) {
					++$groups[ $base ]['type'];
				}
			}

			if ( false !== strpos( $rule['selector'], ':hover' ) || false !== strpos( $rule['selector'], ':focus' ) ) {
				$groups[ $base ]['states'] = true;
			}
		}

		$out = array();

		foreach ( $groups as $base => $group ) {
			// Either kind of definition counts, and a piece that is both is
			// stronger than one that is neither.
			$defined = $group['surface'] + $group['type'];

			// One rule that only sets a colour is not a component, it is a tweak.
			if ( $defined < 2 ) {
				continue;
			}

			if ( 1 === count( $group['rules'] ) && $defined < 3 ) {
				continue;
			}

			$out[] = array(
				'suggested_id'       => $this->suggest_id( $base ),
				'base_class'         => $base,
				'selectors'          => array_values( array_unique( $group['selectors'] ) ),
				'css'                => $this->rebuild( $group['rules'] ),
				'has_states'         => $group['states'],
				'already_in_library' => isset( $known[ $base ] ),
			);
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => count( $b['selectors'] ) <=> count( $a['selectors'] )
		);

		return $out;
	}

	/**
	 * The class a group of rules is built around.
	 *
	 * `.cr-btn`, `.cr-btn:hover` and `.cr-btn--quiet` are one component.
	 * `.cr-hero .cr-btn` is the hero placing a button, not a new component.
	 */
	private function base_class( string $selector ): string {
		$selector = trim( $selector );

		// A selector with a descendant, child or sibling part is placement.
		if ( preg_match( '/[\s>+~]/', preg_replace( '/\([^)]*\)/', '', $selector ) ?? $selector ) ) {
			return '';
		}

		if ( ! preg_match( '/^\.(-?[_a-zA-Z][\w-]*)/', $selector, $m ) ) {
			return '';
		}

		$class = $m[1];

		// Treat a BEM modifier or element as part of its block.
		foreach ( array( '--', '__' ) as $separator ) {
			$at = strpos( $class, $separator );
			if ( false !== $at && $at > 0 ) {
				$class = substr( $class, 0, $at );
			}
		}

		return $class;
	}

	private function suggest_id( string $base ): string {
		// Drop a site prefix like "cr-" so the id reads as what the thing is.
		$id = preg_replace( '/^[a-z]{1,3}-/', '', $base ) ?? $base;

		return '' !== $id ? $id : $base;
	}

	/**
	 * @param array<int,array{selector:string,media:string,declarations:array<string,string>}> $rules
	 */
	private function rebuild( array $rules ): string {
		$out    = array();
		$medias = array();

		foreach ( $rules as $rule ) {
			$body = '';
			foreach ( $rule['declarations'] as $property => $value ) {
				$body .= sprintf( "  %s: %s;\n", $property, $value );
			}

			$block = sprintf( "%s {\n%s}", $rule['selector'], $body );

			if ( '' === $rule['media'] ) {
				$out[] = $block;
				continue;
			}

			$medias[ $rule['media'] ][] = $block;
		}

		foreach ( $medias as $query => $blocks ) {
			$out[] = sprintf( "%s {\n%s\n}", $query, implode( "\n", $blocks ) );
		}

		return implode( "\n\n", $out );
	}
}
