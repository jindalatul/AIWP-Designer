<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\ACF\SchemaRegistrar;
use AIWP\Designer\Storage\FileStore;

/**
 * Wires the article design into WordPress.
 *
 * Three jobs: put the plugin's article shell in front of single posts when a
 * design exists, load its stylesheet there, and register the extra fields
 * against the post type so a writer can fill them in.
 *
 * The block editor is deliberately left alone on posts. It is turned off for
 * AIWP pages, where the design is the whole page and there is nothing to type.
 * An article is the opposite: the body is the point, and the person writing it
 * needs a real editor.
 */
final class ArticleManager {

	public const POST_TYPE = 'post';

	private FileStore $files;

	private FieldValueManager $values;

	private SchemaRegistrar $schemas;

	public function __construct( FileStore $files, FieldValueManager $values, SchemaRegistrar $schemas ) {
		$this->files   = $files;
		$this->values  = $values;
		$this->schemas = $schemas;
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'template_include' ), 98 );
		add_action( 'acf/init', array( $this, 'register_fields' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/** The design for any list of articles. */
	public function archive(): ArchiveTemplate {
		return new ArchiveTemplate( $this->files );
	}

	/**
	 * Is this a list of posts the plugin has a design for?
	 *
	 * Deliberately not is_archive() alone: the blog index is is_home(), and a
	 * search page is neither, and all three are the same page to a reader.
	 */
	public function archive_applies(): bool {
		if ( is_singular() || is_404() || is_admin() ) {
			return false;
		}

		$listing = is_home() || is_archive() || is_search();

		return $listing && $this->archive()->exists();
	}

	public function render_archive(): string {
		return ( new ArchiveRenderer( $this->archive() ) )->render();
	}

	public function template_for( ?string $kind = null ): ArticleTemplate {
		return new ArticleTemplate( $this->files, $kind ?? ArticleTemplate::DEFAULT_KIND );
	}

	/**
	 * Which article design a post uses. One kind today; the hook is here so a
	 * later step can choose by category without moving anything.
	 */
	public function kind_for( \WP_Post $post ): string {
		$kind = apply_filters( 'aiwp/article_kind', ArticleTemplate::DEFAULT_KIND, $post );

		return is_string( $kind ) && '' !== $kind ? $kind : ArticleTemplate::DEFAULT_KIND;
	}

	public function renderer_for( \WP_Post $post ): ArticleRenderer {
		return new ArticleRenderer( $this->template_for( $this->kind_for( $post ) ), $this->values );
	}

	public function applies_to( ?\WP_Post $post ): bool {
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return $this->template_for( $this->kind_for( $post ) )->exists();
	}

	public function template_include( string $template ): string {
		if ( $this->archive_applies() ) {
			$shell = AIWP_PLUGIN_DIR . 'templates/archive-shell.php';
			if ( file_exists( $shell ) ) {
				return $shell;
			}
		}

		if ( ! is_singular( self::POST_TYPE ) ) {
			return $template;
		}

		$post = get_queried_object();
		if ( ! $this->applies_to( $post instanceof \WP_Post ? $post : null ) ) {
			return $template;
		}

		$shell = AIWP_PLUGIN_DIR . 'templates/article-shell.php';

		return file_exists( $shell ) ? $shell : $template;
	}

	/**
	 * Register the article's extra fields against the post type, so they appear
	 * under the editor like any other custom fields.
	 */
	public function register_fields(): void {
		foreach ( ArticleTemplate::kinds() as $kind ) {
			$design = $this->template_for( $kind );
			$schema = $design->schema();

			if ( null === $schema ) {
				continue;
			}

			$this->schemas->register_for_post_type( $schema, self::POST_TYPE, $design->field_uuid() );
		}
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $classes;
		}

		$post = get_queried_object();
		if ( $this->applies_to( $post instanceof \WP_Post ? $post : null ) ) {
			$classes[] = 'aiwp-article-page';
		}

		return $classes;
	}
}
