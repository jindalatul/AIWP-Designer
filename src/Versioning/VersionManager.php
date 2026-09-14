<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Versioning;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Storage\FileStore;

/**
 * Immutable snapshots of every page mutation.
 *
 * Files live on disk under versions/N. The database table is only an index.
 */
final class VersionManager {

	private FileStore $files;
	private PageRepository $pages;

	public function __construct( FileStore $files, PageRepository $pages ) {
		$this->files = $files;
		$this->pages = $pages;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiwp_versions';
	}

	public static function install_table(): void {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_uuid VARCHAR(36) NOT NULL DEFAULT '',
			version INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			workflow_type VARCHAR(64) NOT NULL DEFAULT '',
			manifest LONGTEXT NULL,
			checksum VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY page_version (page_id, version),
			KEY page_uuid (page_uuid),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function next_version( int $page_id ): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE page_id = %d", $page_id ) );

		return absint( $max ) + 1;
	}

	/**
	 * Write one immutable snapshot of everything that makes the page.
	 *
	 * @param array<string,mixed> $artifacts template, css, schema, content, manifest
	 * @return array{success:bool,version:int,checksum:string,error:string}
	 */
	public function snapshot( int $page_id, string $uuid, int $version, array $artifacts, string $workflow_type ): array {
		$payload = array(
			'template.aiwp' => (string) ( $artifacts['template'] ?? '' ),
			'page.css'      => (string) ( $artifacts['css'] ?? '' ),
			// What the author actually wrote, before scoping. The code editor shows
			// this; the browser gets page.css.
			'authored.css'  => (string) ( $artifacts['authored_css'] ?? ( $artifacts['css'] ?? '' ) ),
			'schema.json'   => (string) wp_json_encode( $artifacts['schema'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'content.json'  => (string) wp_json_encode( $artifacts['content'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'manifest.json' => (string) wp_json_encode( $artifacts['manifest'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);

		return $this->snapshot_files(
			$page_id,
			$uuid,
			$version,
			$this->files->page_dir( $uuid, $version ),
			$payload,
			(array) ( $artifacts['manifest'] ?? array() ),
			$workflow_type
		);
	}

	/**
	 * Write an arbitrary set of files as one immutable version, and index it.
	 *
	 * @param array<string,string> $payload  filename => contents
	 * @param array<string,mixed>  $manifest
	 * @return array{success:bool,version:int,checksum:string,error:string}
	 */
	public function snapshot_files( int $page_id, string $uuid, int $version, string $dir, array $payload, array $manifest, string $workflow_type ): array {
		foreach ( $payload as $file => $contents ) {
			if ( ! $this->files->atomic_write( $dir . '/' . $file, $contents ) ) {
				$this->files->delete_dir( $dir );
				return array(
					'success'  => false,
					'version'  => 0,
					'checksum' => '',
					// FileStore worked out why. Saying only "could not write" hands
					// the AI, and whoever it reports to, a dead end.
					'error'    => rtrim( 'AIWP_FILE_WRITE_FAILED: could not write ' . $file . '. ' . $this->files->last_error() ),
				);
			}
		}

		$checksum = hash( 'sha256', implode( '', $payload ) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'page_id'       => $page_id,
				'page_uuid'     => $uuid,
				'version'       => $version,
				'created_at'    => current_time( 'mysql', true ),
				'created_by'    => get_current_user_id(),
				'workflow_type' => substr( $workflow_type, 0, 64 ),
				'manifest'      => (string) wp_json_encode( $manifest ),
				'checksum'      => $checksum,
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$this->files->delete_dir( $dir );
			return array(
				'success'  => false,
				'version'  => 0,
				'checksum' => '',
				'error'    => 'AIWP_FILE_WRITE_FAILED: could not index the version.',
			);
		}

		return array(
			'success'  => true,
			'version'  => $version,
			'checksum' => $checksum,
			'error'    => '',
		);
	}

	/**
	 * Copy a stored version into the page's "current" directory.
	 */
	public function promote( string $uuid, int $version ): bool {
		$from = $this->files->page_dir( $uuid, $version );
		$to   = $this->files->page_dir( $uuid );

		if ( ! is_dir( $from ) ) {
			return false;
		}

		return $this->files->copy_dir( $from, $to );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $page_id ): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT version, created_at, created_by, workflow_type, checksum FROM {$table} WHERE page_id = %d ORDER BY version DESC", $page_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function version_exists( int $page_id, int $version ): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE page_id = %d AND version = %d", $page_id, $version ) );

		return (bool) $found;
	}

	/**
	 * @return array<string,mixed>|null template, css, schema, content, manifest
	 */
	public function read_version( string $uuid, int $version ): ?array {
		$dir = $this->files->page_dir( $uuid, $version );
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$template = $this->files->read( $dir . '/template.aiwp' );
		$css      = $this->files->read( $dir . '/page.css' );
		$schema   = json_decode( (string) $this->files->read( $dir . '/schema.json' ), true );
		$content  = json_decode( (string) $this->files->read( $dir . '/content.json' ), true );
		$manifest = json_decode( (string) $this->files->read( $dir . '/manifest.json' ), true );

		if ( null === $template ) {
			return null;
		}

		return array(
			'template' => $template,
			'css'      => (string) $css,
			'schema'   => is_array( $schema ) ? $schema : array(),
			'content'  => is_array( $content ) ? $content : array(),
			'manifest' => is_array( $manifest ) ? $manifest : array(),
		);
	}

	public function delete_page_versions( int $page_id, string $uuid ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'page_id' => $page_id ), array( '%d' ) );
		$this->files->delete_dir( $this->files->page_dir( $uuid ) );
		$this->files->delete_dir( dirname( $this->files->page_dir( $uuid ) ) );
	}
}
