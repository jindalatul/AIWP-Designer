<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Security;

/**
 * Shared input sanitising helpers.
 */
final class Sanitizer {

	/** Maximum accepted size of any single generated artefact (template or CSS). */
	public const MAX_ARTIFACT_BYTES = 512000;

	public static function text( mixed $value ): string {
		return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
	}

	public static function key( mixed $value ): string {
		$value = strtolower( (string) ( is_scalar( $value ) ? $value : '' ) );
		$value = preg_replace( '/[^a-z0-9_]/', '_', $value ) ?? '';
		$value = preg_replace( '/_+/', '_', $value ) ?? '';
		return trim( $value, '_' );
	}

	/**
	 * "hero.headline" -> "hero.headline" (validated), rejects anything else.
	 */
	public static function field_path( mixed $value ): string {
		$value = (string) ( is_scalar( $value ) ? $value : '' );
		if ( ! preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+){0,2}$/i', $value ) ) {
			return '';
		}
		return strtolower( $value );
	}

	public static function uuid(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Rejects path traversal, absolute paths and null bytes.
	 */
	public static function is_safe_segment( string $segment ): bool {
		if ( '' === $segment ) {
			return false;
		}
		if ( false !== strpos( $segment, "\0" ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_\-]+$/', $segment );
	}

	public static function too_large( string $payload ): bool {
		return strlen( $payload ) > self::MAX_ARTIFACT_BYTES;
	}
}
