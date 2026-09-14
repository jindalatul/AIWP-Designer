<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Security\Sanitizer;

/**
 * Design tokens for the whole site, plus their compiled CSS custom properties.
 */
final class DesignSystem {

	/**
	 * Hosts a design system may load a font stylesheet from.
	 *
	 * Page CSS still cannot load anything remote. This is a site-wide design
	 * decision, made once, not something an individual page can reach for.
	 */
	public const FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.bunny.net' );

	/** @var array<string,mixed> */
	private array $tokens;

	/**
	 * @param array<string,mixed> $tokens
	 */
	public function __construct( array $tokens ) {
		$this->tokens = $tokens;
	}

	public static function defaults(): self {
		return new self(
			array(
				'version'    => 1,
				'colors'     => array(
					'primary'   => '#15253d',
					'secondary' => '#ffffff',
					'accent'    => '#d99b3d',
					'text'      => '#1e1e1e',
					'muted'     => '#707070',
					'surface'   => '#f6f7f9',
					'border'    => '#e2e5ea',
				),
				'typography' => array(
					'heading_font' => 'Inter, system-ui, sans-serif',
					'body_font'    => 'Inter, system-ui, sans-serif',
					'base_size'    => '16px',
					'scale'        => '1.25',
					'font_url'     => '',
				),
				// A real ladder. Five steps is not enough to build a page with,
				// so every page ends up inventing the values in between and the
				// site stops looking like one site.
				'spacing'    => array(
					'3xs' => '4px',
					'2xs' => '8px',
					'xs'  => '12px',
					'sm'  => '16px',
					'md'  => '24px',
					'lg'  => '40px',
					'xl'  => '64px',
					'2xl' => '96px',
					'3xl' => '144px',
				),
				'radius'     => array(
					'small'  => '6px',
					'medium' => '12px',
					'large'  => '24px',
					'full'   => '999px',
				),
				'container'  => array( 'max' => '1200px', 'text' => '68ch' ),
				/*
				 * How this site moves. Three durations, one curve and one
				 * distance, chosen once.
				 *
				 * Without these every page invents its own timing, and a site
				 * ends up with .16s here, .18s there and .22s on the next
				 * section — small enough that nobody can name it, big enough
				 * that the site feels assembled rather than designed. These are
				 * a plain starting point, not a recommendation: the design
				 * system overwrites them the way it overwrites the colours.
				 */
				'motion'     => array(
					'fast'   => '120ms',
					'base'   => '200ms',
					'slow'   => '400ms',
					'ease'   => 'ease',
					'travel' => '16px',
				),
				// The decisions that make this site look like itself and not
				// like every other site. Words, not values: they are read back
				// by every page so page twelve makes the same choices as page one.
				'style'      => array(
					'personality' => '',
					'layout'      => '',
					'surface'     => '',
					'motion'      => '',
					'buttons'     => '',
					'imagery'     => '',
					'signature'   => '',
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	public static function from_array( array $raw ): self {
		$defaults = self::defaults()->tokens();
		$out      = $defaults;

		foreach ( array( 'colors', 'typography', 'spacing', 'radius', 'container', 'motion', 'style' ) as $group ) {
			if ( ! isset( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) {
				continue;
			}
			foreach ( $raw[ $group ] as $key => $value ) {
				$key = Sanitizer::key( $key );
				if ( '' === $key || ! is_scalar( $value ) ) {
					continue;
				}

				if ( 'typography' === $group && 'font_url' === $key ) {
					$out[ $group ][ $key ] = self::clean_font_url( (string) $value );
					continue;
				}

				if ( 'style' === $group ) {
					$out[ $group ][ $key ] = self::clean_note( (string) $value );
					continue;
				}

				$clean = self::clean_value( $group, (string) $value );

				// A motion value we do not recognise keeps the one already
				// there, so a typo slows the site down rather than emitting
				// "--aiwp-motion-ease: ;" and stopping the rule that uses it.
				if ( 'motion' === $group && '' === $clean ) {
					continue;
				}

				$out[ $group ][ $key ] = $clean;
			}
		}

		if ( isset( $raw['version'] ) ) {
			$out['version'] = absint( $raw['version'] );
		}

		return new self( $out );
	}

	/**
	 * A style decision is a sentence the next page has to read, not a CSS value.
	 */
	private static function clean_note( string $value ): string {
		$value = wp_strip_all_tags( trim( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value ) ?? $value;

		return substr( $value, 0, 400 );
	}

	/**
	 * A font stylesheet URL is kept only if it is https and on an allowlisted host.
	 */
	private static function clean_font_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return '';
		}

		if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::FONT_HOSTS, true ) ) {
			return '';
		}

		return esc_url_raw( $value );
	}

	public function font_url(): string {
		$typography = (array) ( $this->tokens['typography'] ?? array() );
		return (string) ( $typography['font_url'] ?? '' );
	}

	private static function clean_value( string $group, string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );

		if ( 'colors' === $group ) {
			if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
				return strtolower( $value );
			}
			if ( preg_match( '/^(rgb|hsl)a?\([0-9,.%\/\s-]+\)$/i', $value ) ) {
				return $value;
			}
			return '#000000';
		}

		if ( 'motion' === $group ) {
			return self::clean_motion( $value );
		}

		// Lengths, font stacks and numbers. No parentheses, no semicolons, no braces.
		$value = preg_replace( '/[{};()<>]/', '', $value ) ?? '';
		return substr( $value, 0, 120 );
	}

