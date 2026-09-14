<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Transfer;

use AIWP\Designer\Media\ImageImporter;
use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Plugin;
use AIWP\Designer\Storage\FileStore;
use ZipArchive;

/**
 * Unpacks a site exported by SiteExporter onto this WordPress install.
 *
 * The hard part is not the files, it is the numbers. A page's content points
 * at images by attachment id, menus point at pages by post id, and the site
 * root points at a page by id — and every one of those ids means something
 * different here. So media goes in first, the old id to new id map is kept,
 * and everything is rewritten on the way in.
 *
 * A page is matched by the id it travels under, so importing the same zip
 * twice updates the same pages instead of making a second copy of the site.
 */
final class SiteImporter {

	private Plugin $plugin;
	private FileStore $files;
	private PageRepository $pages;

	/** @var string[] */
	private array $problems = array();

	/** @var string[] */
	private array $done = array();

	/** @var array<int,int> old attachment id => new attachment id */
	private array $media_map = array();

	/** @var array<string,int> page uuid => new post id */
	private array $page_map = array();

	private string $source_url = '';

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->files  = $plugin->files();
		$this->pages  = $plugin->pages();
	}

	/** @return string[] */
	public function problems(): array {
		return $this->problems;
	}

	/** @return string[] */
	public function done(): array {
		return $this->done;
	}

	/**
	 * What is in the file, without changing anything.
	 *
	 * @return array<string,mixed>|null
	 */
	public function inspect( string $zip_path ): ?array {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			$this->problems[] = 'That file could not be opened. It may not be a zip.';
			return null;
		}

		$manifest = json_decode( (string) $zip->getFromName( 'aiwp-site.json' ), true );

		if ( ! is_array( $manifest ) || ! isset( $manifest['format'] ) ) {
			$this->problems[] = 'That zip was not made by AIWP Designer.';
			$zip->close();
			return null;
		}

		if ( (int) $manifest['format'] > 1 ) {
			$this->problems[] = sprintf(
				'That file was written by a newer version of the plugin (format %d). Update this site first.',
				(int) $manifest['format']
			);
			$zip->close();
			return null;
		}

		$pages    = (array) json_decode( (string) $zip->getFromName( 'pages.json' ), true );
		$articles = (array) json_decode( (string) $zip->getFromName( 'articles.json' ), true );

		$new = 0;
		foreach ( $pages as $page ) {
			if ( 0 === $this->find_by_uuid( (string) ( $page['uuid'] ?? '' ) ) ) {
				++$new;
			}
		}

		$zip->close();

		return array(
			'manifest' => $manifest,
			'pages'    => count( $pages ),
			'new'      => $new,
			'updating' => count( $pages ) - $new,
			'articles' => count( $articles ),
		);
	}

	/**
	 * Does the work. Returns false if it could not start.
	 */
	public function run( string $zip_path ): bool {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			$this->problems[] = 'That file could not be opened.';
			return false;
		}

		$manifest = json_decode( (string) $zip->getFromName( 'aiwp-site.json' ), true );

		if ( ! is_array( $manifest ) || ! isset( $manifest['format'] ) || (int) $manifest['format'] > 1 ) {
			$this->problems[] = 'That zip was not made by this plugin, or was made by a newer version of it.';
			$zip->close();
			return false;
		}

		$this->source_url = untrailingslashit( (string) ( $manifest['source_url'] ?? '' ) );

		$this->import_media( $zip );
		$this->import_files( $zip );
		$this->import_options( $zip );
		$this->import_pages( $zip );
		$this->import_articles( $zip );
		$this->import_menus( $zip );
		$this->import_front( $zip );

		$zip->close();

		// The field groups are built from the schemas, and the schemas just changed.
		$this->plugin->schemas()->refresh();
		wp_cache_delete( 'aiwp_page_ids' );

		return true;
	}

	// -- media ---------------------------------------------------------------

	private function import_media( ZipArchive $zip ): void {
		$items = (array) json_decode( (string) $zip->getFromName( 'media.json' ), true );

		if ( array() === $items ) {
			return;
		}

		// A zip is somebody else's file, so every image goes in through the same
		// door the AI uses: type sniffed, size and dimensions checked, SVG
		// sanitized against the allowlist before it is ever written.
		$importer = new ImageImporter();

		foreach ( $items as $item ) {
			$old  = (int) ( $item['id'] ?? 0 );
			$name = (string) ( $item['name'] ?? '' );

			if ( $old <= 0 || '' === $name ) {
				continue;
			}

			$bytes = $zip->getFromName( 'media/' . $old . '-' . $name );

			if ( false === $bytes ) {
				$this->problems[] = sprintf( 'The file for "%s" was not in the zip, so that image is missing.', $name );
				continue;
			}

			$result = $importer->from_base64(
				base64_encode( (string) $bytes ),
				(string) ( $item['alt'] ?? '' ),
				(string) ( $item['title'] ?? '' ),
				$name
			);

			if ( empty( $result['ok'] ) || empty( $result['image']['id'] ) ) {
				$this->problems[] = sprintf(
					'Could not add "%s" to the library: %s',
					$name,
					(string) ( $result['error'] ?? 'no reason given' )
				);
				continue;
			}

			$this->media_map[ $old ] = (int) $result['image']['id'];
		}

		$this->done[] = sprintf( '%d image(s) added to the media library.', count( $this->media_map ) );
	}

	// -- files ---------------------------------------------------------------

	private function import_files( ZipArchive $zip ): void {
		$root    = untrailingslashit( $this->files->root() );
		$written = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( 0 !== strpos( $name, 'files/' ) ) {
				continue;
			}

			$inside = substr( $name, strlen( 'files/' ) );

			// A zip is somebody else's file. Never let one walk out of our folder.
			if ( '' === $inside || false !== strpos( $inside, '..' ) || 0 === strpos( $inside, '/' ) ) {
				$this->problems[] = sprintf( 'Refused a suspicious path in the zip: %s', $name );
				continue;
			}

			$bytes = $zip->getFromIndex( $i );

			if ( false === $bytes ) {
				continue;
			}

			$path = $root . '/' . $inside;

			if ( ! $this->files->atomic_write( $path, $this->rewrite_urls( (string) $bytes ) ) ) {
				$this->problems[] = sprintf( 'Could not write %s. %s', $inside, $this->files->last_error() );
				continue;
			}

			++$written;
		}

		$this->done[] = sprintf( '%d design file(s) written.', $written );
	}

	// -- options -------------------------------------------------------------

	private function import_options( ZipArchive $zip ): void {
		$options = (array) json_decode( (string) $zip->getFromName( 'options.json' ), true );

		foreach ( $options as $name => $value ) {
			$name = (string) $name;

			// Only ours, and never a secret, whatever the zip claims.
			if ( 0 !== strpos( $name, 'aiwp_' ) || in_array( $name, array( 'aiwp_mcp_tokens', 'aiwp_preview_secret', 'aiwp_form_secret' ), true ) ) {
				continue;
			}

			update_option( $name, $value );
		}
	}

	// -- pages ---------------------------------------------------------------

	private function import_pages( ZipArchive $zip ): void {
		$pages = (array) json_decode( (string) $zip->getFromName( 'pages.json' ), true );
		$made  = 0;
		$kept  = 0;

		foreach ( $pages as $page ) {
			$uuid = (string) ( $page['uuid'] ?? '' );

			if ( '' === $uuid ) {
				continue;
			}

			$existing = $this->find_by_uuid( $uuid );
			$id       = $this->write_post( (array) $page, $existing );

			if ( 0 === $id ) {
				continue;
			}

			update_post_meta( $id, PageRepository::META_UUID, $uuid );
			$this->page_map[ $uuid ] = $id;

			$existing > 0 ? ++$kept : ++$made;
		}

		// The chrome holder is a page, and its id is remembered in an option.
		$this->repoint_holder( 'aiwp_chrome_page_id', $pages );

		$this->done[] = sprintf( '%d page(s) created, %d updated.', $made, $kept );
	}

	private function import_articles( ZipArchive $zip ): void {
		$articles = (array) json_decode( (string) $zip->getFromName( 'articles.json' ), true );
		$count    = 0;

		foreach ( $articles as $article ) {
			$slug = (string) ( $article['post']['name'] ?? '' );

			if ( '' === $slug ) {
				continue;
			}

			$existing = $this->find_post_by_slug( $slug );
			$id       = $this->write_post( (array) $article, $existing );

			if ( 0 === $id ) {
				continue;
			}

			foreach ( (array) ( $article['terms'] ?? array() ) as $taxonomy => $names ) {
				wp_set_object_terms( $id, array_map( 'strval', (array) $names ), (string) $taxonomy );
			}

			++$count;
		}

		if ( $count > 0 ) {
			$this->done[] = sprintf( '%d article(s) imported.', $count );
		}
	}

	/**
	 * Writes one post and its meta. Returns the post id, or 0.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function write_post( array $payload, int $existing ): int {
		$post = (array) ( $payload['post'] ?? array() );

		$data = array(
			'post_title'   => (string) ( $post['title'] ?? '' ),
			'post_name'    => (string) ( $post['name'] ?? '' ),
			'post_status'  => (string) ( $post['status'] ?? 'draft' ),
			'post_type'    => (string) ( $post['type'] ?? 'page' ),
			'post_excerpt' => (string) ( $post['excerpt'] ?? '' ),
			'post_content' => $this->rewrite_urls( (string) ( $post['content'] ?? '' ) ),
			'menu_order'   => (int) ( $post['order'] ?? 0 ),
		);

		if ( $existing > 0 ) {
			$data['ID'] = $existing;
			$id         = wp_update_post( $data, true );
		} else {
			$id = wp_insert_post( $data, true );
		}

		if ( is_wp_error( $id ) || 0 === (int) $id ) {
			$this->problems[] = sprintf(
				'WordPress refused "%s": %s',
				(string) ( $post['title'] ?? '?' ),
				is_wp_error( $id ) ? $id->get_error_message() : 'no reason given'
			);
			return 0;
		}

		$id = (int) $id;

		foreach ( (array) ( $payload['meta'] ?? array() ) as $key => $value ) {
			update_post_meta( $id, (string) $key, $this->remap( $value ) );
		}

		if ( '' !== (string) ( $post['template'] ?? '' ) ) {
			update_post_meta( $id, '_wp_page_template', (string) $post['template'] );
		}

		return $id;
	}

	// -- menus ---------------------------------------------------------------

	private function import_menus( ZipArchive $zip ): void {
		$payload = (array) json_decode( (string) $zip->getFromName( 'menus.json' ), true );
		$by_slug = array();

		foreach ( (array) ( $payload['menus'] ?? array() ) as $menu ) {
			$name = (string) ( $menu['name'] ?? '' );
			$slug = (string) ( $menu['slug'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$existing = wp_get_nav_menu_object( $slug );
			$menu_id  = $existing ? (int) $existing->term_id : 0;

			if ( 0 === $menu_id ) {
				$made = wp_create_nav_menu( $name );
				if ( is_wp_error( $made ) ) {
					$this->problems[] = sprintf( 'Could not create the menu "%s".', $name );
					continue;
				}
				$menu_id = (int) $made;
			} else {
				// Start clean, or a second import doubles every menu.
				foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
					wp_delete_post( (int) $item->ID, true );
				}
			}

			$by_slug[ $slug ] = $menu_id;
			$made_items       = array();

			foreach ( (array) ( $menu['items'] ?? array() ) as $item ) {
				$uuid   = (string) ( $item['uuid'] ?? '' );
				$target = $uuid && isset( $this->page_map[ $uuid ] ) ? $this->page_map[ $uuid ] : 0;

				$args = array(
					'menu-item-title'     => (string) ( $item['title'] ?? '' ),
					'menu-item-status'    => 'publish',
					'menu-item-position'  => (int) ( $item['order'] ?? 0 ),
					'menu-item-target'    => (string) ( $item['target'] ?? '' ),
					'menu-item-parent-id' => (int) ( $made_items[ (int) ( $item['parent'] ?? 0 ) ] ?? 0 ),
				);

				if ( $target > 0 ) {
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = get_post_type( $target ) ?: 'page';
					$args['menu-item-object-id'] = $target;
				} else {
					$args['menu-item-type'] = 'custom';
					$args['menu-item-url']  = $this->rewrite_urls( (string) ( $item['url'] ?? '' ) );
				}

				$new = wp_update_nav_menu_item( $menu_id, 0, $args );

				if ( ! is_wp_error( $new ) ) {
					$made_items[ (int) ( $item['id'] ?? 0 ) ] = (int) $new;
				}
			}
		}

		$locations = get_nav_menu_locations();

		foreach ( (array) ( $payload['locations'] ?? array() ) as $location => $slug ) {
			if ( isset( $by_slug[ (string) $slug ] ) ) {
				$locations[ (string) $location ] = $by_slug[ (string) $slug ];
			}
		}

		set_theme_mod( 'nav_menu_locations', $locations );

		if ( array() !== $by_slug ) {
			$this->done[] = sprintf( '%d menu(s) rebuilt.', count( $by_slug ) );
		}
	}

	private function import_front( ZipArchive $zip ): void {
		$front = (array) json_decode( (string) $zip->getFromName( 'front.json' ), true );
		$page  = (string) ( $front['page_on_front'] ?? '' );

		if ( 'page' !== (string) ( $front['show_on_front'] ?? '' ) || '' === $page ) {
			return;
		}

		if ( ! isset( $this->page_map[ $page ] ) ) {
			return;
		}

		$id = $this->page_map[ $page ];

		// A draft cannot hold the root, and silently setting it would leave the
		// site showing nothing.
		if ( 'publish' !== get_post_status( $id ) ) {
			$this->problems[] = 'The homepage arrived as a draft, so the site root was left alone. Publish it, then set it under Settings > Reading.';
			return;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $id );

		$posts = (string) ( $front['page_for_posts'] ?? '' );
		if ( '' !== $posts && isset( $this->page_map[ $posts ] ) ) {
			update_option( 'page_for_posts', $this->page_map[ $posts ] );
		}

		$this->done[] = 'The site root points at the homepage again.';
	}

	// -- helpers -------------------------------------------------------------

	/**
	 * Rewrites attachment ids inside a stored value.
	 */
	private function remap( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'remap' ), $value );
		}

		if ( is_scalar( $value ) ) {
			$text = (string) $value;

			if ( '' !== $text && ctype_digit( $text ) && strlen( $text ) <= 12 ) {
				$old = (int) $text;
				if ( isset( $this->media_map[ $old ] ) ) {
					return (string) $this->media_map[ $old ];
				}
			}

			return $this->rewrite_urls( $text );
		}

		return $value;
	}

	/** The site it came from is not the site it is on. */
	private function rewrite_urls( string $text ): string {
		if ( '' === $this->source_url || '' === $text ) {
			return $text;
		}

		$here = untrailingslashit( home_url() );

		return $here === $this->source_url ? $text : str_replace( $this->source_url, $here, $text );
	}

	private function find_by_uuid( string $uuid ): int {
		if ( '' === $uuid ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'   => array( 'page', 'post' ),
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => PageRepository::META_UUID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $uuid,                     // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		return array() === (array) $found ? 0 : (int) $found[0];
	}

	private function find_post_by_slug( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, 'post' );

		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}

	/**
	 * The shared header and footer live on a page of their own, and an option
	 * remembers which one. That id is this site's, so it is looked up again by
	 * the mark the holder carries.
	 *
	 * @param array<int,array<string,mixed>> $pages
	 */
	private function repoint_holder( string $option, array $pages ): void {
		foreach ( $pages as $page ) {
			$uuid = (string) ( $page['uuid'] ?? '' );

			if ( ! isset( $page['meta']['_aiwp_chrome'] ) || ! isset( $this->page_map[ $uuid ] ) ) {
				continue;
			}

			update_option( $option, $this->page_map[ $uuid ] );
			return;
		}
	}
}
