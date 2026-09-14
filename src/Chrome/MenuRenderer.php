<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Chrome;

use AIWP\Designer\Pages\PageRepository;

/**
 * Real WordPress navigation menus inside AIWP markup.
 *
 * The plugin used to carry its own list of links in a repeater, which meant the
 * site had two menu systems and neither was the one WordPress admins know.
 * Templates now place `<nav data-aiwp-menu="primary"></nav>` and the plugin fills
 * it from Appearance → Menus, with the current page marked and sub-menus
 * working, exactly as a theme would.
 */
final class MenuRenderer {

	/** Menu locations the plugin registers. */
	public const LOCATIONS = array(
		'primary' => 'aiwp_primary',
		'footer'  => 'aiwp_footer',
	);

	/**
	 * Which menu belongs in which location, remembered by the plugin.
	 *
	 * WordPress keeps this in a theme mod, which means it is emptied by a theme
	 * switch and can be cleared by saving the Menus screen without ticking the
	 * location box. Either leaves the site showing a fallback list of pages with
	 * no explanation, so the plugin keeps its own copy and repairs the theme mod.
	 */
	private const OPTION_LOCATIONS = 'aiwp_menu_locations';

	private PageRepository $pages;

	public function __construct( PageRepository $pages ) {
		$this->pages = $pages;
	}

	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'register_locations' ), 20 );
	}

	public function register_locations(): void {
		register_nav_menus(
			array(
				self::LOCATIONS['primary'] => __( 'AIWP header menu', 'aiwp-designer' ),
				self::LOCATIONS['footer']  => __( 'AIWP footer menu', 'aiwp-designer' ),
			)
		);
	}

	public static function is_location( string $name ): bool {
		return isset( self::LOCATIONS[ strtolower( $name ) ] );
	}

	public static function theme_location( string $name ): string {
		return self::LOCATIONS[ strtolower( $name ) ] ?? '';
	}

	/**
	 * The menu that should be rendered in a location.
	 *
	 * The theme mod wins. When it is empty but the plugin remembers a menu that
	 * still exists, that one is used and the theme mod is put back.
	 */
	public function menu_id_for( string $location ): int {
		$theme_location = self::theme_location( $location );
		if ( '' === $theme_location ) {
			return 0;
		}

		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$menu_id   = absint( $locations[ $theme_location ] ?? 0 );

		if ( $menu_id > 0 && wp_get_nav_menu_object( $menu_id ) ) {
			return $menu_id;
		}

		$remembered = (array) get_option( self::OPTION_LOCATIONS, array() );
		$menu_id    = absint( $remembered[ $theme_location ] ?? 0 );

		if ( 0 === $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
			return 0;
		}

		// Put the theme mod back, so WordPress's own screens agree with us.
		$locations[ $theme_location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return $menu_id;
	}

	private function remember( string $theme_location, int $menu_id ): void {
		$remembered                    = (array) get_option( self::OPTION_LOCATIONS, array() );
		$remembered[ $theme_location ] = $menu_id;
		update_option( self::OPTION_LOCATIONS, $remembered, false );
	}

	/**
	 * Replace every empty <nav data-aiwp-menu="..."></nav> with a real menu.
	 */
	public function inject( string $html ): string {
		if ( false === strpos( $html, 'data-aiwp-menu' ) ) {
			return $html;
		}

		$pattern = '/(<(nav|div|ul)\b[^>]*\bdata-aiwp-menu="([a-zA-Z0-9_-]+)"[^>]*>)\s*(<\/\2>)/i';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $match ): string {
				if ( ! self::is_location( $match[3] ) ) {
					return $match[0];
				}
				return $match[1] . $this->render( $match[3] ) . $match[4];
			},
			$html
		);
	}

	/**
	 * The menu markup itself. Classes are predictable so page CSS can style it.
	 */
	public function render( string $location ): string {
		$menu_id = $this->menu_id_for( $location );

		add_filter( 'nav_menu_css_class', array( $this, 'item_classes' ), 10, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'link_attributes' ), 10, 3 );

		$html = wp_nav_menu(
			array(
				// Addressed by id rather than by location, so a cleared theme mod
				// cannot silently swap the menu for a list of pages.
				'menu'           => $menu_id > 0 ? $menu_id : 0,
				'theme_location' => $menu_id > 0 ? '' : self::theme_location( $location ),
				'container'      => false,
				'menu_class'     => 'aiwp-menu aiwp-menu--' . sanitize_html_class( $location ),
				'menu_id'        => '',
				'depth'          => 2,
				'echo'           => false,
				'fallback_cb'    => array( $this, 'fallback' ),
				'items_wrap'     => '<ul class="%2$s">%3$s</ul>',
			)
		);

		remove_filter( 'nav_menu_css_class', array( $this, 'item_classes' ), 10 );
		remove_filter( 'nav_menu_link_attributes', array( $this, 'link_attributes' ), 10 );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * @param string[] $classes
	 * @param object   $item
	 * @return string[]
	 */
	public function item_classes( $classes, $item ): array {
		$classes[] = 'aiwp-menu__item';

		if ( in_array( 'current-menu-item', (array) $classes, true ) || in_array( 'current_page_item', (array) $classes, true ) ) {
			$classes[] = 'is-current';
		}

		return $classes;
	}

	/**
	 * @param array<string,string> $atts
	 * @return array<string,string>
	 */
	public function link_attributes( $atts, $item, $args ): array {
		$atts['class'] = trim( ( $atts['class'] ?? '' ) . ' aiwp-menu__link' );

		if ( ! empty( $item->current ) ) {
			$atts['aria-current'] = 'page';
		}

		return $atts;
	}

	/**
	 * Nothing assigned to this location yet, so show the site's own pages rather
	 * than an empty header.
	 *
	 * @param array<string,mixed> $args
	 */
	public function fallback( $args = array() ): string {
		$page_ids = $this->pages->content_page_ids();

		if ( array() === $page_ids ) {
			return '';
		}

		$front = \AIWP\Designer\Pages\FrontPage::current();
		$items = array();

		foreach ( $page_ids as $page_id ) {
			if ( 'publish' !== get_post_status( $page_id ) || $page_id === $front ) {
				continue;
			}

			$is_current = get_queried_object_id() === $page_id;

			$items[] = sprintf(
				'<li class="aiwp-menu__item%s"><a class="aiwp-menu__link" href="%s"%s>%s</a></li>',
				$is_current ? ' is-current' : '',
				esc_url( (string) get_permalink( $page_id ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( (string) get_the_title( $page_id ) )
			);

			if ( count( $items ) >= 6 ) {
				break;
			}
		}

		if ( array() === $items ) {
			return '';
		}

		$class = isset( $args['menu_class'] ) ? (string) $args['menu_class'] : 'aiwp-menu';

		return sprintf( '<ul class="%s">%s</ul>', esc_attr( $class ), implode( '', $items ) );
	}

	/**
	 * Create or update a real WordPress menu and put it in a location.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array{success:bool,menu_id:int,count:int,error:string}
	 */
	/**
	 * Put a menu that already exists into a location, without touching its items.
	 *
	 * Use this when the site owner has already built the menu they want, or to
	 * put back an assignment that something else changed.
	 *
	 * @return array{success:bool,menu_id:int,count:int,error:string}
	 */
	public function assign_menu( string $location, int $menu_id ): array {
		$theme_location = self::theme_location( $location );

		if ( '' === $theme_location ) {
			return array(
				'success' => false,
				'menu_id' => 0,
				'count'   => 0,
				'error'   => sprintf( 'Unknown menu location "%s". Use: %s.', $location, implode( ', ', array_keys( self::LOCATIONS ) ) ),
			);
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return array(
				'success' => false,
				'menu_id' => 0,
				'count'   => 0,
				'error'   => sprintf( 'There is no menu with id %d.', $menu_id ),
			);
		}

		$locations                    = (array) get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $theme_location ] = (int) $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$this->remember( $theme_location, (int) $menu->term_id );

		return array(
			'success' => true,
			'menu_id' => (int) $menu->term_id,
			'count'   => (int) $menu->count,
			'error'   => '',
		);
	}

	public function set_menu( string $location, string $title, array $items ): array {
		$theme_location = self::theme_location( $location );

		if ( '' === $theme_location ) {
			return array(
				'success' => false,
				'menu_id' => 0,
				'count'   => 0,
				'error'   => sprintf( 'Unknown menu location "%s". Use: %s.', $location, implode( ', ', array_keys( self::LOCATIONS ) ) ),
			);
		}

		$title = '' !== trim( $title ) ? trim( $title ) : ucfirst( $location ) . ' menu';
		$menu  = wp_get_nav_menu_object( $title );

		// A location that already has a menu keeps it. Matching on the title
		// alone meant a second call that named the menu differently — or did
		// not name it at all — built a second menu for the same place and left
		// the first one behind. The owner opens Appearance > Menus and finds
		// three menus for two locations, and cannot tell which is live.
		if ( ! $menu ) {
			$assigned = (int) ( get_nav_menu_locations()[ $theme_location ] ?? 0 );

			if ( $assigned > 0 ) {
				$menu = wp_get_nav_menu_object( $assigned ) ?: null;
			}
		}

		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( $title );
			if ( is_wp_error( $menu_id ) ) {
				return array(
					'success' => false,
					'menu_id' => 0,
					'count'   => 0,
					'error'   => $menu_id->get_error_message(),
				);
			}
			$menu_id = (int) $menu_id;
		} else {
			$menu_id = (int) $menu->term_id;
		}

		// Update the existing items in place rather than deleting and recreating
		// them. Recreating churns every id and throws away anything the site owner
		// changed on the Menus screen.
		$existing = array_values( (array) wp_get_nav_menu_items( $menu_id ) );
		$added    = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$page_id = absint( $item['page_id'] ?? 0 );

			$args = array(
				'menu-item-title'     => $label,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $added + 1,
				'menu-item-parent-id' => 0,
			);

			if ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) {
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = 'page';
				$args['menu-item-object-id'] = $page_id;
				$args['menu-item-url']       = '';
			} else {
				$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}
				$args['menu-item-type']      = 'custom';
				$args['menu-item-object']    = 'custom';
				$args['menu-item-object-id'] = 0;
				$args['menu-item-url']       = $url;
			}

			$reuse  = isset( $existing[ $added ] ) ? (int) $existing[ $added ]->ID : 0;
			$result = wp_update_nav_menu_item( $menu_id, $reuse, $args );

			if ( ! is_wp_error( $result ) ) {
				// WordPress stores an empty title when the label matches the
				// linked page, so the menu follows a rename. That is a sensible
				// default and the wrong answer here: a caller that passed
				// label "Contact" meant the menu says Contact. Without this, a
				// page renamed for search rewrote the navigation under it — a
				// three-item nav ended up reading "Request a free SEO audit".
				$stored = get_post( (int) $result );

				if ( $stored instanceof \WP_Post && $label !== $stored->post_title ) {
					wp_update_post(
						array(
							'ID'         => (int) $result,
							'post_title' => $label,
						)
					);
				}

				++$added;
			}
		}

		// Anything left over is no longer wanted.
		for ( $i = $added, $total = count( $existing ); $i < $total; $i++ ) {
			wp_delete_post( (int) $existing[ $i ]->ID, true );
		}

		$locations                    = (array) get_theme_mod( 'nav_menu_locations', array() );
		$locations[ $theme_location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		$this->remember( $theme_location, $menu_id );

		return array(
			'success' => true,
			'menu_id' => $menu_id,
			'count'   => $added,
			'error'   => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function describe(): array {
		$out = array();

		foreach ( self::LOCATIONS as $name => $theme_location ) {
			$menu_id = $this->menu_id_for( $name );
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;

			$items = array();
			foreach ( (array) ( $menu ? wp_get_nav_menu_items( $menu_id ) : array() ) as $item ) {
				$items[] = array(
					'label' => $item->title,
					'url'   => $item->url,
				);
			}

			$out[ $name ] = array(
				'assigned'   => (bool) $menu,
				'menu'       => $menu ? $menu->name : '',
				'menu_id'    => $menu_id,
				'items'      => $items,
				'edit_url'   => $menu_id ? admin_url( 'nav-menus.php?menu=' . $menu_id ) : '',
				'showing'    => $menu ? 'the assigned menu' : 'a list of published pages, because no menu is assigned',
				'place_with' => sprintf( '<nav data-aiwp-menu="%s"></nav>', $name ),
			);
		}

		return $out;
	}
}