	/**
	 * A duration, a length, or an easing curve.
	 *
	 * Every other token has its brackets stripped, because a bracket is how CSS
	 * injection gets in. An easing curve is the one place a site genuinely needs
	 * them — cubic-bezier is what makes motion feel like this site and not the
	 * browser default — so instead of opening the gate, the shapes that are
	 * allowed are written out and nothing else gets through.
	 */
	private static function clean_motion( string $value ): string {
		$value = strtolower( trim( $value ) );

		// A time: 200ms, .2s.
		if ( preg_match( '/^[0-9]*\.?[0-9]+m?s$/', $value ) ) {
			return $value;
		}

		// A distance: 16px, 1rem, 2%.
		if ( preg_match( '/^-?[0-9]*\.?[0-9]+(px|rem|em|%|vh|vw)$/', $value ) ) {
			return $value;
		}

		if ( in_array( $value, array( 'linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end' ), true ) ) {
			return $value;
		}

		if ( preg_match( '/^cubic-bezier\(\s*-?[0-9]*\.?[0-9]+\s*(,\s*-?[0-9]*\.?[0-9]+\s*){3}\)$/', $value ) ) {
			return (string) preg_replace( '/\s+/', '', $value );
		}

		if ( preg_match( '/^steps\(\s*[0-9]+\s*(,\s*(jump-(start|end|none|both)|start|end)\s*)?\)$/', $value ) ) {
			return (string) preg_replace( '/\s+/', '', $value );
		}

		// Not a shape we know. Say nothing rather than write broken CSS.
		return '';
	}

	/**
	 * Merge a partial token set over this one. Groups that are not mentioned keep
	 * their current values, so an update never quietly resets the palette.
	 *
	 * @param array<string,mixed> $raw
	 */
	public function merged_with( array $raw ): self {
		$merged = $this->tokens;

		foreach ( array( 'colors', 'typography', 'spacing', 'radius', 'container', 'style' ) as $group ) {
			if ( isset( $raw[ $group ] ) && is_array( $raw[ $group ] ) ) {
				$merged[ $group ] = array_merge( (array) ( $merged[ $group ] ?? array() ), $raw[ $group ] );
			}
		}

		return self::from_array( $merged );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function tokens(): array {
		return $this->tokens;
	}

	public function version(): int {
		return absint( $this->tokens['version'] ?? 1 );
	}

	public function with_version( int $version ): self {
		$tokens            = $this->tokens;
		$tokens['version'] = $version;
		return new self( $tokens );
	}

	/**
	 * Compile tokens into :root custom properties plus a small, honest baseline.
	 */
	/** Step names, smallest first, with the body size in the middle. */
	private const TYPE_STEPS = array( 'xs' => -2, 'sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5, '5xl' => 6 );

	/**
	 * @param array<string,mixed> $typography
	 * @return array<string,string>
	 */
	private static function type_scale( array $typography ): array {
		$base  = (float) preg_replace( '/[^\d.]/', '', (string) ( $typography['base_size'] ?? '16px' ) );
		$ratio = (float) ( $typography['scale'] ?? 1.25 );

		if ( $base <= 0 ) {
			$base = 16.0;
		}
		if ( $ratio <= 1.0 ) {
			$ratio = 1.25;
		}

		$out = array();
		foreach ( self::TYPE_STEPS as $name => $step ) {
			$out[ $name ] = (string) round( $base * ( $ratio ** $step ), 2 );
		}

		return $out;
	}

	public function to_css(): string {
		$lines = array();

		foreach ( (array) ( $this->tokens['colors'] ?? array() ) as $key => $value ) {
			$lines[] = sprintf( '--aiwp-color-%s: %s;', $key, $value );
		}
		foreach ( (array) ( $this->tokens['spacing'] ?? array() ) as $key => $value ) {
			$lines[] = sprintf( '--aiwp-space-%s: %s;', $key, $value );
		}
		foreach ( (array) ( $this->tokens['radius'] ?? array() ) as $key => $value ) {
			$lines[] = sprintf( '--aiwp-radius-%s: %s;', $key, $value );
		}

		$typography = (array) ( $this->tokens['typography'] ?? array() );
		$lines[]    = sprintf( '--aiwp-font-heading: %s;', $typography['heading_font'] ?? 'Inter' );
		$lines[]    = sprintf( '--aiwp-font-body: %s;', $typography['body_font'] ?? 'Inter' );
		if ( ! empty( $typography['mono_font'] ) ) {
			$lines[] = sprintf( '--aiwp-font-mono: %s;', $typography['mono_font'] );
		}
		$lines[]    = sprintf( '--aiwp-font-size-base: %s;', $typography['base_size'] ?? '16px' );

		/*
		 * The type scale, as real values.
		 *
		 * base_size is the body size, but `rem` is relative to the browser root,
		 * which is 16px and not ours to change without rescaling the whole
		 * theme. So a site with an 18px base that writes `1.02rem` gets 16.3px —
		 * smaller than its own body text, which is the opposite of what was
		 * meant. These variables remove the guess: the scale is computed here,
		 * from base_size and the ratio the design system chose.
		 */
		foreach ( self::type_scale( $typography ) as $name => $size ) {
			$lines[] = sprintf( '--aiwp-text-%s: %spx;', $name, $size );
		}
		$lines[]    = sprintf( '--aiwp-container-max: %s;', ( (array) ( $this->tokens['container'] ?? array() ) )['max'] ?? '1200px' );

		// Motion, so a hover on page twelve takes as long as a hover on page one.
		foreach ( (array) ( $this->tokens['motion'] ?? array() ) as $key => $value ) {
			$lines[] = sprintf( '--aiwp-motion-%s: %s;', $key, $value );
		}

		return ":root{\n  " . implode( "\n  ", $lines ) . "\n}\n";
	}
}
