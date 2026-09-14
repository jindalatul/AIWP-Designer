<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Rendering;

use AIWP\Designer\Chrome\ChromeManager;
use AIWP\Designer\Design\DesignSystemRepository;
use AIWP\Designer\Pages\PageRepository;

/**
 * Loads exactly the CSS and JS one page needs, and nothing else.
 */
final class AssetManager {

	public const BEHAVIORS = array( 'accordion', 'tabs', 'modal', 'counter', 'reveal', 'sticky', 'carousel' );

	/**
	 * The behaviors a page needs: the ones its markup uses, plus any declared.
	 *
	 * These used to be two statements that could disagree, and when they did
	 * the result was invisible content. The baseline CSS hides a reveal until
	 * a script adds is-revealed; the script is only loaded when the manifest
	 * lists the behavior; so a template that used the attribute without also
	 * repeating itself in the list rendered a band of nothing. No error, on a
	 * published page. I did it myself on three pages of a real site.
	 *
	 * The markup is the truth now. The validator has already refused any name
	 * that is not a real behavior, so nothing unknown can arrive this way.
	 *
	 * @param string[] $declared
	 * @return string[]
	 */
	public static function behaviors_for( string $template, array $declared = array() ): array {
		$found = array_map( 'strval', $declared );

		if ( preg_match_all( '/data-aiwp-behavior\s*=\s*(["\'])(.*?)\1/i', $template, $matches ) ) {
			foreach ( $matches[2] as $value ) {
				foreach ( preg_split( '/\s+/', trim( (string) $value ) ) ?: array() as $name ) {
					if ( '' !== $name && in_array( $name, self::BEHAVIORS, true ) ) {
						$found[] = $name;
					}
				}
			}
		}

		$found = array_values( array_unique( array_filter( $found ) ) );
		sort( $found );

		return $found;
	}


	private PageRepository $pages;
	private DesignSystemRepository $design;
	private ?ChromeManager $chrome;
	private ?\AIWP\Designer\Articles\ArticleManager $articles;

	public function __construct(
		PageRepository $pages,
		DesignSystemRepository $design,
		?ChromeManager $chrome = null,
		?\AIWP\Designer\Articles\ArticleManager $articles = null
	) {
		$this->pages    = $pages;
		$this->design   = $design;
		$this->chrome   = $chrome;
		$this->articles = $articles;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
	}

	/**
	 * Warm up the font host so text does not arrive late.
	 *
	 * @param string[] $hints
	 * @return string[]
	 */
	public function resource_hints( array $hints, string $relation ): array {
		if ( 'preconnect' !== $relation ) {
			return $hints;
		}

		$font_url = $this->design->current()->font_url();
		if ( '' === $font_url ) {
			return $hints;
		}

		$host = wp_parse_url( $font_url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return $hints;
		}

		$hints[] = 'https://' . $host;
		if ( 'fonts.googleapis.com' === $host ) {
			$hints[] = array(
				'href'        => 'https://fonts.gstatic.com',
				'crossorigin' => 'anonymous',
			);
		}

		return $hints;
	}

	/**
	 * The article design for the post being viewed, or null when this is not an
	 * article the plugin renders.
	 */
	private function enqueue_chrome_css(): void {
		if ( ! $this->chrome instanceof ChromeManager || ! $this->chrome->exists() ) {
			return;
		}

		$url = $this->chrome->css_url();
		if ( '' === $url ) {
			return;
		}

		wp_enqueue_style( 'aiwp-chrome', $url, array( 'aiwp-global' ), (string) $this->file_version( $this->chrome->css_path() ) );
	}

	private function article_design(): ?\AIWP\Designer\Articles\ArticleTemplate {
		if ( ! $this->articles instanceof \AIWP\Designer\Articles\ArticleManager ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->articles->applies_to( $post ) ) {
			return null;
		}

		return $this->articles->template_for( $this->articles->kind_for( $post ) );
	}

	public function enqueue(): void {
		$page_id = get_queried_object_id();
		$is_page = $page_id && $this->pages->is_aiwp_page( $page_id );
		$article = $this->article_design();
		$archive = $this->articles instanceof \AIWP\Designer\Articles\ArticleManager
			&& $this->articles->archive_applies();

		// An article is not an AIWP page, but it still needs the site's fonts,
		// baseline and tokens. Loading them in one place keeps the order right:
		// fonts, baseline, design system, then whatever is specific to this view.
		if ( ! $is_page && null === $article && ! $archive ) {
			return;
		}

		// Site fonts first, so the browser starts fetching them before the CSS.
		$font_url = $this->design->current()->font_url();
		if ( '' !== $font_url ) {
			wp_enqueue_style( 'aiwp-fonts', $font_url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		// The plugin's own baseline. Shipped with the plugin, so an update applies
		// everywhere without the site having to re-save its design system.
		// Versioned by the file itself, not by the plugin version. The plugin
		// version sat at 0.3.0 through four releases of CSS changes, so every
		// browser that had ever loaded this file kept the old one.
		wp_enqueue_style(
			'aiwp-base',
			AIWP_PLUGIN_URL . 'assets/dist/base.css',
			array(),
			(string) $this->file_version( AIWP_PLUGIN_DIR . 'assets/dist/base.css' )
		);

		$global_url = $this->design->global_css_url();
		if ( '' !== $global_url ) {
			wp_enqueue_style(
				'aiwp-global',
				$global_url,
				array( 'aiwp-base' ),
				(string) $this->file_version( $this->design->global_css_path() )
			);
		}

		if ( $archive ) {
			$design = $this->articles->archive();
			$url    = $design->css_url();

			if ( '' !== $url ) {
				wp_enqueue_style( 'aiwp-archive', $url, array( 'aiwp-global' ), (string) $this->file_version( $design->css_path() ) );
			}

			$this->enqueue_chrome_css();

			return;
		}

		if ( null !== $article ) {
			$article_url = $article->css_url();
			if ( '' !== $article_url ) {
				wp_enqueue_style(
					'aiwp-article-' . $article->kind(),
					$article_url,
					array( 'aiwp-global' ),
					(string) $this->file_version( $article->css_path() )
				);
			}

			$this->enqueue_chrome_css();

			return;
		}

		$page_url = $this->pages->css_url( $page_id );
		if ( '' !== $page_url ) {
			wp_enqueue_style(
				'aiwp-page-' . $page_id,
				$page_url,
				array( 'aiwp-global' ),
				(string) $this->file_version( $this->pages->css_path( $page_id ) )
			);
		}

		if ( $this->chrome instanceof ChromeManager && 'site' === $this->pages->manifest( $page_id )->get( 'chrome', 'theme' ) ) {
			$chrome_url = $this->chrome->css_url();
			if ( '' !== $chrome_url ) {
				wp_enqueue_style(
					'aiwp-chrome',
					$chrome_url,
					array( 'aiwp-global' ),
					(string) $this->file_version( $this->chrome->css_path() )
				);
			}
		}

		$behaviors = (array) $this->pages->manifest( $page_id )->get( 'behaviors', array() );
		if ( array() !== $behaviors ) {
			wp_enqueue_script(
				'aiwp-behaviors',
				AIWP_PLUGIN_URL . 'assets/dist/behaviors.js',
				array(),
				AIWP_VERSION,
				true
			);
		}
	}

	private function file_version( string $path ): int {
		$time = file_exists( $path ) ? filemtime( $path ) : 0;
		return false === $time ? 0 : (int) $time;
	}
}
