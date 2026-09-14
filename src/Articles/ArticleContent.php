<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

/**
 * Turns a WordPress post into the content array an article template reads.
 *
 * Everything WordPress already knows about a post lives under `post.`, so a
 * template says `{{text:post.title}}` and `{{post_body:post.body}}`. The extra
 * fields the article design adds sit alongside it under their own section
 * names, exactly as they do on a page.
 */
final class ArticleContent {

	/** Words a minute, for the reading estimate. */
	private const READING_SPEED = 225;

	/**
	 * The `post` section, described the way a schema section is, so the
	 * template validator knows these paths are real.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function schema_sections(): array {
		$field = static fn( string $id, string $label, string $type = 'text' ): array => array(
			'id'    => $id,
			'name'  => $id,
			'label' => $label,
			'type'  => $type,
		);

		return array(
			array(
				'id'     => 'post',
				'label'  => 'From WordPress',
				'fields' => array(
					$field( 'title', 'Title' ),
					$field( 'body', 'Body', 'wysiwyg' ),
					$field( 'excerpt', 'Excerpt', 'textarea' ),
					$field( 'url', 'Permalink', 'url' ),
					$field( 'date', 'Published date' ),
					$field( 'date_iso', 'Published date, machine readable' ),
					$field( 'updated', 'Updated date' ),
					$field( 'updated_iso', 'Updated date, machine readable' ),
					$field( 'author', 'Author name' ),
					$field( 'author_bio', 'Author bio', 'textarea' ),
					$field( 'author_url', 'Author archive', 'url' ),
					$field( 'author_avatar', 'Author photo', 'url' ),
					$field( 'image', 'Featured image', 'image' ),
					$field( 'reading_time', 'Reading time in minutes', 'number' ),
					$field( 'word_count', 'Word count', 'number' ),
					$field( 'comment_count', 'Number of comments', 'number' ),
					array(
						'id'         => 'toc_deep',
						'name'       => 'toc_deep',
						'label'      => 'Contents including sub-headings',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array(
							$field( 'text', 'Heading' ),
							$field( 'anchor', 'Link', 'url' ),
							$field( 'level', 'Heading level' ),
							$field( 'id', 'Anchor id' ),
						),
					),
					array(
						'id'         => 'toc',
						'name'       => 'toc',
						'label'      => 'Contents, top-level headings only',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array(
							$field( 'text', 'Heading' ),
							$field( 'anchor', 'Link', 'url' ),
							$field( 'level', 'Heading level' ),
							$field( 'id', 'Anchor id' ),
						),
					),
					array(
						'id'         => 'categories',
						'name'       => 'categories',
						'label'      => 'Categories',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array( $field( 'name', 'Name' ), $field( 'url', 'Link', 'url' ) ),
					),
					array(
						'id'         => 'tags',
						'name'       => 'tags',
						'label'      => 'Tags',
						'type'       => 'aiwp_repeater',
						'sub_fields' => array( $field( 'name', 'Name' ), $field( 'url', 'Link', 'url' ) ),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function for_post( \WP_Post $post ): array {
		$author_id = (int) $post->post_author;
		$plain     = wp_strip_all_tags( (string) $post->post_content );
		$words     = str_word_count( $plain );
		$rendered  = self::body_and_contents( $post );

		return array(
			'title'         => get_the_title( $post ),
			// WordPress's own rendering of the body. Printed unescaped by the
			// post_body filter, which the validator allows on this path alone.
			'body'          => $rendered['body'],
			// Top-level headings only. A contents list that repeats every
			// sub-heading stops being a summary and becomes a second article.
			'toc'           => array_values(
				array_filter(
					$rendered['toc'],
					static fn( array $h ): bool => '2' === $h['level']
				)
			),
			// Every heading, for a design that wants a nested list.
			'toc_deep'      => $rendered['toc'],
			'excerpt'       => self::excerpt( $post ),
			'url'           => (string) get_permalink( $post ),
			'date'          => (string) get_the_date( '', $post ),
			'date_iso'      => (string) get_the_date( 'c', $post ),
			'updated'       => (string) get_the_modified_date( '', $post ),
			'updated_iso'   => (string) get_the_modified_date( 'c', $post ),
			'author'        => (string) get_the_author_meta( 'display_name', $author_id ),
			'author_bio'    => (string) get_the_author_meta( 'description', $author_id ),
			'author_url'    => (string) get_author_posts_url( $author_id ),
			'author_avatar' => (string) get_avatar_url( $author_id, array( 'size' => 160 ) ),
			'image'         => (int) get_post_thumbnail_id( $post ),
			'reading_time'  => max( 1, (int) ceil( $words / self::READING_SPEED ) ),
			'word_count'    => $words,
			'comment_count' => (int) $post->comment_count,
			'categories'    => self::terms( $post, 'category' ),
			'tags'          => self::terms( $post, 'post_tag' ),
		);
	}

	/**
	 * The body, rendered the way WordPress renders it in a theme.
	 */
	private static function body( \WP_Post $post ): string {
		return self::body_and_contents( $post )['body'];
	}

