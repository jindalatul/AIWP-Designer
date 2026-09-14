<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Chrome\ChromeManager;
use AIWP\Designer\Plugin;

/**
 * The WordPress edit screen for an AIWP page.
 *
 * An AIWP page's content lives in fields, and its layout lives in a template the
 * block editor cannot see. Showing Gutenberg there offers an editing surface that
 * does nothing and invites people to break the page. This strips the screen back
 * to the title and the fields.
 */
final class EditorScreen {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_filter( 'use_block_editor_for_post', array( $this, 'disable_block_editor' ), 20, 2 );
		add_action( 'load-post.php', array( $this, 'prepare_screen' ) );
		add_action( 'load-post-new.php', array( $this, 'prepare_screen' ) );
		add_action( 'edit_form_after_title', array( $this, 'render_notice' ) );
		add_action( 'admin_head', array( $this, 'screen_styles' ) );

		// The shared header and footer is a holder, not a page. It must never be
		// published or deleted by accident.
		add_filter( 'wp_insert_post_data', array( $this, 'keep_chrome_private' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'protect_chrome' ), 10, 4 );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public function keep_chrome_private( $data, $postarr ) {
		$post_id = absint( $postarr['ID'] ?? 0 );

		if ( $post_id > 0 && $this->plugin->chrome()->is_holder( $post_id ) ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * @param string[] $caps
	 * @param string   $cap
	 * @param int      $user_id
	 * @param array    $args
	 * @return string[]
	 */
	public function protect_chrome( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'delete_post', 'delete_page' ), true ) ) {
			return $caps;
		}

		$post_id = absint( $args[0] ?? 0 );

		if ( $post_id > 0 && $this->plugin->chrome()->is_holder( $post_id ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * @param bool     $use_block_editor
	 * @param \WP_Post $post
	 */
	public function disable_block_editor( $use_block_editor, $post ) {
		if ( $post instanceof \WP_Post && $this->is_aiwp( (int) $post->ID ) ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Take away everything on the screen that does not apply to an AIWP page.
	 */
	public function prepare_screen(): void {
		$post_id = $this->current_post_id();
		if ( 0 === $post_id || ! $this->is_aiwp( $post_id ) ) {
			return;
		}

		// No content box: the page is rendered from its template, not from post content.
		remove_post_type_support( 'page', 'editor' );
		remove_post_type_support( 'page', 'excerpt' );
		remove_post_type_support( 'page', 'comments' );
		remove_post_type_support( 'page', 'trackbacks' );
		remove_post_type_support( 'page', 'custom-fields' );

		add_action(
			'add_meta_boxes',
			static function (): void {
				remove_meta_box( 'postcustom', 'page', 'normal' );
				remove_meta_box( 'commentstatusdiv', 'page', 'normal' );
				remove_meta_box( 'commentsdiv', 'page', 'normal' );
				remove_meta_box( 'trackbacksdiv', 'page', 'normal' );
				remove_meta_box( 'slugdiv', 'page', 'normal' );
				// The page template is owned by the plugin.
				remove_meta_box( 'pageparentdiv', 'page', 'side' );
			},
			100
		);
	}

	/**
	 * A short line under the title saying where the design lives.
	 *
	 * @param \WP_Post $post
	 */
	public function render_notice( $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_aiwp( (int) $post->ID ) ) {
			return;
		}

		$page_id  = (int) $post->ID;
		$is_chrome = $this->plugin->chrome()->is_holder( $page_id );
		$version   = $this->plugin->pages()->active_version( $page_id );

		echo '<div class="aiwp-editor-note">';
		echo '<p class="aiwp-editor-note__lead">';

		if ( $is_chrome ) {
			echo esc_html__( 'This is the shared header and footer. What you change here appears on every AIWP page.', 'aiwp-designer' );
		} else {
			echo esc_html__( 'Edit the words and images in the fields below. The layout comes from this page\'s AIWP template, so there is no block editor here.', 'aiwp-designer' );
		}

		echo '</p>';

		// A prominent button rather than a panel at the foot of the screen.
		// Changing what fields exist is a different job from filling them in,
		// and it should be one obvious click away rather than a scroll away.
		if ( ! $is_chrome ) {
			echo '<p class="aiwp-editor-note__do">';

			printf(
				'<a class="button button-primary button-hero" href="%s">%s</a>',
				esc_url( FieldsScreen::url_for( $page_id ) ),
				esc_html__( 'Add or remove fields', 'aiwp-designer' )
			);

			if ( CodeEditor::can_use() ) {
				printf(
					'<a class="button button-hero" href="%s">%s</a>',
					esc_url( CodeEditor::url_for_page( $page_id ) ),
					esc_html__( 'Template &amp; CSS', 'aiwp-designer' )
				);
			}

			printf(
				'<span class="aiwp-editor-note__hint">%s</span>',
				esc_html__( 'Both open their own screen. Your words are safe — nothing here is unsaved.', 'aiwp-designer' )
			);

			echo '</p>';
		}

		echo '<p class="aiwp-editor-note__meta">';

		if ( ! $is_chrome ) {
			printf(
				'<span>%s</span>',
				esc_html( sprintf( /* translators: %d: version number */ __( 'AIWP version %d', 'aiwp-designer' ), $version ) )
			);
			printf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $this->plugin->page_manager()->preview_url( $page_id ) ),
				esc_html__( 'Preview this page', 'aiwp-designer' )
			);
		}

		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=aiwp-pages' ) ),
			esc_html__( 'All AI pages', 'aiwp-designer' )
		);

		echo '</p></div>';
	}

	public function screen_styles(): void {
		$post_id = $this->current_post_id();
		if ( 0 === $post_id || ! $this->is_aiwp( $post_id ) ) {
			return;
		}

		$chrome_css = $this->plugin->chrome()->is_holder( $post_id )
			// Publishing or trashing the holder would break the chrome on every page.
			? '#publishing-action,#delete-action,#misc-publishing-actions .misc-pub-visibility,#misc-publishing-actions .misc-pub-post-status{display:none}'
			: '';

		echo '<style>' . esc_html( $chrome_css ) . '
			.aiwp-editor-note{margin:14px 0 6px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #4d2559;border-radius:3px}
			.aiwp-editor-note__lead{margin:0;font-size:13px;color:#1d2327}
			.aiwp-editor-note__do{margin:14px 0 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
			.aiwp-editor-note__hint{font-size:12px;color:#646970}
			.aiwp-editor-note__meta{margin:12px 0 0;padding-top:10px;border-top:1px solid #f0f0f1;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#646970}
			.aiwp-editor-note__meta a{text-decoration:none}
			#postdivrich,#postdiv{display:none}
			.acf-postbox .postbox-header h2{font-size:13px;letter-spacing:.02em;text-transform:uppercase;color:#646970}
		</style>';
	}

	private function current_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return absint( $_GET['post'] ?? ( $_REQUEST['post_ID'] ?? 0 ) );
	}

	private function is_aiwp( int $post_id ): bool {
		return $post_id > 0 && $this->plugin->pages()->is_aiwp_page( $post_id );
	}
}
