<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Pages\PreviewToken;
use AIWP\Designer\Security\AuditLogger;
use AIWP\Designer\SEO\PageBrief;

/**
 * Writes articles as WordPress posts, for the post_* MCP tools.
 *
 * An article is a blog post (ADR-032): the body lives in post_content as core
 * blocks, so it opens in the block editor like anything a person wrote, and the
 * article design supplies the look around it. Pages keep their own tools; this
 * class refuses anything that is not a post.
 *
 * Posts are always created as drafts. Publishing is a separate call that needs
 * confirm_publish, because going live is the person's decision.
 */
final class PostWriter {

	/** Preview tokens for posts carry this prefix in place of a page uuid. */
	public const PREVIEW_PREFIX = 'post:';

	private ArticleManager $articles;

	private FieldValueManager $values;

	public function __construct( ArticleManager $articles, FieldValueManager $values ) {
		$this->articles = $articles;
		$this->values   = $values;
	}

	// ------------------------------------------------------------- tools

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function create( array $args, int $user_id ): array {
		$title = trim( wp_strip_all_tags( (string) ( $args['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return $this->fail( 'AIWP_POST_INVALID', 'Send a title.' );
		}

		$body = $this->body( $args );
		if ( isset( $body['error'] ) ) {
			return $body['error'];
		}
		if ( '' === trim( $body['content'] ) ) {
			return $this->fail( 'AIWP_POST_INVALID', 'Send the article text in body. It was empty after cleaning.' );
		}

		$slug = sanitize_title( (string) ( $args['slug'] ?? '' ) ?: $title );
		if ( $this->slug_taken( $slug, 0 ) ) {
			return $this->fail( 'AIWP_SLUG_TAKEN', sprintf( 'The address "%s" is already used by another post or page. Choose another slug.', $slug ) );
		}

		$design = $this->articles->template_for( (string) ( $args['kind'] ?? '' ) );
		$fields = $this->check_fields( $args, $design );
		if ( isset( $fields['error'] ) ) {
			return $fields['error'];
		}
		$terms = $this->check_terms( $args );
		if ( isset( $terms['error'] ) ) {
			return $terms['error'];
		}
		$image = $this->check_image( $args );
		if ( isset( $image['error'] ) ) {
			return $image['error'];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => ArticleManager::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $body['content'],
				'post_excerpt' => sanitize_textarea_field( (string) ( $args['excerpt'] ?? '' ) ),
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->fail( 'AIWP_POST_WRITE_FAILED', $post_id->get_error_message() );
		}

		$warnings = $body['warnings'];
		$warnings = array_merge( $warnings, $this->apply_extras( (int) $post_id, $args, $design, $fields, $terms, $image ) );

		AuditLogger::log( 'post.create', array( 'post_id' => $post_id, 'user_id' => $user_id ) );

		return array_merge(
			array( 'success' => true ),
			$this->summary( (int) $post_id ),
			array(
				'blocks'   => $body['blocks'],
				'warnings' => array_values( array_merge( $warnings, $this->site_warnings( $design ) ) ),
				'note'     => 'Saved as a draft. Open preview_url and read it, then publish only when the person asks (post_publish).',
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function update( array $args, int $user_id ): array {
		$post = $this->own_post( $args, $user_id );
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}

		$change   = array( 'ID' => $post->ID );
		$warnings = array();
		$blocks   = null;

		if ( array_key_exists( 'title', $args ) ) {
			$title = trim( wp_strip_all_tags( (string) $args['title'] ) );
			if ( '' === $title ) {
				return $this->fail( 'AIWP_POST_INVALID', 'The title cannot be empty.' );
			}
			$change['post_title'] = $title;
		}
		if ( array_key_exists( 'body', $args ) ) {
			$body = $this->body( $args );
			if ( isset( $body['error'] ) ) {
				return $body['error'];
			}
			if ( '' === trim( $body['content'] ) ) {
				return $this->fail( 'AIWP_POST_INVALID', 'The new body was empty after cleaning, so nothing was changed.' );
			}
			$change['post_content'] = $body['content'];
			$warnings               = $body['warnings'];
			$blocks                 = $body['blocks'];
		}
		if ( array_key_exists( 'excerpt', $args ) ) {
			$change['post_excerpt'] = sanitize_textarea_field( (string) $args['excerpt'] );
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$slug = sanitize_title( (string) $args['slug'] );
			if ( '' === $slug || $this->slug_taken( $slug, $post->ID ) ) {
				return $this->fail( 'AIWP_SLUG_TAKEN', sprintf( 'The address "%s" is empty or already used. Choose another slug.', $slug ) );
			}
			$change['post_name'] = $slug;
		}

		$design = $this->articles->template_for( (string) ( $args['kind'] ?? '' ) );
		$fields = $this->check_fields( $args, $design );
		if ( isset( $fields['error'] ) ) {
			return $fields['error'];
		}
		$terms = $this->check_terms( $args );
		if ( isset( $terms['error'] ) ) {
			return $terms['error'];
		}
		$image = $this->check_image( $args );
		if ( isset( $image['error'] ) ) {
			return $image['error'];
		}

		// WordPress stores a revision on every save, so the text a published post had
		// before this change stays in its history (Revisions in the block editor).
		$before = count( wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) ) );

		if ( count( $change ) > 1 ) {
			$done = wp_update_post( $change, true );
			if ( is_wp_error( $done ) ) {
				return $this->fail( 'AIWP_POST_WRITE_FAILED', $done->get_error_message() );
			}
		}
		$revision = count( wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) ) ) > $before;

		$warnings = array_merge( $warnings, $this->apply_extras( $post->ID, $args, $design, $fields, $terms, $image ) );
		AuditLogger::log( 'post.update', array( 'post_id' => $post->ID, 'user_id' => $user_id ) );

		return array_merge(
			array( 'success' => true ),
			$this->summary( $post->ID ),
			array(
				'blocks'          => $blocks,
				'revision_saved'  => $revision,
				'warnings'        => array_values( $warnings ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function get( array $args, int $user_id ): array {
		$post = $this->own_post( $args, $user_id );
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}

		$design = $this->articles->template_for( (string) ( $args['kind'] ?? '' ) );
		$schema = $design->schema();
		$blocks = array();
		foreach ( parse_blocks( $post->post_content ) as $b ) {
			if ( null !== $b['blockName'] ) {
				$blocks[ $b['blockName'] ] = ( $blocks[ $b['blockName'] ] ?? 0 ) + 1;
			}
		}

		return array_merge(
			$this->summary( $post->ID ),
			array(
				'excerpt'         => $post->post_excerpt,
				'body'            => $post->post_content,
				'block_counts'    => $blocks,
				'words'           => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'categories'      => array_map( fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), get_the_category( $post->ID ) ),
				'tags'            => array_values( wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) ),
				'featured_image'  => (int) get_post_thumbnail_id( $post->ID ),
				'fields'          => null !== $schema ? $this->values->read( $post->ID, $schema, $design->field_uuid() ) : array(),
				'brief'           => PageBrief::for_post( $post->ID )->to_array(),
				'design_exists'   => $design->exists(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function publish( array $args, int $user_id ): array {
		if ( true !== ( $args['confirm_publish'] ?? false ) ) {
			return $this->fail( 'AIWP_CONFIRM_REQUIRED', 'Set confirm_publish to true. Only publish when the person has asked for it.' );
		}
		$post = $this->own_post( $args, $user_id );
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}
		if ( ! user_can( $user_id, 'publish_posts' ) ) {
			return $this->fail( 'AIWP_PERMISSION_DENIED', 'This connection cannot publish posts.' );
		}
		if ( '' === trim( $post->post_title ) || '' === trim( wp_strip_all_tags( $post->post_content ) ) ) {
			return $this->fail( 'AIWP_POST_INVALID', 'The post needs a title and a body before it can be published.' );
		}

		$when   = trim( (string) ( $args['publish_at'] ?? '' ) );
		$change = array( 'ID' => $post->ID, 'post_status' => 'publish' );
		if ( '' !== $when ) {
			$ts = strtotime( $when );
			if ( false === $ts ) {
				return $this->fail( 'AIWP_POST_INVALID', 'publish_at must be a date and time, for example 2026-11-02 09:00.' );
			}
			if ( $ts > time() ) {
				$change['post_status']   = 'future';
				$change['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
				$change['post_date']     = get_date_from_gmt( $change['post_date_gmt'] );
				$change['edit_date']     = true;
			}
		}

		$done = wp_update_post( $change, true );
		if ( is_wp_error( $done ) ) {
			return $this->fail( 'AIWP_POST_WRITE_FAILED', $done->get_error_message() );
		}
		AuditLogger::log( 'post.publish', array( 'post_id' => $post->ID, 'user_id' => $user_id, 'status' => $change['post_status'] ) );

		clean_post_cache( $post->ID );
		$status = (string) get_post_status( $post->ID );

		return array_merge(
			array( 'success' => true ),
			$this->summary( $post->ID ),
			array(
				'note' => 'future' === $status
					? sprintf( 'Scheduled. It goes live on %s (site time).', get_post_field( 'post_date', $post->ID ) )
					: 'Live now. Give this url to any tool that tracks published pages (for example ConvertRank mark_page_done).',
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function terms(): array {
		$cats = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			$cats[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'posts' => (int) $c->count, 'parent' => (int) $c->parent );
		}
		$tags = array();
		foreach ( get_tags( array( 'hide_empty' => false, 'number' => 100, 'orderby' => 'count', 'order' => 'DESC' ) ) as $t ) {
			$tags[] = array( 'id' => $t->term_id, 'name' => $t->name, 'posts' => (int) $t->count );
		}
		return array(
			'categories' => $cats,
			'tags'       => $tags,
			'note'       => 'Use category ids in post_create. Pick an existing category; do not make a new one for every article. Tags are names and are created when new.',
		);
	}

	public function preview_url( int $post_id ): string {
		if ( 'publish' === get_post_status( $post_id ) ) {
			return (string) get_permalink( $post_id );
		}
		return add_query_arg(
			array(
				'p'            => $post_id,
				'aiwp_preview' => PreviewToken::create( self::PREVIEW_PREFIX . $post_id, 0 ),
			),
			home_url( '/' )
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function preview( array $args, int $user_id ): array {
		$post = $this->own_post( $args, $user_id );
		if ( ! $post instanceof \WP_Post ) {
			return $post;
		}
		return array(
			'post_id'     => $post->ID,
			'preview_url' => $this->preview_url( $post->ID ),
			'status'      => $post->post_status,
			'expires_in'  => PreviewToken::TTL,
			'note'        => 'Open this in your browser and read the whole article before calling it finished.',
		);
	}

	// ------------------------------------------------------------- parts

	/**
	 * Clean the body and turn it into blocks.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed> content, blocks, warnings — or error
	 */
	private function body( array $args ): array {
		$raw    = (string) ( $args['body'] ?? '' );
		$format = strtolower( (string) ( $args['body_format'] ?? 'html' ) );
		if ( ! in_array( $format, array( 'html', 'markdown', 'blocks' ), true ) ) {
			return array( 'error' => $this->fail( 'AIWP_POST_INVALID', 'body_format must be html, markdown or blocks.' ) );
		}

		if ( 'blocks' === $format ) {
			// Already block markup (for example read back with post_get and edited). Keep the
			// blocks, but every block's HTML still goes through the same cleaning as a person's.
			$parsed = parse_blocks( $raw );
			$known  = \WP_Block_Type_Registry::get_instance();
			foreach ( $parsed as $b ) {
				if ( null !== $b['blockName'] && ! $known->is_registered( $b['blockName'] ) ) {
					return array( 'error' => $this->fail( 'AIWP_POST_INVALID', sprintf( 'Unknown block "%s". Send html or markdown instead.', $b['blockName'] ) ) );
				}
			}
			$count = static function ( array $blocks ) use ( &$count ): int {
				$n = 0;
				foreach ( $blocks as $b ) {
					$n += ( null !== $b['blockName'] ? 1 : 0 ) + $count( $b['innerBlocks'] );
				}
				return $n;
			};
			return array( 'content' => wp_kses_post( $raw ), 'blocks' => $count( $parsed ), 'warnings' => array() );
		}

		$html = 'markdown' === $format ? ( new MarkdownToHtml() )->convert( $raw ) : $raw;
		$html = wp_kses_post( $html );

		$converter = new BlockConverter(
			static function ( string $src ): ?array {
				$id = attachment_url_to_postid( $src );
				if ( ! $id ) {
					// A sized copy (image-1024x768.jpg) is still the same attachment.
					$id = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $src ) );
				}
				if ( ! $id || ! wp_attachment_is_image( $id ) ) {
					return null;
				}
				$url = wp_get_attachment_image_url( $id, 'large' );
				return $url ? array( 'id' => $id, 'url' => (string) $url ) : null;
			}
		);

		return $converter->convert( $html );
	}

	/**
	 * The article fields declared by the design, checked before anything is written.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed> the fields to write, or error
	 */
	private function check_fields( array $args, ArticleTemplate $design ): array {
		$fields = $args['fields'] ?? null;
		if ( null === $fields || array() === $fields ) {
			return array();
		}
		if ( ! is_array( $fields ) ) {
			return array( 'error' => $this->fail( 'AIWP_FIELDS_INVALID', 'fields must be an object: { section_id: { field_name: value } }.' ) );
		}
		$schema = $design->schema();
		if ( null === $schema ) {
			return array( 'error' => $this->fail( 'AIWP_FIELDS_INVALID', 'The article design has no extra fields yet. Leave fields out, or add them with article_set_template.' ) );
		}

		$unknown = array();
		foreach ( $fields as $section => $values ) {
			if ( ! is_array( $values ) ) {
				$unknown[] = (string) $section;
				continue;
			}
			foreach ( array_keys( $values ) as $name ) {
				if ( ! $schema->has_field( $section . '.' . $name ) ) {
					$unknown[] = $section . '.' . $name;
				}
			}
		}
		if ( array() !== $unknown ) {
			return array(
				'error' => $this->fail(
					'AIWP_FIELDS_INVALID',
					sprintf( 'The article design has no field %s. It has: %s.', implode( ', ', $unknown ), implode( ', ', array_keys( $schema->fields() ) ) )
				),
			);
		}
		return array( 'fields' => $fields, 'schema' => $schema );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function check_terms( array $args ): array {
		$cats = array();
		foreach ( (array) ( $args['categories'] ?? array() ) as $id ) {
			$id = absint( $id );
			if ( ! $id || ! term_exists( $id, 'category' ) ) {
				return array( 'error' => $this->fail( 'AIWP_TERM_INVALID', sprintf( 'There is no category with id %d. Use terms_list.', $id ) ) );
			}
			$cats[] = $id;
		}
		$tags = array_values( array_filter( array_map( fn( $t ) => trim( sanitize_text_field( (string) $t ) ), (array) ( $args['tags'] ?? array() ) ) ) );
		return array( 'categories' => $cats, 'tags' => $tags, 'set_categories' => array_key_exists( 'categories', $args ), 'set_tags' => array_key_exists( 'tags', $args ) );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function check_image( array $args ): array {
		if ( ! array_key_exists( 'featured_image_id', $args ) ) {
			return array();
		}
		$id = absint( $args['featured_image_id'] );
		if ( 0 === $id ) {
			return array( 'remove' => true );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return array( 'error' => $this->fail( 'AIWP_MEDIA_INVALID', sprintf( 'Media %d is not an image in the library. Use media_search or media_import.', $id ) ) );
		}
		return array( 'id' => $id );
	}

	/**
	 * Fields, categories, tags, featured image and the SEO brief.
	 *
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $fields
	 * @param array<string,mixed> $terms
	 * @param array<string,mixed> $image
	 * @return string[] warnings
	 */
	private function apply_extras( int $post_id, array $args, ArticleTemplate $design, array $fields, array $terms, array $image ): array {
		$warnings = array();

		if ( isset( $fields['fields'] ) && $fields['schema'] instanceof PageSchema ) {
			foreach ( $this->values->write( $post_id, $fields['schema'], $fields['fields'], $design->field_uuid() ) as $problem ) {
				$warnings[] = $problem;
			}
		}
		if ( ! empty( $terms['set_categories'] ) ) {
			wp_set_post_categories( $post_id, $terms['categories'] );
		}
		if ( ! empty( $terms['set_tags'] ) ) {
			wp_set_post_tags( $post_id, $terms['tags'], false );
		}
		if ( isset( $image['id'] ) ) {
			set_post_thumbnail( $post_id, (int) $image['id'] );
		} elseif ( ! empty( $image['remove'] ) ) {
			delete_post_thumbnail( $post_id );
		}

		// meta title, description and schema types go into the brief, which MetaTags,
		// SchemaGraph and seo_audit already read for posts.
		$seo = array_filter(
			array(
				'meta_title'       => isset( $args['meta_title'] ) ? sanitize_text_field( (string) $args['meta_title'] ) : null,
				'meta_description' => isset( $args['meta_description'] ) ? sanitize_textarea_field( (string) $args['meta_description'] ) : null,
				'primary_keyword'  => isset( $args['primary_keyword'] ) ? sanitize_text_field( (string) $args['primary_keyword'] ) : null,
				'schema_types'     => isset( $args['schema_types'] ) ? array_values( array_map( 'sanitize_text_field', (array) $args['schema_types'] ) ) : null,
				'questions'        => isset( $args['questions'] ) ? array_values( array_map( 'sanitize_text_field', (array) $args['questions'] ) ) : null,
			),
			static fn( $v ) => null !== $v
		);
		if ( array() !== $seo ) {
			$brief = PageBrief::from_array( array_merge( PageBrief::for_post( $post_id )->to_array(), $seo ) );
			if ( ! $brief->save( $post_id ) ) {
				$warnings[] = 'The meta title, description or schema could not be stored.';
			}
		}

		return $warnings;
	}

	/**
	 * Things about the site that stop an article looking right, said plainly.
	 *
	 * @return string[]
	 */
	private function site_warnings( ArticleTemplate $design ): array {
		$out = array();

		if ( ! function_exists( 'use_block_editor_for_post_type' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		if ( function_exists( 'use_block_editor_for_post_type' ) && ! use_block_editor_for_post_type( ArticleManager::POST_TYPE ) ) {
			$out[] = 'Gutenberg is off for posts on this site (for example the Classic Editor plugin), so this post opens in the classic editor. The text is still there.';
		}
		if ( ! $design->exists() ) {
			$out[] = 'There is no article design yet, so this post uses the theme\'s look. Build one with build_article_template so every article matches the site.';
		}
		if ( 'page' === get_option( 'show_on_front' ) && ! (int) get_option( 'page_for_posts' ) ) {
			$out[] = 'The site has no blog page (Settings > Reading > Posts page), so there is no list where readers find articles. The post still has its own address.';
		}
		if ( ! $this->articles->archive()->exists() ) {
			$out[] = 'There is no design for the list of articles yet. build_archive makes the blog and category pages match the site.';
		}

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function summary( int $post_id ): array {
		return array(
			'post_id'     => $post_id,
			'title'       => (string) get_the_title( $post_id ),
			'slug'        => (string) get_post_field( 'post_name', $post_id ),
			'status'      => (string) get_post_status( $post_id ),
			'url'         => (string) get_permalink( $post_id ),
			'preview_url' => $this->preview_url( $post_id ),
			'edit_url'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * The post named by post_id, if it is a post this user may edit.
	 *
	 * @param array<string,mixed> $args
	 * @return \WP_Post|array<string,mixed>
	 */
	private function own_post( array $args, int $user_id ) {
		$post = get_post( absint( $args['post_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post || ArticleManager::POST_TYPE !== $post->post_type ) {
			return $this->fail( 'AIWP_POST_INVALID', 'There is no post with that id. Pages have their own page_ tools.' );
		}
		if ( 'trash' === $post->post_status ) {
			return $this->fail( 'AIWP_POST_INVALID', 'That post is in the trash.' );
		}
		if ( ! user_can( $user_id, 'edit_post', $post->ID ) ) {
			return $this->fail( 'AIWP_PERMISSION_DENIED', 'This connection cannot edit that post.' );
		}
		return $post;
	}

	private function slug_taken( string $slug, int $except ): bool {
		if ( '' === $slug ) {
			return false;
		}
		global $wpdb;
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('post','page') AND post_status NOT IN ('trash','auto-draft','inherit') AND ID <> %d LIMIT 1",
				$slug,
				$except
			)
		);
		return $found > 0;
	}

	/**
	 * @param string[] $details
	 * @return array<string,mixed>
	 */
	private function fail( string $code, string $message, array $details = array() ): array {
		return array( 'success' => false, 'error' => array( 'code' => $code, 'message' => $message, 'details' => $details ) );
	}
}
