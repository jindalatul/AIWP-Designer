<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

/**
 * Reads colour values out of CSS and compares them.
 */
final class Color {

	/** Words that are always fine and are not part of a palette. */
	public const NEUTRAL_KEYWORDS = array(
		'transparent', 'currentcolor', 'inherit', 'initial', 'unset', 'none', 'revert',
	);

	/**
	 * Every colour written in a value, as lowercase source text.
	 *
	 * @return string[]
	 */
	public static function find_all( string $value ): array {
		$found = array();

		if ( preg_match_all( '/#[0-9a-f]{3,8}\b/i', $value, $m ) ) {
			foreach ( $m[0] as $hex ) {
				$found[] = strtolower( $hex );
			}
		}

		if ( preg_match_all( '/\b(?:rgba?|hsla?)\([^)]*\)/i', $value, $m ) ) {
			foreach ( $m[0] as $fn ) {
				$found[] = strtolower( preg_replace( '/\s+/', '', $fn ) ?? $fn );
			}
		}

		return $found;
	}

	/**
	 * A colour as red, green and blue in 0-255, or null when it cannot be read.
	 *
	 * @return array{0:int,1:int,2:int}|null
	 */
	public static function to_rgb( string $color ): ?array {
		$color = strtolower( trim( $color ) );

		if ( 'white' === $color ) {
			return array( 255, 255, 255 );
		}
		if ( 'black' === $color ) {
			return array( 0, 0, 0 );
		}

		if ( 0 === strpos( $color, '#' ) ) {
			$hex = substr( $color, 1 );

			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( 6 !== strlen( $hex ) && 8 !== strlen( $hex ) ) {
				return null;
			}

			if ( ! ctype_xdigit( substr( $hex, 0, 6 ) ) ) {
				return null;
			}

			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		if ( preg_match( '/^rgba?\(([^)]*)\)$/', $color, $m ) ) {
			$parts = preg_split( '/[\s,\/]+/', trim( $m[1] ) ) ?: array();
			if ( count( $parts ) < 3 ) {
				return null;
			}

			$rgb = array();
			for ( $i = 0; $i < 3; $i++ ) {
				$piece = trim( (string) $parts[ $i ] );
				if ( '' === $piece || ! is_numeric( rtrim( $piece, '%' ) ) ) {
					return null;
				}
				$number = (float) rtrim( $piece, '%' );
				$rgb[]  = (int) round( str_ends_with( $piece, '%' ) ? $number * 2.55 : $number );
			}

			return array(
				max( 0, min( 255, $rgb[0] ) ),
				max( 0, min( 255, $rgb[1] ) ),
				max( 0, min( 255, $rgb[2] ) ),
			);
		}

		return null;
	}

	/**
	 * The alpha of a colour, 1.0 when it has none.
	 */
	public static function alpha( string $color ): float {
		$color = strtolower( trim( $color ) );

		if ( preg_match( '/^#([0-9a-f]{8})$/i', $color, $m ) ) {
			return hexdec( substr( $m[1], 6, 2 ) ) / 255;
		}
		if ( preg_match( '/^#([0-9a-f]{4})$/i', $color, $m ) ) {
			return hexdec( $m[1][3] . $m[1][3] ) / 255;
		}
		if ( preg_match( '/^rgba\(([^)]*)\)$/', $color, $m ) ) {
			$parts = preg_split( '/[\s,\/]+/', trim( $m[1] ) ) ?: array();
			if ( count( $parts ) >= 4 && is_numeric( $parts[3] ) ) {
				return (float) $parts[3];
			}
		}

		return 1.0;
	}

	/**
	 * WCAG contrast ratio, 1.0 (same) to 21.0 (black on white).
	 */
	public static function contrast( string $a, string $b ): ?float {
		$one = self::to_rgb( $a );
		$two = self::to_rgb( $b );

		if ( null === $one || null === $two ) {
			return null;
		}

		$la = self::luminance( $one );
		$lb = self::luminance( $two );

		$light = max( $la, $lb );
		$dark  = min( $la, $lb );

		return round( ( $light + 0.05 ) / ( $dark + 0.05 ), 2 );
	}

	/**
	 * How far apart two colours are, 0 (same) to about 442.
	 */
	public static function distance( string $a, string $b ): ?float {
		$one = self::to_rgb( $a );
		$two = self::to_rgb( $b );

		if ( null === $one || null === $two ) {
			return null;
		}

		return round(
			sqrt(
				( $one[0] - $two[0] ) ** 2 +
				( $one[1] - $two[1] ) ** 2 +
				( $one[2] - $two[2] ) ** 2
			),
			2
		);
	}

	/**
	 * @param array{0:int,1:int,2:int} $rgb
	 */
	private static function luminance( array $rgb ): float {
		$channels = array();

		foreach ( $rgb as $value ) {
			$c          = $value / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
