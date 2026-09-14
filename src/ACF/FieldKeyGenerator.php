<?php
declare( strict_types = 1 );

namespace AIWP\Designer\ACF;

/**
 * Stable ACF keys. Keys are derived from immutable UUIDs and field ids, so a
 * label change never moves stored content.
 */
final class FieldKeyGenerator {

	public static function group_key( string $page_uuid, string $section_id ): string {
		return 'group_aiwp_' . self::slug( $page_uuid ) . '_' . self::slug( $section_id );
	}

	public static function field_key( string $page_uuid, string $field_id ): string {
		return 'field_aiwp_' . self::slug( $page_uuid ) . '_' . self::slug( $field_id );
	}

	public static function sub_field_key( string $page_uuid, string $field_id, string $sub_id ): string {
		return 'field_aiwp_' . self::slug( $page_uuid ) . '_' . self::slug( $field_id ) . '_' . self::slug( $sub_id );
	}

	/**
	 * What two fields would have to share before they collide.
	 *
	 * The path "hero.sub_title" and the path "hero_sub.title" are different
	 * fields, but both are stored as hero_sub_title: the dot becomes an
	 * underscore, and the key drops separators altogether. Whoever typed the
	 * second one would silently overwrite the first. Nothing can be done about
	 * it in the key itself — ids are [a-z0-9_], so there is no character left
	 * to separate with — so instead we hand out this fingerprint and let the
	 * schema refuse the pair before either is stored.
	 */
	public static function fingerprint( string $section_id, string $field_id ): string {
		return self::slug( $section_id . '_' . $field_id );
	}

	/** The same, for one row inside a repeating list. */
	public static function sub_fingerprint( string $section_id, string $field_id, string $sub_id ): string {
		return self::fingerprint( $section_id, $field_id ) . '_' . self::slug( $sub_id );
	}

	private static function slug( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]/', '', $value ) ?? '';
		// Long UUIDs would make unwieldy keys; a stable prefix + hash keeps them short and unique.
		if ( strlen( $value ) > 16 ) {
			$value = substr( $value, 0, 8 ) . substr( md5( $value ), 0, 8 );
		}
		return $value;
	}
}
