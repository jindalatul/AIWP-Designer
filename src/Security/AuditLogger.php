<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Security;

/**
 * Append-only action log. Never stores tokens or secrets.
 */
final class AuditLogger {

	private const OPTION = 'aiwp_audit_log';
	private const LIMIT  = 300;

	/**
	 * @param array<string,mixed> $context
	 */
	public static function log( string $event, array $context = array() ): void {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'event'   => sanitize_key( $event ),
			'user'    => get_current_user_id(),
			'time'    => gmdate( 'c' ),
			'context' => self::scrub( $context ),
		);

		if ( count( $entries ) > self::LIMIT ) {
			$entries = array_slice( $entries, -self::LIMIT );
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $count = 20 ): array {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_slice( array_reverse( $entries ), 0, $count );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function scrub( array $context ): array {
		$blocked = array( 'token', 'secret', 'password', 'authorization', 'bearer', 'hash' );
		$out     = array();
		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );
			foreach ( $blocked as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					continue 2;
				}
			}
			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = wp_json_encode( $value );
			}
		}
		return $out;
	}
}
