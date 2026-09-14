<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\Storage\FileStore;

/**
 * Reads and writes the AIWP metadata attached to ordinary WordPress pages.
 */
final class PageRepository {

	public const META_ENABLED        = '_aiwp_enabled';
	public const META_UUID           = '_aiwp_page_uuid';
	public const META_SCHEMA         = '_aiwp_schema';
	public const META_MANIFEST       = '_aiwp_manifest';
	public const META_ACTIVE_VERSION = '_aiwp_active_version';
	public const META_DESIGN_VERSION = '_aiwp_design_version';
	/** The version the AI last read back with page_look. */
	public const META_LOOKED_AT      = '_aiwp_looked_at';

	private FileStore $files;

	public function __construct( FileStore $files ) {
		$this->files = $files;
	}

	public function is_aiwp_page( int $page_id ): bool {
		return '1' === (string) get_post_meta( $page_id, self::META_ENABLED, true );
	}

	public function uuid( int $page_id ): string {
		return (string) get_post_meta( $page_id, self::META_UUID, true );
	}

	public function active_version( int $page_id ): int {
		return absint( get_post_meta( $page_id, self::META_ACTIVE_VERSION, true ) );
	}

	public function design_version( int $page_id ): int {
		return absint( get_post_meta( $page_id, self::META_DESIGN_VERSION, true ) );
	}

	public function schema( int $page_id ): ?PageSchema {
		$raw = get_post_meta( $page_id, self::META_SCHEMA, true );
		if ( ! is_array( $raw ) || array() === $raw ) {
			return null;
		}
		return PageSchema::from_array( $raw );
	}

	public function manifest( int $page_id ): PageManifest {
		$raw = get_post_meta( $page_id, self::META_MANIFEST, true );
		return new PageManifest( is_array( $raw ) ? $raw : array() );
	}

	public function save_schema( int $page_id, PageSchema $schema ): void {
		update_post_meta( $page_id, self::META_SCHEMA, $schema->to_array() );
	}

	public function save_manifest( int $page_id, PageManifest $manifest ): void {
		update_post_meta( $page_id, self::META_MANIFEST, $manifest->to_array() );
	}

	public function mark_enabled( int $page_id, string $uuid ): void {
		update_post_meta( $page_id, self::META_ENABLED, '1' );
		update_post_meta( $page_id, self::META_UUID, $uuid );
	}

	public function set_active_version( int $page_id, int $version ): void {
		update_post_meta( $page_id, self::META_ACTIVE_VERSION, $version );
	}

	public function set_design_version( int $page_id, int $version ): void {
		update_post_meta( $page_id, self::META_DESIGN_VERSION, $version );
	}

	public function find_by_uuid( string $uuid ): int {
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_UUID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $uuid,           // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
			)
		);
		return $pages ? (int) $pages[0] : 0;
	}

	/**
	 * @return int[]
	 */
	public function all_page_ids(): array {
		$ids = wp_cache_get( 'aiwp_page_ids' );
		if ( is_array( $ids ) ) {
			return $ids;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'any',
				'numberposts'      => 500,
				'fields'           => 'ids',
				'meta_key'         => self::META_ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => '1',                // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
			)
		);

		$ids = array_map( 'intval', (array) $ids );
		wp_cache_set( 'aiwp_page_ids', $ids, '', 60 );

		return $ids;
	}

	/**
	 * Pages a person would call pages: the hidden header/footer holder is not one.
	 *
	 * @return int[]
	 */
	public function content_page_ids(): array {
		return array_values(
			array_filter(
				$this->all_page_ids(),
				static fn( int $page_id ): bool => '1' !== (string) get_post_meta( $page_id, \AIWP\Designer\Chrome\ChromeManager::META_CHROME, true )
			)
		);
	}

	public function flush_page_list_cache(): void {
		wp_cache_delete( 'aiwp_page_ids' );
	}

	// ------------------------------------------------------- generated files

	public function template( int $page_id, ?int $version = null ): string {
		return $this->read_artifact( $page_id, 'template.aiwp', $version );
	}

	public function css( int $page_id, ?int $version = null ): string {
		return $this->read_artifact( $page_id, 'page.css', $version );
	}

	/**
	 * The CSS as it was written, before the plugin scoped it to this page.
	 */
	public function authored_css( int $page_id, ?int $version = null ): string {
		$css = $this->read_artifact( $page_id, 'authored.css', $version );

		if ( '' !== $css ) {
			return $css;
		}

		// Older versions kept only the scoped copy, which has no line breaks.
		return \AIWP\Designer\Design\CSSValidator::format( $this->css( $page_id, $version ) );
	}

	public function css_url( int $page_id ): string {
		$path = $this->files->page_dir( $this->uuid( $page_id ) ) . '/page.css';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return $this->files->path_to_url( $path );
	}

	public function css_path( int $page_id ): string {
		return $this->files->page_dir( $this->uuid( $page_id ) ) . '/page.css';
	}

	private function read_artifact( int $page_id, string $file, ?int $version ): string {
		$uuid = $this->uuid( $page_id );
		if ( '' === $uuid ) {
			return '';
		}
		return $this->files->read( $this->files->page_dir( $uuid, $version ) . '/' . $file ) ?? '';
	}

	public function files(): FileStore {
		return $this->files;
	}
}
