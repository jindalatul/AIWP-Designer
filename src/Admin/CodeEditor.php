<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;

/**
 * A developer's view of the template and CSS behind a page.
 *
 * Deliberately not on the main path: most changes should be asked for in words.
 * Everything typed here goes through the same validators as the AI's output, and
 * saving creates a version, so nothing can be stored by hand that the AI could
 * not have stored — and anything that goes wrong can be rolled back.
 *
 * There is no JavaScript editor. The reason an AI can be trusted with a live site
 * is that nothing it writes is executed, and a box that runs code would end that.
 */
final class CodeEditor {

	public const SLUG = 'aiwp-code';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function enqueue(): void {
		// Both modes, because a page has a template and a stylesheet.
		$html = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		$css  = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );

		if ( false === $html && false === $css ) {
			// The user turned the syntax highlighter off in their profile.
			return;
		}

		wp_enqueue_script(
			'aiwp-code-editor',
			AIWP_PLUGIN_URL . 'assets/dist/code-editor.js',
			array( 'jquery', 'wp-theme-plugin-editor', 'code-editor' ),
			AIWP_VERSION,
			true
		);

		wp_enqueue_style(
			'aiwp-code-editor',
			AIWP_PLUGIN_URL . 'assets/dist/code-editor.css',
			array( 'code-editor', 'dashicons' ),
			AIWP_VERSION
		);

		wp_localize_script(
			'aiwp-code-editor',
			'aiwpCode',
			array(
				'root'     => esc_url_raw( rest_url( AIWP_REST_NAMESPACE . '/code' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'settings' => array(
					'aiwp' => $html,
					'css'  => $css,
				),
				'filters'  => \AIWP\Designer\Template\TemplateParser::FILTERS,
				'i18n'     => array(
					'checking'   => __( 'Checking…', 'aiwp-designer' ),
					'saving'     => __( 'Saving…', 'aiwp-designer' ),
					'clean'      => __( 'No problems found.', 'aiwp-designer' ),
					'saved'      => __( 'Saved as version %d.', 'aiwp-designer' ),
					'unchanged'  => __( 'Nothing changed, so no new version was made.', 'aiwp-designer' ),
					'notSaved'   => __( 'Not saved — fix the problems below first.', 'aiwp-designer' ),
					'failed'     => __( 'Something went wrong. Nothing was saved.', 'aiwp-designer' ),
					'unsaved'    => __( 'You have unsaved changes.', 'aiwp-designer' ),
					'leaveWarn'  => __( 'You have unsaved changes in the code editor.', 'aiwp-designer' ),
					'phpRefused' => __( 'PHP is not allowed here, and would be refused on save.', 'aiwp-designer' ),
					'line'       => __( 'line %d', 'aiwp-designer' ),
				),
			)
		);
	}

	public function render(): void {
		$target  = $this->current_target();
		$page_id = $this->current_page_id();

		echo '<div class="wrap aiwp-code">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Code', 'aiwp-designer' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->render_picker( $target, $page_id );

		if ( 'page' === $target && 0 === $page_id ) {
			echo '<p>' . esc_html__( 'Choose something to edit.', 'aiwp-designer' ) . '</p></div>';
			return;
		}

		printf(
			'<div id="aiwp-code-app" data-target="%s" data-page="%d"><p class="aiwp-code__loading">%s</p></div>',
			esc_attr( $target ),
			$page_id,
			esc_html__( 'Loading…', 'aiwp-designer' )
		);

		echo '</div>';
	}

	private function render_picker( string $target, int $page_id ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );

		echo '<form method="get" class="aiwp-code__picker">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		printf( '<label for="aiwp-code-target">%s</label> ', esc_html__( 'Editing', 'aiwp-designer' ) );

		echo '<select name="aiwp_target" id="aiwp-code-target" onchange="this.form.submit()">';
		printf(
			'<option value="chrome"%s>%s</option>',
			selected( $target, 'chrome', false ),
			esc_html__( 'Header & footer', 'aiwp-designer' )
		);

		echo '<optgroup label="' . esc_attr__( 'Pages', 'aiwp-designer' ) . '">';
		foreach ( $this->plugin->pages()->content_page_ids() as $id ) {
			printf(
				'<option value="page:%d"%s>%s</option>',
				$id,
				selected( 'page' === $target && $id === $page_id, true, false ),
				esc_html( (string) get_the_title( $id ) )
			);
		}
		echo '</optgroup></select>';

		if ( 'page' === $target && $page_id > 0 ) {
			printf(
				' <a class="button" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $this->plugin->page_manager()->preview_url( $page_id ) ),
				esc_html__( 'Preview', 'aiwp-designer' )
			);
		}

		printf(
			'<span class="aiwp-code__hint">%s</span>',
			esc_html__( 'Checked before it saves. Every save keeps a version.', 'aiwp-designer' )
		);

		unset( $base );
		echo '</form>';
	}

	private function current_target(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( (string) ( $_GET['aiwp_target'] ?? '' ) ) );

		if ( 'chrome' === $raw || '' === $raw ) {
			return 'chrome' === $raw ? 'chrome' : ( $this->first_page_id() > 0 ? 'page' : 'chrome' );
		}

		return 0 === strpos( $raw, 'page:' ) ? 'page' : 'chrome';
	}

	private function current_page_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( (string) ( $_GET['aiwp_target'] ?? '' ) ) );

		if ( 0 === strpos( $raw, 'page:' ) ) {
			$id = absint( substr( $raw, 5 ) );
			return $this->plugin->pages()->is_aiwp_page( $id ) ? $id : 0;
		}

		return '' === $raw ? $this->first_page_id() : 0;
	}

	private function first_page_id(): int {
		$ids = $this->plugin->pages()->content_page_ids();
		return $ids ? (int) $ids[0] : 0;
	}

	public static function url_for_page( int $page_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&aiwp_target=page:' . $page_id );
	}

	public static function can_use(): bool {
		return CapabilityManager::current_user_can( CapabilityManager::MANAGE_DESIGN );
	}
}
