<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Rendering;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Pages\PreviewToken;

/**
 * Puts the plugin's own page shell in front of AIWP pages, and lets a valid
 * signed preview token view a draft.
 */
final class TemplateLoader {

	private PageRepository $pages;

	public function __construct( PageRepository $pages ) {
		$this->pages = $pages;
	}

	public function register(): void {
		add_filter( 'template_include', array( $this, 'template_include' ), 99 );
		add_action( 'pre_get_posts', array( $this, 'allow_signed_preview' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	public function template_include( string $template ): string {
		$page_id = get_queried_object_id();
		if ( ! $page_id || ! $this->pages->is_aiwp_page( $page_id ) ) {
			return $template;
		}

		$shell = AIWP_PLUGIN_DIR . 'templates/page-shell.php';
		return file_exists( $shell ) ? $shell : $template;
	}

	/**
	 * @param \WP_Query $query
	 */
	public function allow_signed_preview( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['aiwp_preview'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['aiwp_preview'] ) ) : '';
		if ( '' === $token ) {
			return;
		}

		$verified = PreviewToken::verify( $token );
		if ( ! $verified['valid'] ) {
			return;
		}

		$page_id = $this->pages->find_by_uuid( $verified['page_uuid'] );
		if ( ! $page_id ) {
			return;
		}

		$query->set( 'page_id', $page_id );
		$query->set( 'post_type', 'page' );
		$query->set( 'post_status', array( 'draft', 'pending', 'private', 'publish', 'future' ) );
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		$page_id = get_queried_object_id();
		if ( $page_id && $this->pages->is_aiwp_page( $page_id ) ) {
			$classes[] = 'aiwp-designed';
		}
		return $classes;
	}
}
