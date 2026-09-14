<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Transfer;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Plugin;
use AIWP\Designer\Storage\FileStore;
use ZipArchive;

/**
 * Packs a whole AIWP site into one file.
 *
 * A page is not one row. It is a post, its ACF values, six files on disk, a
 * design system those files lean on, the shared header and footer, the menus,
 * and every image it points at by attachment id. WordPress's own export
 * carries the post and nothing else, so a site moved that way arrives with its
 * words and none of its design. This carries all of it.
 *
 * Two things deliberately stay behind: MCP tokens and the signing secrets. A
 * zip travels by email and through other people's laptops, and a token in it
 * would be a key to the site it came from.
 */
final class SiteExporter {

	private Plugin $plugin;
	private FileStore $files;
	private PageRepository $pages;

	/** @var string[] */
	private array $problems = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->files  = $plugin->files();
		$this->pages  = $plugin->pages();
	}

	/** Options worth carrying. Secrets and bookkeeping are not here on purpose. */
	private const OPTIONS = array(
		'aiwp_archive_version',
		'aiwp_article_kinds',
		'aiwp_brand_inputs',
		'aiwp_chrome_version',
		'aiwp_design_updated',
		'aiwp_design_version',
		'aiwp_menu_locations',
	);

	/** Post meta that describes this install, not this page. */
	private const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/** @return string[] */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Writes the zip and returns its path, or null if it could not be written.
	 */
	public function write( string $path ): ?string {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->problems[] = sprintf( 'Could not create the file at %s.', $path );
			return null;
		}

		$pages    = $this->page_payload();
		$articles = $this->article_payload();
		$media    = $this->media_payload( array_merge( $pages, $articles ) );

		$manifest = array(
			'format'           => 1,
			'plugin_version'   => AIWP_VERSION,
			'template_version' => AIWP_TEMPLATE_LANGUAGE_VERSION,
			'exported'         => gmdate( 'c' ),
			'source_url'       => home_url(),
			'source_name'      => get_bloginfo( 'name' ),
			'counts'           => array(
				'pages'    => count( $pages ),
				'articles' => count( $articles ),
				'media'    => count( $media ),
			),
		);

		$zip->addFromString( 'aiwp-site.json', $this->json( $manifest ) );
		$zip->addFromString( 'pages.json', $this->json( $pages ) );
		$zip->addFromString( 'articles.json', $this->json( $articles ) );
		$zip->addFromString( 'media.json', $this->json( $media ) );
		$zip->addFromString( 'options.json', $this->json( $this->options_payload() ) );
		$zip->addFromString( 'menus.json', $this->json( $this->menu_payload() ) );
		$zip->addFromString( 'front.json', $this->json( $this->front_payload() ) );

		// The files each page and the design own.
		foreach ( $pages as $page ) {
			// page_dir() already points at "current", and the path inside the zip
			// has to say so too, or the import writes one level too high and
			// every page comes out blank.
			$this->add_dir( $zip, $this->files->page_dir( (string) $page['uuid'] ), 'files/pages/' . $page['uuid'] . '/current' );
		}

		// Only what is live. The version history is this site's own record of
		// how it got here, it is large, and it means nothing on the site the
		// zip is going to.
		foreach ( $this->live_dirs() as $inside ) {
			$this->add_dir( $zip, trailingslashit( $this->files->root() ) . $inside, 'files/' . $inside );
		}

		foreach ( $media as $item ) {
			$file = (string) ( $item['file'] ?? '' );
			if ( '' !== $file && is_readable( $file ) ) {
				$zip->addFile( $file, 'media/' . $item['id'] . '-' . basename( $file ) );
			}
		}

		$zip->close();

		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Every AIWP page, with its post, its meta and its schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function page_payload(): array {
		$out = array();

		foreach ( $this->page_ids() as $page_id ) {
			$post = get_post( $page_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$uuid = $this->pages->uuid( $page_id );

			if ( '' === $uuid ) {
				$this->problems[] = sprintf( 'Skipped "%s": it has no id of its own to travel under.', $post->post_title );
				continue;
			}

			$out[] = array(
				'uuid'    => $uuid,
				'post_id' => $page_id,
				'post'    => array(
					'title'     => $post->post_title,
					'name'      => $post->post_name,
					'status'    => $post->post_status,
					'type'      => $post->post_type,
					'excerpt'   => $post->post_excerpt,
					'content'   => $post->post_content,
					'parent'    => (int) $post->post_parent,
					'order'     => (int) $post->menu_order,
					'template'  => (string) get_page_template_slug( $page_id ),
					'date_gmt'  => $post->post_date_gmt,
				),
				'meta'    => $this->meta_of( $page_id ),
			);
		}

		return $out;
	}

	/**
	 * Pages, plus the holders that are pages without looking like one: the
	 * shared header and footer, and the article and archive designs.
	 *
	 * @return int[]
	 */
	private function page_ids(): array {
		$ids = $this->pages->all_page_ids();

		foreach ( array( 'aiwp_chrome_page_id', 'aiwp_article_template_page', 'aiwp_archive_template_page' ) as $option ) {
			$holder = (int) get_option( $option, 0 );
			if ( $holder > 0 && ! in_array( $holder, $ids, true ) ) {
				$ids[] = $holder;
			}
		}

		return $ids;
	}

	/**
	 * Articles.
	 *
	 * An article has no design of its own — every one of them is drawn by the
	 * single article template — so it has no uuid and no files. What travels
	 * is the post WordPress owns plus the ACF values the template reads. Its
	 * slug is the name it goes by on the other side.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function article_payload(): array {
		if ( ! $this->plugin->articles()->template_for( '' )->exists() ) {
			return array();
		}

		$out = array();

		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'numberposts' => 500,
			)
		);

		foreach ( (array) $posts as $post ) {
			$out[] = array(
				'uuid'    => '',
				'post_id' => (int) $post->ID,
				'post'    => array(
					'title'    => $post->post_title,
					'name'     => $post->post_name,
					'status'   => $post->post_status,
					'type'     => 'post',
					'excerpt'  => $post->post_excerpt,
					'content'  => $post->post_content,
					'parent'   => 0,
					'order'    => (int) $post->menu_order,
					'template' => '',
					'date_gmt' => $post->post_date_gmt,
				),
				'terms'   => $this->terms_of( (int) $post->ID ),
				'meta'    => $this->meta_of( (int) $post->ID ),
			);
		}

		return $out;
	}

	/**
	 * Categories and tags, by name, so the import can find or make them.
	 *
	 * @return array<string,string[]>
	 */
	private function terms_of( int $post_id ): array {
		$out = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_array( $terms ) && array() !== $terms ) {
				$out[ $taxonomy ] = array_map( 'strval', $terms );
			}
		}

		return $out;
	}

	/**
	 * The live copies of the shared designs.
	 *
	 * @return string[]
	 */
	private function live_dirs(): array {
		$out = array( 'design/current', 'chrome/current' );

		$articles = trailingslashit( $this->files->root() ) . 'articles';

		foreach ( (array) glob( $articles . '/*', GLOB_ONLYDIR ) as $kind ) {
			if ( is_dir( $kind . '/current' ) ) {
				$out[] = 'articles/' . basename( (string) $kind ) . '/current';
			}
		}

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function meta_of( int $page_id ): array {
		$out = array();

		foreach ( (array) get_post_meta( $page_id ) as $key => $values ) {
			$key = (string) $key;

			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}

			$out[ $key ] = count( (array) $values ) > 1
				? array_map( 'maybe_unserialize', (array) $values )
				: maybe_unserialize( (string) $values[0] );
		}

		return $out;
	}

	/**
	 * Images and files the pages point at.
	 *
	 * Content stores an attachment id, and an id means nothing on the site it
	 * arrives at. Each one travels with its file so the import can point the
	 * value at whatever id it ends up with there.
	 *
	 * @param array<int,array<string,mixed>> $pages
	 * @return array<int,array<string,mixed>>
	 */
	private function media_payload( array $pages ): array {
		$ids = array();

		foreach ( $pages as $page ) {
			foreach ( (array) $page['meta'] as $key => $value ) {
				if ( 0 === strpos( (string) $key, '_' ) ) {
					continue;
				}
				foreach ( $this->attachment_ids( $value ) as $id ) {
					$ids[ $id ] = true;
				}
			}

			$thumb = (int) ( $page['meta']['_thumbnail_id'] ?? 0 );
			if ( $thumb > 0 ) {
				$ids[ $thumb ] = true;
			}
		}

		$out = array();

		foreach ( array_keys( $ids ) as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );

			if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
				continue;
			}

			$file = get_attached_file( $id );

			if ( ! is_string( $file ) || ! is_readable( $file ) ) {
				$this->problems[] = sprintf( 'Image #%d is in the library but its file is missing, so it cannot travel.', $id );
				continue;
			}

			$out[] = array(
				'id'        => $id,
				'file'      => $file,
				'name'      => basename( $file ),
				'title'     => $post->post_title,
				'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption'   => $post->post_excerpt,
				'mime'      => $post->post_mime_type,
			);
		}

		return $out;
	}

	/**
	 * A value that is an attachment id, or holds some.
	 *
	 * @return int[]
	 */
	private function attachment_ids( mixed $value ): array {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out = array_merge( $out, $this->attachment_ids( $item ) );
			}
			return $out;
		}

		if ( ! is_scalar( $value ) ) {
			return array();
		}

		$value = (string) $value;

		// An id and nothing else. Anything longer is words, not a reference.
		if ( '' === $value || ! ctype_digit( $value ) || strlen( $value ) > 12 ) {
			return array();
		}

		$id = (int) $value;

		return $id > 0 && 'attachment' === get_post_type( $id ) ? array( $id ) : array();
	}

	/** @return array<string,mixed> */
	private function options_payload(): array {
		$out = array();

		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Menus travel as names and links, not as ids.
	 *
	 * @return array<string,mixed>
	 */
	private function menu_payload(): array {
		$out = array( 'menus' => array(), 'locations' => array() );

		foreach ( (array) wp_get_nav_menus() as $menu ) {
			$items = array();

			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$items[] = array(
					'id'     => (int) $item->ID,
					'parent' => (int) $item->menu_item_parent,
					'title'  => $item->title,
					'url'    => $item->url,
					'type'   => $item->type,
					'object' => $item->object,
					// An object id is a post id here, so it travels as the uuid.
					'uuid'   => 'post_type' === $item->type ? $this->pages->uuid( (int) $item->object_id ) : '',
					'order'  => (int) $item->menu_order,
					'target' => $item->target,
				);
			}

			$out['menus'][] = array(
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'items' => $items,
			);
		}

		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$menu = wp_get_nav_menu_object( (int) $menu_id );
			if ( $menu ) {
				$out['locations'][ $location ] = $menu->slug;
			}
		}

		return $out;
	}

	/** Which page the site root shows, by uuid. */
	private function front_payload(): array {
		$front = (int) get_option( 'page_on_front', 0 );
		$posts = (int) get_option( 'page_for_posts', 0 );

		return array(
			'show_on_front'  => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'  => $front > 0 ? $this->pages->uuid( $front ) : '',
			'page_for_posts' => $posts > 0 ? $this->pages->uuid( $posts ) : '',
		);
	}

	private function add_dir( ZipArchive $zip, string $dir, string $prefix ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$walk = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $walk as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$inside = substr( $file->getPathname(), strlen( $dir ) + 1 );
			$zip->addFile( $file->getPathname(), $prefix . '/' . str_replace( '\\', '/', $inside ) );
		}
	}

	private function json( mixed $value ): string {
		return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
