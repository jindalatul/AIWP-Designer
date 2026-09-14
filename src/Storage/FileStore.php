<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Storage;

use AIWP\Designer\Security\Sanitizer;

/**
 * Owns every generated file under wp-content/uploads/aiwp-designer/.
 *
 * AI never supplies a path. Callers pass UUIDs and version numbers; this class
 * decides the physical location and refuses anything that escapes the root.
 */
final class FileStore {

	private string $root;
	private string $root_url;

	public function __construct() {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'aiwp-designer';
		$base_u  = trailingslashit( $uploads['baseurl'] ) . 'aiwp-designer';

		if ( is_multisite() ) {
			$base   .= '/site-' . get_current_blog_id();
			$base_u .= '/site-' . get_current_blog_id();
		}

		$this->root     = $base;
		$this->root_url = $base_u;
	}

	public function root(): string {
		return $this->root;
	}

	public function root_url(): string {
		return $this->root_url;
	}

	/** Why the last write failed, in words somebody can act on. */
	private string $last_error = '';

	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * Work out why a directory cannot be written to, and say so.
	 *
	 * "Could not write the design system" is true and useless. On a fresh
	 * install the cause is almost always that the uploads folder belongs to a
	 * different user than the one PHP runs as — which happens whenever a plugin
	 * is activated with WP-CLI as root and then used through the web server.
	 */
	private function explain( string $dir ): string {
		$existing = $dir;
		while ( ! file_exists( $existing ) && dirname( $existing ) !== $existing ) {
			$existing = dirname( $existing );
		}

		$owner = '';
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'fileowner' ) ) {
			$info  = @posix_getpwuid( (int) @fileowner( $existing ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$owner = is_array( $info ) ? (string) ( $info['name'] ?? '' ) : '';
		}

		$running = '';
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$info    = @posix_getpwuid( posix_geteuid() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$running = is_array( $info ) ? (string) ( $info['name'] ?? '' ) : '';
		}

		$perms = file_exists( $existing ) ? substr( sprintf( '%o', @fileperms( $existing ) ), -4 ) : '????'; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$message = sprintf( 'Cannot write inside %s.', $existing );

		if ( '' !== $owner && '' !== $running && $owner !== $running ) {
			$message .= sprintf(
				' It belongs to "%s" but PHP is running as "%s", so the web server cannot write there.'
					. ' Fix it with: chown -R %s "%s"',
				$owner,
				$running,
				$running,
				$existing
			);
		} else {
			$message .= sprintf( ' Permissions are %s. The web server needs write access to it.', $perms );
		}

		return $message;
	}

	/**
	 * Can the plugin actually save anything? Tried for real, not guessed from
	 * is_writable(), which lies often enough to be worth not trusting.
	 */
	public function can_write(): bool {
		$probe = $this->root . '/cache/.writable';

		if ( ! $this->atomic_write( $probe, 'ok' ) ) {
			return false;
		}

		wp_delete_file( $probe );

		return true;
	}

	public function ensure_structure(): bool {
		$dirs = array(
			$this->root,
			$this->root . '/design/current',
			$this->root . '/design/versions',
			$this->root . '/pages',
			$this->root . '/cache',
		);

		foreach ( $dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
		}

		// Directory listings off; PHP never executed from here.
		$index = $this->root . '/index.php';
		if ( ! file_exists( $index ) ) {
			$this->atomic_write( $index, "<?php // Silence is golden.\n" );
		}

		$htaccess = $this->root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<FilesMatch \"\\.(php|php\\d|phtml|phar)$\">\n"
				. "  Require all denied\n"
				. "</FilesMatch>\n";
			$this->atomic_write( $htaccess, $rules );
		}

		return true;
	}

	public function page_dir( string $page_uuid, ?int $version = null ): string {
		$uuid = $this->safe_uuid( $page_uuid );
		$dir  = $this->root . '/pages/' . $uuid;
		return null === $version ? $dir . '/current' : $dir . '/versions/' . absint( $version );
	}

	public function design_dir( ?int $version = null ): string {
		return null === $version
			? $this->root . '/design/current'
			: $this->root . '/design/versions/' . absint( $version );
	}

	public function read( string $path ): ?string {
		if ( ! $this->is_inside_root( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $contents ? null : $contents;
	}

	/**
	 * Writes to a temp file, then renames. A half-written template never goes live.
	 */
	public function atomic_write( string $path, string $contents ): bool {
		$this->last_error = '';

		if ( ! $this->is_inside_root( $path ) ) {
			$this->last_error = sprintf( 'Refused to write outside the folder this plugin owns: %s', $path );
			return false;
		}

		$dir = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			$this->last_error = $this->explain( $dir );
			return false;
		}

		$tmp = $dir . '/.tmp-' . wp_generate_password( 12, false, false );

		// phpcs:disable WordPress.WP.AlternativeFunctions
		$handle = fopen( $tmp, 'wb' );
		if ( false === $handle ) {
			$this->last_error = $this->explain( $dir );
			return false;
		}
		$written = fwrite( $handle, $contents );
		if ( false !== $written ) {
			fflush( $handle );
		}
		fclose( $handle );
		// phpcs:enable

		if ( false === $written || strlen( $contents ) !== $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		return true;
	}

	public function copy_dir( string $from, string $to ): bool {
		if ( ! $this->is_inside_root( $from ) || ! $this->is_inside_root( $to ) ) {
			return false;
		}
		if ( ! is_dir( $from ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $to ) ) {
			return false;
		}

		$entries = scandir( $from );
		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$src = $from . '/' . $entry;
			$dst = $to . '/' . $entry;
			if ( is_dir( $src ) ) {
				if ( ! $this->copy_dir( $src, $dst ) ) {
					return false;
				}
				continue;
			}
			$contents = $this->read( $src );
			if ( null === $contents || ! $this->atomic_write( $dst, $contents ) ) {
				return false;
			}
		}

		return true;
	}

	public function delete_dir( string $dir ): bool {
		if ( ! $this->is_inside_root( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->delete_dir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public function path_to_url( string $path ): string {
		if ( ! $this->is_inside_root( $path ) ) {
			return '';
		}
		$relative = substr( $path, strlen( $this->root ) );
		return $this->root_url . str_replace( '\\', '/', $relative );
	}

	/**
	 * Every write and read goes through here. No "../", no absolute escape.
	 */
	public function is_inside_root( string $path ): bool {
		if ( false !== strpos( $path, "\0" ) ) {
			return false;
		}
		if ( false !== strpos( $path, '..' ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $path );
		$root       = str_replace( '\\', '/', $this->root );

		return 0 === strpos( $normalized, $root . '/' ) || $normalized === $root;
	}

	private function safe_uuid( string $uuid ): string {
		$uuid = strtolower( trim( $uuid ) );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $uuid ) ) {
			// Never build a path from something that is not a UUID we generated.
			return 'invalid-' . md5( $uuid );
		}
		return $uuid;
	}
}
