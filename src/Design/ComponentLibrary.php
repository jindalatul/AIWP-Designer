<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Security\Sanitizer;

/**
 * The set of components this site is built from.
 *
 * Before this existed, components lived inside the global stylesheet as one
 * opaque blob. Nothing could answer "what does this site already have?", so
 * every page re-read the CSS, guessed, and quietly invented its own button.
 * That is why page twelve stops matching page one.
 *
 * Here each component is addressable: what it is called, when to use it, and
 * the CSS behind it. Pages compose from this list. A page that needs something
 * new adds it here, where the next page can find it.
 *
 * Nothing in this class says what a component should look like. The site's own
 * AI writes the list, so a law firm and a skate shop share the mechanism and
 * nothing else.
 */
final class ComponentLibrary {

	public const MAX_COMPONENTS = 80;

	/** @var array<int,array{id:string,name:string,use_when:string,css:string,markup:string}> */
	private array $components;

	/**
	 * @param array<int,array{id:string,name:string,use_when:string,css:string,markup:string}> $components
	 */
	private function __construct( array $components ) {
		$this->components = $components;
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * @param array<int,mixed> $raw
	 */
	public static function from_array( array $raw ): self {
		$out  = array();
		$seen = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = Sanitizer::key( $entry['id'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$out[] = array(
				'id'       => $id,
				'name'     => self::note( $entry['name'] ?? $id, 80 ),
				'use_when' => self::note( $entry['use_when'] ?? '', 300 ),
				'css'      => trim( (string) ( $entry['css'] ?? '' ) ),
				'markup'   => trim( (string) ( $entry['markup'] ?? '' ) ),
			);

			if ( count( $out ) >= self::MAX_COMPONENTS ) {
				break;
			}
		}

		return new self( $out );
	}

	/**
	 * @return array<int,array{id:string,name:string,use_when:string,css:string,markup:string}>
	 */
	public function to_array(): array {
		return $this->components;
	}

	public function count(): int {
		return count( $this->components );
	}

	public function is_empty(): bool {
		return array() === $this->components;
	}

	/**
	 * Every component's CSS, in order, each labelled so the stylesheet stays readable.
	 */
	public function css(): string {
		$parts = array();

		foreach ( $this->components as $component ) {
			if ( '' === $component['css'] ) {
				continue;
			}

			$parts[] = sprintf(
				"/* %s — %s */\n%s",
				$component['id'],
				'' !== $component['use_when'] ? $component['use_when'] : $component['name'],
				$component['css']
			);
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The class names this library defines, so a page can be asked whether it
	 * is composing from them or writing its own.
	 *
	 * @return string[]
	 */
	public function class_names(): array {
		$found = array();

		foreach ( $this->components as $component ) {
			if ( preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', $component['css'], $m ) ) {
				foreach ( $m[1] as $class ) {
					$found[ $class ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/**
	 * The list without the CSS, which is what an AI needs to choose one.
	 *
	 * @return array<int,array{id:string,name:string,use_when:string,has_markup:bool}>
	 */
	public function index(): array {
		return array_map(
			static fn( array $c ): array => array(
				'id'         => $c['id'],
				'name'       => $c['name'],
				'use_when'   => $c['use_when'],
				'has_markup' => '' !== $c['markup'],
			),
			$this->components
		);
	}

	/**
	 * Add or replace components, keeping everything not mentioned.
	 *
	 * @param array<int,mixed> $raw
	 */
	public function merged_with( array $raw ): self {
		$incoming = self::from_array( $raw )->to_array();
		$byId     = array();

		foreach ( $this->components as $component ) {
			$byId[ $component['id'] ] = $component;
		}

		foreach ( $incoming as $component ) {
			$byId[ $component['id'] ] = $component;
		}

		return new self( array_slice( array_values( $byId ), 0, self::MAX_COMPONENTS ) );
	}

	/**
	 * @param string[] $ids
	 */
	public function without( array $ids ): self {
		$drop = array_flip( array_map( static fn( $id ): string => Sanitizer::key( $id ), $ids ) );

		return new self(
			array_values(
				array_filter(
					$this->components,
					static fn( array $c ): bool => ! isset( $drop[ $c['id'] ] )
				)
			)
		);
	}

	private static function note( mixed $value, int $limit ): string {
		$value = wp_strip_all_tags( trim( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;

		return substr( $value, 0, $limit );
	}
}
