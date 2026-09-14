<?php
declare( strict_types = 1 );

namespace AIWP\Designer\ACF;

/**
 * Everything the plugin needs to know about the ACF installation at runtime.
 *
 * ACF is never assumed to be present. Every call is guarded.
 */
final class ACFManager {

	public static function is_available(): bool {
		return function_exists( 'acf_get_field_type' ) && function_exists( 'acf_add_local_field_group' );
	}

	public static function version(): string {
		if ( defined( 'ACF_VERSION' ) ) {
			return (string) ACF_VERSION;
		}
		return '';
	}

	public static function is_pro(): bool {
		return defined( 'ACF_PRO' ) && ACF_PRO;
	}

	public static function field_type_exists( string $type ): bool {
		if ( ! function_exists( 'acf_get_field_type' ) ) {
			return false;
		}
		return (bool) acf_get_field_type( $type );
	}

	/**
	 * The field types this site can actually use right now.
	 *
	 * @return string[]
	 */
	public static function available_field_types(): array {
		$candidates = \AIWP\Designer\Pages\PageSchema::SUPPORTED_TYPES;
		$available  = array();

		foreach ( $candidates as $type ) {
			if ( self::field_type_exists( $type ) ) {
				$available[] = $type;
			}
		}

		return $available;
	}
}
