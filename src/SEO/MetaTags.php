<?php
declare( strict_types = 1 );

namespace AIWP\Designer\SEO;

/**
 * Title and description tags, and the decision not to fight over them.
 *
 * SEO plugins are the most installed plugins in WordPress. If this one also
 * printed a title and a description, sites running Yoast or Rank Math would get
 * two of each, which is worse than printing none. So when one of them is
 * active, this stands down: it writes the brief's values into that plugin's own
 * fields and lets it do the printing.
 *
 * On a site with no SEO plugin, it prints them itself, because a page with no
 * description is a page whose search result is assembled by a machine out of
 * whatever sentence it found first.
 */
final class MetaTags {

	/** Plugin => the post meta key it stores a title in. */
	private const KNOWN = array(
		'yoast'    => array( 'class' => 'WPSEO_Options', 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ),
		'rankmath' => array( 'class' => 'RankMath', 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ),
		'seopress' => array( 'function' => 'seopress_activation', 'title' => '_seopress_titles_title', 'desc' => '_seopress_titles_desc' ),
		'aioseo'   => array( 'function' => 'aioseo', 'title' => '', 'desc' => '' ),
	);

	public function register(): void {
		if ( '' !== self::owner() ) {
			// Somebody else is printing these. Keep their fields in step instead.
			add_action( 'save_post', array( $this, 'sync_to_seo_plugin' ), 20, 1 );
			return;
		}

		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_action( 'wp_head', array( $this, 'print_description' ), 1 );
	}

	/**
	 * Which SEO plugin owns the meta tags on this site, if any.
	 */
	public static function owner(): string {
		foreach ( self::KNOWN as $name => $probe ) {
			if ( isset( $probe['class'] ) && class_exists( $probe['class'] ) ) {
				return $name;
			}
			if ( isset( $probe['function'] ) && function_exists( $probe['function'] ) ) {
				return $name;
			}
		}

		return '';
	}

	public static function title_for( \WP_Post $post, ?PageBrief $brief = null ): string {
		$brief = $brief ?? PageBrief::for_post( $post->ID );
		$own   = (string) $brief->get( 'meta_title', '' );

		if ( '' !== trim( $own ) ) {
			return $own;
		}

		$owner = self::owner();
		if ( '' !== $owner && '' !== self::KNOWN[ $owner ]['title'] ) {
			$stored = (string) get_post_meta( $post->ID, self::KNOWN[ $owner ]['title'], true );
			if ( '' !== trim( $stored ) ) {
				return $stored;
			}
		}

		return (string) get_the_title( $post );
	}

	public static function description_for( \WP_Post $post, ?PageBrief $brief = null ): string {
		$brief = $brief ?? PageBrief::for_post( $post->ID );
		$own   = (string) $brief->get( 'meta_description', '' );

		if ( '' !== trim( $own ) ) {
			return $own;
		}

		$owner = self::owner();
		if ( '' !== $owner && '' !== self::KNOWN[ $owner ]['desc'] ) {
			$stored = (string) get_post_meta( $post->ID, self::KNOWN[ $owner ]['desc'], true );
			if ( '' !== trim( $stored ) ) {
				return $stored;
			}
		}

		return (string) $post->post_excerpt;
	}

	/**
	 * @param array<string,string> $parts
	 * @return array<string,string>
	 */
	public function title_parts( array $parts ): array {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! is_singular() ) {
			return $parts;
		}

		$brief = PageBrief::for_post( $post->ID );
		$title = (string) $brief->get( 'meta_title', '' );

		if ( '' === trim( $title ) ) {
			return $parts;
		}

		// A title written for a search result is the whole thing, not a
		// fragment to have the site name appended to.
		return array( 'title' => $title );
	}

	public function print_description(): void {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! is_singular() ) {
			return;
		}

		$description = self::description_for( $post );
		if ( '' === trim( $description ) ) {
			return;
		}

		printf(
			"<meta name=\"description\" content=\"%s\">\n",
			esc_attr( wp_strip_all_tags( $description ) )
		);
	}

	/**
	 * Write the brief's values into whichever SEO plugin is in charge, so a
	 * person editing them there sees what the AI decided rather than a blank.
	 */
	public function sync_to_seo_plugin( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$owner = self::owner();
		if ( '' === $owner || '' === self::KNOWN[ $owner ]['title'] ) {
			return;
		}

		$brief = PageBrief::for_post( $post_id );
		if ( $brief->is_empty() ) {
			return;
		}

		foreach ( array( 'title' => 'meta_title', 'desc' => 'meta_description' ) as $slot => $field ) {
			$value = (string) $brief->get( $field, '' );
			$key   = self::KNOWN[ $owner ][ $slot ];

			// Never overwrite something a person typed there.
			if ( '' !== trim( $value ) && '' === trim( (string) get_post_meta( $post_id, $key, true ) ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
