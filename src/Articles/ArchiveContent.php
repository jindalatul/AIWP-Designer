<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

/**
 * Turns whatever list WordPress is currently showing into the content array an
 * archive template reads.
 *
 * A blog index, a category, a tag, an author and a set of search results are
 * the same page with a different title and a different list. Treating them as
 * five designs is how a site ends up with five slightly different ones.
 */
final class ArchiveContent {

	private const READING_SPEED = 225;

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function schema_sections(): array {
		$f = static fn( string $id, string $label, string $type = 'text' ): array => array(
			'id' => $id, 'name' => $id, 'label' => $label, 'type' => $type,
		);

		return array(
			array(
				'id'     => 'archive',
				'label'  => 'From WordPress',
				'fields' => array(
					$f( 'title', 'Title of this list' ),
					$f( 'kind', 'What kind of list: blog, category, tag, author, search, date' ),
					$f( 'description', 'Description, where the taxonomy has one', 'textarea' ),
					$f( 'term', 'The category or tag name, when there is one' ),
					$f( 'query', 'The search words, on a search page' ),
					$f( 'count', 'How many articles in total', 'number' ),
					$f( 'shown', 'How many are on this page', 'number' ),
					$f( 'page', 'Which page of the list this is', 'number' ),
					$f( 'pages', 'How many pages there are', 'number' ),
					$f( 'newer_url', 'Link to the previous page of results', 'url' ),
					$f( 'older_url', 'Link to the next page of results', 'url' ),
					$f( 'empty', 'True when there is nothing to list', 'true_false' ),
					$f( 'paginated', 'True only when there is more than one page', 'true_false' ),
					array(
						'id'         => 'items',
						'name'       => 'items',
						'label'      => 'The articles',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array(
							$f( 'title', 'Title' ),
							$f( 'url', 'Link', 'url' ),
							$f( 'excerpt', 'Excerpt', 'textarea' ),
							$f( 'date', 'Published date' ),
							$f( 'date_iso', 'Published date, machine readable' ),
							$f( 'author', 'Author' ),
							$f( 'image', 'Featured image', 'image' ),
							$f( 'reading_time', 'Reading time in minutes', 'number' ),
							$f( 'category', 'First category' ),
							$f( 'category_url', 'Link to that category', 'url' ),
						),
					),
					array(
						'id'         => 'categories',
						'name'       => 'categories',
						'label'      => 'Every category on the site',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array(
							$f( 'name', 'Name' ),
							$f( 'url', 'Link', 'url' ),
							$f( 'count', 'How many articles', 'number' ),
							$f( 'is_current', 'True when this is the list being shown', 'true_false' ),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function current(): array {
		global $wp_query;

		$items = array();
		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				$items[] = self::item( get_post() );
			}
			rewind_posts();
		}

		$total = (int) ( $wp_query->found_posts ?? 0 );
		$pages = (int) max( 1, (int) ( $wp_query->max_num_pages ?? 1 ) );
		$page  = (int) max( 1, (int) get_query_var( 'paged' ) );

		return array(
			'title'       => self::title(),
			'kind'        => self::kind(),
			'description' => (string) get_the_archive_description(),
			'term'        => is_category() || is_tag() || is_tax() ? (string) single_term_title( '', false ) : '',
			'query'       => is_search() ? (string) get_search_query() : '',
			'count'       => $total,
			'shown'       => count( $items ),
			'page'        => $page,
			'pages'       => $pages,
			// Newer is the previous page of a list that runs newest first.
			'newer_url'   => $page > 1 ? (string) get_pagenum_link( $page - 1 ) : '',
			'older_url'   => $page < $pages ? (string) get_pagenum_link( $page + 1 ) : '',
			'empty'       => array() === $items,
			// The template language cannot compare numbers, and a pager reading
			// "Page 1 of 1" on every short list is worse than no pager.
			'paginated'   => $pages > 1,
			'items'       => $items,
			'categories'  => self::categories(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function item( \WP_Post $post ): array {
		$plain = wp_strip_all_tags( (string) $post->post_content );
		$cats  = get_the_category( $post->ID );
		$first = is_array( $cats ) && array() !== $cats ? $cats[0] : null;
		$link  = $first ? get_category_link( $first->term_id ) : '';

		return array(
			'title'        => get_the_title( $post ),
			'url'          => (string) get_permalink( $post ),
			'excerpt'      => '' !== trim( (string) $post->post_excerpt )
				? (string) $post->post_excerpt
				: wp_trim_words( $plain, 32 ),
			'date'         => (string) get_the_date( '', $post ),
			'date_iso'     => (string) get_the_date( 'c', $post ),
			'author'       => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'image'        => (int) get_post_thumbnail_id( $post ),
			'reading_time' => max( 1, (int) ceil( str_word_count( $plain ) / self::READING_SPEED ) ),
			'category'     => $first ? $first->name : '',
			'category_url' => is_wp_error( $link ) ? '' : (string) $link,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function categories(): array {
		$terms = get_categories( array( 'hide_empty' => true ) );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$current = is_category() ? (int) get_queried_object_id() : 0;
		$out     = array();

		foreach ( $terms as $term ) {
			$link  = get_category_link( $term->term_id );
			$out[] = array(
				'name'       => $term->name,
				'url'        => is_wp_error( $link ) ? '' : (string) $link,
				'count'      => (int) $term->count,
				'is_current' => $term->term_id === $current,
			);
		}

		return $out;
	}

	private static function title(): string {
		if ( is_home() ) {
			$page = (int) get_option( 'page_for_posts' );
			return $page > 0 ? (string) get_the_title( $page ) : __( 'Writing', 'aiwp-designer' );
		}
		if ( is_search() ) {
			/* translators: %s: the search words. */
			return sprintf( __( 'Search: %s', 'aiwp-designer' ), get_search_query() );
		}

		return (string) get_the_archive_title();
	}

	private static function kind(): string {
		if ( is_search() ) {
			return 'search';
		}
		if ( is_category() ) {
			return 'category';
		}
		if ( is_tag() ) {
			return 'tag';
		}
		if ( is_author() ) {
			return 'author';
		}
		if ( is_date() ) {
			return 'date';
		}

		return 'blog';
	}
}
