<?php
/**
 * Minimal PSR-4 autoloader so the plugin runs without `composer install`.
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AIWP\\Designer\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