	/**
	 * The body with an id on every heading, and the list of those headings.
	 *
	 * A contents list is worth having on anything long, but asking a writer to
	 * keep one in step with the article by hand guarantees it drifts. So it is
	 * read back out of the headings they already wrote. Nothing to maintain, and
	 * it cannot disagree with the page.
	 *
	 * @return array{body:string,toc:array<int,array<string,string>>}
	 */
	private static function body_and_contents( \WP_Post $post ): array {
		static $cache = array();

		if ( isset( $cache[ $post->ID ] ) ) {
			return $cache[ $post->ID ];
		}

		// The same filters a theme's the_content() would run, so blocks,
		// shortcodes, embeds and paragraphs all come out. This is content an
		// editor wrote and WordPress already filtered on save according to what
		// that person was allowed to publish.
		$html = (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$html = str_replace( ']]>', ']]&gt;', $html );

		$result = self::add_heading_ids( $html );

		$cache[ $post->ID ] = $result;

		return $result;
	}

	/**
	 * @return array{body:string,toc:array<int,array<string,string>>}
	 */
	private static function add_heading_ids( string $html ): array {
		if ( ! preg_match( '/<h[23][\s>]/i', $html ) ) {
			return array( 'body' => $html, 'toc' => array() );
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$loaded   = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="aiwp-body">' . $html . '</div>',
			LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array( 'body' => $html, 'toc' => array() );
		}

		$xpath = new \DOMXPath( $doc );
		$root  = $xpath->query( '//div[@id="aiwp-body"]' );

		if ( ! $root || 0 === $root->length ) {
			return array( 'body' => $html, 'toc' => array() );
		}

		$toc  = array();
		$seen = array();

		foreach ( $xpath->query( './/h2 | .//h3', $root->item( 0 ) ) as $heading ) {
			if ( ! $heading instanceof \DOMElement ) {
				continue;
			}

			$text = trim( (string) $heading->textContent );
			if ( '' === $text ) {
				continue;
			}

			// A heading that already has an id keeps it: somebody may be linking
			// to it from outside, and breaking that is worse than a tidy slug.
			$id = $heading->getAttribute( 'id' );

			if ( '' === $id ) {
				$id = sanitize_title( $text );
				if ( '' === $id ) {
					$id = 'section';
				}
				if ( isset( $seen[ $id ] ) ) {
					$id .= '-' . ( ++$seen[ $id ] );
				} else {
					$seen[ $id ] = 1;
				}
				$heading->setAttribute( 'id', $id );
			} elseif ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = 1;
			}

			$toc[] = array(
				'level'  => substr( $heading->nodeName, 1 ),
				'text'   => $text,
				'anchor' => '#' . $id,
				'id'     => $id,
			);
		}

		$out = '';
		foreach ( $root->item( 0 )->childNodes as $child ) {
			$out .= (string) $doc->saveHTML( $child );
		}

		return array( 'body' => $out, 'toc' => $toc );
	}

	private static function excerpt( \WP_Post $post ): string {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return (string) $post->post_excerpt;
		}

		return wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40 );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private static function terms( \WP_Post $post, string $taxonomy ): array {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			$out[] = array(
				'name' => $term->name,
				'url'  => is_wp_error( $link ) ? '' : (string) $link,
			);
		}

		return $out;
	}
}
