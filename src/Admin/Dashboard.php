<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\ACF\ACFManager;
use AIWP\Designer\Plugin;
use AIWP\Designer\Pages\FrontPage;
use AIWP\Designer\Security\AuditLogger;

/**
 * Dashboard, AI Pages table and the Design System view.
 */
final class Dashboard {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function render(): void {
		$pages     = $this->plugin->pages()->content_page_ids();
		$tokens    = $this->plugin->auth_provider()->tokens();
		$design    = $this->plugin->design()->current_version();
		$acf_ready = ACFManager::is_available();

		echo '<div class="wrap"><h1>' . esc_html__( 'AIWP Designer', 'aiwp-designer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your own AI designs this website through MCP. This plugin validates, stores, versions and renders what it sends.', 'aiwp-designer' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		$this->row( __( 'ACF', 'aiwp-designer' ), $acf_ready ? sprintf( 'Active (%s)', ACFManager::version() ) : 'Not active', $acf_ready );
		$this->row( __( 'MCP connection', 'aiwp-designer' ), $tokens ? sprintf( '%d active token(s)', count( $tokens ) ) : 'No token yet', (bool) $tokens );
		$this->row( __( 'Design system', 'aiwp-designer' ), $design > 0 ? 'Version ' . $design : 'Not created yet', $design > 0 );
		$this->row( __( 'AI pages', 'aiwp-designer' ), (string) count( $pages ), true );

		$front = FrontPage::current();
		$this->row(
			__( 'Front page', 'aiwp-designer' ),
			$front ? (string) get_the_title( $front ) : __( 'Showing latest posts', 'aiwp-designer' ),
			$front > 0 && '' === FrontPage::problem()
		);

		$problem = FrontPage::problem();
		if ( '' !== $problem ) {
			printf(
				'<tr><th></th><td style="color:#b32d2e">%s</td></tr>',
				esc_html( $problem )
			);
		}
		$this->row( __( 'Header & footer', 'aiwp-designer' ), $this->plugin->chrome()->exists() ? 'Version ' . $this->plugin->chrome()->version() : 'Not built yet', $this->plugin->chrome()->exists() );
		$this->row( __( 'Form entries', 'aiwp-designer' ), (string) $this->plugin->entries()->count(), true );
		// Checked here rather than discovered halfway through a build. A site
		// whose uploads folder belongs to another user looks perfectly healthy
		// until the first thing the AI tries to save.
		$files    = $this->plugin->files();
		$writable = $files->can_write();
		$this->row(
			__( 'Storage', 'aiwp-designer' ),
			$writable ? __( 'Writable', 'aiwp-designer' ) : $files->last_error(),
			$writable
		);

		$this->row( __( 'Plugin version', 'aiwp-designer' ), AIWP_VERSION, true );
		$this->row( __( 'Template language', 'aiwp-designer' ), 'v' . AIWP_TEMPLATE_LANGUAGE_VERSION, true );
		$this->row( __( 'MCP endpoint', 'aiwp-designer' ), rest_url( AIWP_REST_NAMESPACE . '/mcp' ), true );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Recent activity', 'aiwp-designer' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Event</th><th>When</th><th>Details</th></tr></thead><tbody>';
		$entries = AuditLogger::recent( 15 );
		if ( ! $entries ) {
			echo '<tr><td colspan="3">' . esc_html__( 'Nothing yet.', 'aiwp-designer' ) . '</td></tr>';
		}
		foreach ( $entries as $entry ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $entry['event'] ),
				esc_html( (string) $entry['time'] ),
				esc_html( (string) wp_json_encode( $entry['context'] ) )
			);
		}
		echo '</tbody></table></div>';
	}

	public function render_pages(): void {
		$table = new PagesListTable( $this->plugin );
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'AI Pages', 'aiwp-designer' ) . '</h1>';

		// There is no "Add New" here on purpose: pages are created by the AI.
		printf(
			'<p class="description aiwp-screen-note">%s <a href="%s">%s</a></p>',
			esc_html__( 'Pages here were built by your AI through MCP. To add one, ask it for a page.', 'aiwp-designer' ),
			esc_url( admin_url( 'admin.php?page=aiwp-mcp' ) ),
			esc_html__( 'MCP connection', 'aiwp-designer' )
		);

		echo '<hr class="wp-header-end">';

		settings_errors( 'aiwp_pages' );

		$table->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'aiwp-pages' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_status'] ) ) {
			printf( '<input type="hidden" name="post_status" value="%s">', esc_attr( sanitize_key( wp_unslash( (string) $_GET['post_status'] ) ) ) );
		}

		$table->search_box( __( 'Search pages', 'aiwp-designer' ), 'aiwp-page' );
		$table->display();

		echo '</form></div>';
	}

	public function render_chrome(): void {
		$chrome  = $this->plugin->chrome();
		$holder  = $chrome->holder_page_id( false );
		$using   = array();

		foreach ( $this->plugin->pages()->content_page_ids() as $page_id ) {
			if ( 'site' === $this->plugin->pages()->manifest( $page_id )->get( 'chrome', 'theme' ) ) {
				$using[] = $page_id;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Header & Footer', 'aiwp-designer' ) . '</h1>';

		if ( ! $chrome->exists() ) {
			echo '<p>' . esc_html__( 'No shared header and footer yet. Ask your AI to build one, then set pages to use it.', 'aiwp-designer' ) . '</p></div>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html( sprintf( /* translators: 1: version, 2: page count */ __( 'Version %1$d, used by %2$d page(s).', 'aiwp-designer' ), $chrome->version(), count( $using ) ) )
		);


		echo '<h2>' . esc_html__( 'Navigation', 'aiwp-designer' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th style="width:150px">' . esc_html__( 'Location', 'aiwp-designer' ) . '</th>';
		echo '<th>' . esc_html__( 'Showing', 'aiwp-designer' ) . '</th><th style="width:120px"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->plugin->menus()->describe() as $name => $info ) {
			printf(
				'<tr><th scope="row"><code>%s</code></th><td>%s</td><td>%s</td></tr>',
				esc_html( $name ),
				$info['assigned']
					? esc_html( sprintf( /* translators: 1: menu name, 2: item count */ __( '%1$s — %2$d items', 'aiwp-designer' ), $info['menu'], count( $info['items'] ) ) )
					: '<span style="color:#996800">' . esc_html__( 'No menu assigned. The published pages are being listed instead.', 'aiwp-designer' ) . '</span>',
				$info['assigned']
					? sprintf( '<a href="%s">%s</a>', esc_url( (string) $info['edit_url'] ), esc_html__( 'Edit menu', 'aiwp-designer' ) )
					: sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'nav-menus.php' ) ), esc_html__( 'Create one', 'aiwp-designer' ) )
			);
		}

		echo '</tbody></table>';
		printf(
			'<p class="description" style="max-width:820px">%s</p>',
			esc_html__( 'Assign a menu to a location under Appearance → Menus. Tick the location box before saving, or WordPress clears the assignment.', 'aiwp-designer' )
		);

		$this->render_chrome_fields( $holder );

		// The markup and CSS are written by the AI. Kept out of the way, but
		// visible for anyone who wants to see what is being rendered.
		if ( CodeEditor::can_use() ) {
			printf(
				'<h2>%s</h2><p><a class="button" href="%s">%s</a></p>',
				esc_html__( 'Markup and CSS', 'aiwp-designer' ),
				esc_url( admin_url( 'admin.php?page=' . CodeEditor::SLUG . '&aiwp_target=chrome' ) ),
				esc_html__( 'Open in the code editor', 'aiwp-designer' )
			);
		}

		echo '<details class="aiwp-dev"><summary>' . esc_html__( 'Template markup and CSS (read-only)', 'aiwp-designer' ) . '</summary>';

		foreach (
			array(
				__( 'Header', 'aiwp-designer' ) => $chrome->header_template(),
				__( 'Footer', 'aiwp-designer' ) => $chrome->footer_template(),
				__( 'CSS', 'aiwp-designer' )    => $chrome->css(),
			) as $label => $markup
		) {
			printf( '<h3>%s</h3>', esc_html( $label ) );
			echo '<pre class="aiwp-code">';
			echo esc_html( '' !== trim( $markup ) ? $markup : '(empty)' );
			echo '</pre>';
		}

		echo '</details>';

		echo '</div>';
	}

	/**
	 * The header and footer content, editable here rather than on a hidden page.
	 */
	private function render_chrome_fields( int $holder ): void {
		if ( 0 === $holder ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Content', 'aiwp-designer' ) . '</h2>';

		if ( ! function_exists( 'acf_form' ) ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( (string) get_edit_post_link( $holder ) ),
				esc_html__( 'Edit header & footer content', 'aiwp-designer' )
			);
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Header and footer updated.', 'aiwp-designer' )
			);
		}

		printf(
			'<p class="description" style="max-width:820px">%s</p>',
			esc_html__( 'These are the words and links used in the header and footer of every AIWP page. The layout around them is designed by your AI.', 'aiwp-designer' )
		);

		acf_form(
			array(
				'id'                 => 'aiwp-chrome-form',
				'post_id'            => $holder,
				'post_title'         => false,
				'post_content'       => false,
				'form'               => true,
				'return'             => add_query_arg( 'updated', '1', admin_url( 'admin.php?page=aiwp-chrome' ) ),
				'submit_value'       => __( 'Save header & footer', 'aiwp-designer' ),
				'updated_message'    => false,
				'label_placement'    => 'top',
				'instruction_placement' => 'label',
				'uploader'           => 'wp',
				'honeypot'           => false,
				'html_submit_button' => '<input type="submit" class="button button-primary button-large" value="%s" />',
			)
		);
	}

	public function render_design(): void {
		$system = $this->plugin->design()->current();
		$css    = $this->plugin->design()->global_css();

		echo '<div class="wrap"><h1>' . esc_html__( 'Design System', 'aiwp-designer' ) . '</h1>';
		printf( '<p>Version <strong>%d</strong>. Ask your AI to run the <code>create_design_system</code> workflow to change it.</p>', $this->plugin->design()->current_version() );

		echo '<h2>Tokens</h2><pre style="background:#fff;padding:16px;border:1px solid #dcdcde;max-width:900px;overflow:auto">';
		echo esc_html( (string) wp_json_encode( $system->tokens(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre>';

		echo '<h2>Compiled global CSS</h2><pre style="background:#fff;padding:16px;border:1px solid #dcdcde;max-width:900px;max-height:420px;overflow:auto">';
		echo esc_html( '' !== $css ? $css : $system->to_css() );
		echo '</pre></div>';
	}

	private function row( string $label, string $value, bool $ok ): void {
		printf(
			'<tr><th style="width:200px">%s</th><td>%s <span style="color:%s">%s</span></td></tr>',
			esc_html( $label ),
			esc_html( $value ),
			$ok ? '#1a7f37' : '#b32d2e',
			$ok ? '&#10003;' : '&#10007;'
		);
	}
}
