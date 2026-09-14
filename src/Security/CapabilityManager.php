<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Security;

/**
 * Registers and checks the plugin's own capabilities.
 */
final class CapabilityManager {

	public const MANAGE_DESIGN      = 'aiwp_manage_design';
	public const EDIT_PAGES         = 'aiwp_edit_pages';
	public const PUBLISH_PAGES      = 'aiwp_publish_pages';
	public const MANAGE_CONNECTIONS = 'aiwp_manage_connections';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_DESIGN,
			self::EDIT_PAGES,
			self::PUBLISH_PAGES,
			self::MANAGE_CONNECTIONS,
		);
	}

	public static function add_capabilities(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( self::EDIT_PAGES );
		}
	}

	public static function remove_capabilities(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	public static function current_user_can( string $cap ): bool {
		return current_user_can( $cap );
	}
}
