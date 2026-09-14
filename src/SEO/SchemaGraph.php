<?php
declare( strict_types = 1 );

namespace AIWP\Designer\SEO;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Rendering\PageRenderer;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Structured data, built by the plugin from what the page actually contains.
 *
 * Deliberately not written by the AI. Schema is a set of claims a search engine
 * treats as facts, and a model asked to produce it will happily assert an
 * aggregateRating nobody measured or an FAQ that is not on the page. Everything
 * here is derived from the rendered page or from WordPress, so it cannot claim
 * something the page does not say.
 *
 * The brief can ask for a type. It cannot supply the values.
 */
final class SchemaGraph {

	/** Types we know how to build honestly. Anything else is ignored. */
	public const SUPPORTED = array( 'WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList', 'FAQPage' );

	private PageRepository $pages;

	private PageRenderer $renderer;

	public function __construct( PageRepository $pages, PageRenderer $renderer ) {
		$this->pages    = $pages;
		$this->renderer = $renderer;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_graph' ), 5 );
	}

	public function print_graph(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$graph = $this->build( $post );
		if ( array() === $graph ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			// wp_json_encode escapes for HTML; the tag itself is fixed.
			(string) wp_json_encode(
				array( '@context' => 'https://schema.org', '@graph' => $graph ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function build( \WP_Post $post ): array {
		$brief = PageBrief::for_post( $post->ID );
		$want  = array_map( 'strval', (array) $brief->get( 'schema_types', array() ) );
		$html  = $this->html( $post );
		$url   = (string) get_permalink( $post );
		$home  = untrailingslashit( (string) home_url() );

		$graph = array(
			array(
				'@type' => 'WebSite',
				'@id'   => $home . '/#website',
				'url'   => $home . '/',
				'name'  => get_bloginfo( 'name' ),
			),
			array(
				'@type' => 'Organization',
				'@id'   => $home . '/#organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => $home . '/',
			),
		);

		$is_article = 'post' === $post->post_type
			|| in_array( 'Article', $want, true );

		$page = array(
			'@type'      => $is_article ? 'Article' : 'WebPage',
			'@id'        => $url . '#page',
			'url'        => $url,
			'name'       => MetaTags::title_for( $post, $brief ),
			'isPartOf'   => array( '@id' => $home . '/#website' ),
			'datePublished' => (string) get_the_date( 'c', $post ),
			'dateModified'  => (string) get_the_modified_date( 'c', $post ),
		);

		$description = MetaTags::description_for( $post, $brief );
		if ( '' !== trim( $description ) ) {
			$page['description'] = wp_strip_all_tags( $description );
		}

		if ( $is_article ) {
			$page['headline'] = get_the_title( $post );
			$page['author']   = array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			);
			$page['publisher'] = array( '@id' => $home . '/#organization' );

			$image = get_the_post_thumbnail_url( $post, 'full' );
			if ( is_string( $image ) && '' !== $image ) {
				$page['image'] = $image;
			}
		}

		$graph[] = $page;

		$crumbs = $this->breadcrumbs( $post, $url, $home );
		if ( array() !== $crumbs ) {
			$graph[] = $crumbs;
		}

		// FAQ schema only where the page genuinely has questions and answers.
		// Asking for the type is not enough: the markup has to be there.
		$faq = $this->faq( $html, $url );
		if ( array() !== $faq ) {
			$graph[] = $faq;
		}

		return $graph;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function breadcrumbs( \WP_Post $post, string $url, string $home ): array {
		$items = array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'aiwp-designer' ), 'item' => $home . '/' ),
		);

		$parent = (int) $post->post_parent;
		if ( $parent > 0 ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => 2,
				'name'     => (string) get_the_title( $parent ),
				'item'     => (string) get_permalink( $parent ),
			);
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => count( $items ) + 1,
			'name'     => (string) get_the_title( $post ),
			'item'     => $url,
		);

		if ( count( $items ) < 2 ) {
			return array();
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumbs',
			'itemListElement' => $items,
		);
	}

	/**
	 * Questions and answers actually present on the page.
	 *
	 * A heading that asks something, with text under it before the next
	 * heading. If the page has none, no FAQPage is emitted however loudly the
	 * brief asked for one.
	 *
	 * @return array<string,mixed>
	 */
	private function faq( string $html, string $url ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadHTML( '<?xml encoding="utf-8" ?><div id="aiwp-faq">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok ) {
			return array();
		}

		$xpath = new DOMXPath( $doc );
		$pairs = array();

		foreach ( $xpath->query( '//h2|//h3|//h4|//dt' ) as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}

			$question = trim( (string) $heading->textContent );
			if ( '' === $question || false === strpos( $question, '?' ) ) {
				continue;
			}

			$answer = '';
			$node   = $heading->nextSibling;

			while ( $node instanceof \DOMNode ) {
				if ( $node instanceof DOMElement && preg_match( '/^(h[1-6]|dt)$/i', $node->nodeName ) ) {
					break;
				}
				$answer .= ' ' . trim( (string) $node->textContent );
				$node    = $node->nextSibling;
			}

			$answer = trim( (string) preg_replace( '/\s+/', ' ', $answer ) );

			if ( strlen( $answer ) < 40 ) {
				continue;
			}

			$pairs[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ),
			);
		}

		if ( count( $pairs ) < 2 ) {
			return array();
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'mainEntity' => array_slice( $pairs, 0, 20 ),
		);
	}

	private function html( \WP_Post $post ): string {
		if ( $this->pages->is_aiwp_page( $post->ID ) ) {
			return $this->renderer->render( $post->ID );
		}

		return (string) apply_filters( 'the_content', (string) $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}
}
