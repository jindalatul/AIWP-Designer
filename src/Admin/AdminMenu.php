<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;

/**
 * The AIWP Designer admin menu.
 */
final class AdminMenu {

	private Plugin $plugin;
	private Dashboard $dashboard;
	private Settings $settings;
	private Onboarding $onboarding;
	private Entries $entries;
	private CodeEditor $code_editor;
	private FieldsScreen $fields_screen;
	private TransferScreen $transfer;

	public function __construct( Plugin $plugin ) {
		$this->plugin     = $plugin;
		$this->dashboard  = new Dashboard( $plugin );
		$this->settings   = new Settings( $plugin );
		$this->onboarding = new Onboarding( $plugin );
		$this->entries    = new Entries( $plugin );
		$this->code_editor = new CodeEditor( $plugin );
		$this->fields_screen = new FieldsScreen( $plugin );
		$this->transfer      = new TransferScreen( $plugin );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_action( 'admin_post_aiwp_save_settings', array( $this->settings, 'handle_save' ) );
		add_action( 'admin_post_aiwp_create_token', array( $this->settings, 'handle_create_token' ) );
		add_action( 'admin_post_aiwp_revoke_token', array( $this->settings, 'handle_revoke_token' ) );
		add_action( 'admin_post_aiwp_save_brand', array( $this->onboarding, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );

		$this->transfer->hooks();

		( new EditorScreen( $this->plugin ) )->register();
	}

	public function add_menu(): void {
		$cap = CapabilityManager::EDIT_PAGES;

		add_menu_page(
			__( 'AIWP Designer', 'aiwp-designer' ),
			__( 'AIWP Designer', 'aiwp-designer' ),
			$cap,
			'aiwp-designer',
			array( $this->dashboard, 'render' ),
			'dashicons-art',
			58
		);

		add_submenu_page( 'aiwp-designer', __( 'Dashboard', 'aiwp-designer' ), __( 'Dashboard', 'aiwp-designer' ), $cap, 'aiwp-designer', array( $this->dashboard, 'render' ) );
		$pages_hook = add_submenu_page( 'aiwp-designer', __( 'AI Pages', 'aiwp-designer' ), __( 'AI Pages', 'aiwp-designer' ), $cap, 'aiwp-pages', array( $this->dashboard, 'render_pages' ) );

		// Screen Options, the same way the native Pages screen offers them.
		add_action(
			'load-' . $pages_hook,
			static function (): void {
				add_screen_option(
					'per_page',
					array(
						'label'   => __( 'Pages per page', 'aiwp-designer' ),
						'default' => 20,
						'option'  => PagesListTable::PER_PAGE_OPTION,
					)
				);
			}
		);
		add_submenu_page( 'aiwp-designer', __( 'Design System', 'aiwp-designer' ), __( 'Design System', 'aiwp-designer' ), $cap, 'aiwp-design', array( $this->dashboard, 'render_design' ) );
		$chrome_hook = add_submenu_page( 'aiwp-designer', __( 'Header & Footer', 'aiwp-designer' ), __( 'Header & Footer', 'aiwp-designer' ), $cap, 'aiwp-chrome', array( $this->dashboard, 'render_chrome' ) );

		// acf_form() has to be prepared before anything is printed.
		add_action(
			'load-' . $chrome_hook,
			static function (): void {
				if ( function_exists( 'acf_form_head' ) ) {
					acf_form_head();
				}
			}
		);
		add_submenu_page( 'aiwp-designer', __( 'Form Entries', 'aiwp-designer' ), __( 'Form Entries', 'aiwp-designer' ), $cap, 'aiwp-entries', array( $this->entries, 'render' ) );

		$fields_hook = add_submenu_page(
			'aiwp-designer',
			__( 'Fields', 'aiwp-designer' ),
			__( 'Fields', 'aiwp-designer' ),
			$cap,
			FieldsScreen::SLUG,
			array( $this->fields_screen, 'render' )
		);

		add_action(
			'load-' . $fields_hook,
			function (): void {
				add_action( 'admin_enqueue_scripts', array( $this->fields_screen, 'enqueue' ) );
			}
		);

		// A developer's view. Most changes should be asked for in words instead.
		$code_hook = add_submenu_page(
			'aiwp-designer',
			__( 'Code', 'aiwp-designer' ),
			__( 'Code', 'aiwp-designer' ),
			CapabilityManager::MANAGE_DESIGN,
			CodeEditor::SLUG,
			array( $this->code_editor, 'render' )
		);

		add_action(
			'load-' . $code_hook,
			function (): void {
				add_action( 'admin_enqueue_scripts', array( $this->code_editor, 'enqueue' ) );
			}
		);
		add_submenu_page( 'aiwp-designer', __( 'Brand & Business', 'aiwp-designer' ), __( 'Brand & Business', 'aiwp-designer' ), $cap, 'aiwp-brand', array( $this->onboarding, 'render' ) );
		add_submenu_page( 'aiwp-designer', __( 'MCP Connection', 'aiwp-designer' ), __( 'MCP Connection', 'aiwp-designer' ), CapabilityManager::MANAGE_CONNECTIONS, 'aiwp-mcp', array( $this->settings, 'render_connection' ) );
		add_submenu_page(
			'aiwp-designer',
			__( 'Move this site', 'aiwp-designer' ),
			__( 'Move this site', 'aiwp-designer' ),
			CapabilityManager::MANAGE_DESIGN,
			TransferScreen::SLUG,
			array( $this->transfer, 'render' )
		);
		add_submenu_page( 'aiwp-designer', __( 'Settings', 'aiwp-designer' ), __( 'Settings', 'aiwp-designer' ), CapabilityManager::MANAGE_CONNECTIONS, 'aiwp-settings', array( $this->settings, 'render' ) );
	}

	/**
	 * @param mixed  $status
	 * @param string $option
	 * @param mixed  $value
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		return PagesListTable::PER_PAGE_OPTION === $option ? absint( $value ) : $status;
	}

	/**
	 * A few classes the list table uses. Small enough to stay inline.
	 */
	public function admin_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'aiwp-' ) ) {
			return;
		}

		$css = '.aiwp-slug{color:#646970;font-size:12px;margin-top:2px}'
			. '.aiwp-sub{color:#646970;font-size:12px}'
			. '.aiwp-sub--warn{color:#996800}'
			. '.aiwp-pill{display:inline-block;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;line-height:1.7;border:1px solid}'
			. '.aiwp-pill--publish{color:#0a5c36;border-color:#b4e3c8;background:#edfaf1}'
			. '.aiwp-pill--draft{color:#646970;border-color:#dcdcde;background:#f6f7f7}'
			. '.aiwp-pill--pending,.aiwp-pill--future{color:#996800;border-color:#f0e3b4;background:#fcf9e8}'
			. '.aiwp-pill--private{color:#3858e9;border-color:#c5cff5;background:#f0f3ff}'
			. '.column-version,.column-design,.column-chrome{width:110px}'
			. '.column-status{width:100px}.column-modified{width:130px}'
			. '.aiwp-screen-note{margin:6px 0 0;font-size:13px}'
			. '.aiwp-screen-note a{text-decoration:none}'
			. '#aiwp-chrome-form{max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:4px 16px 16px;margin:12px 0 24px}'
			. '#aiwp-chrome-form .acf-field{padding:14px 0;border-top:1px solid #f0f0f1}'
			. '#aiwp-chrome-form .acf-field:first-child{border-top:0}'
			. '#aiwp-chrome-form .acf-label label{font-weight:600}'
			. '#aiwp-chrome-form .acf-form-submit{padding-top:14px;border-top:1px solid #dcdcde;margin-top:8px}'
			. '.aiwp-dev{max-width:900px;margin-top:24px}'
			. '.aiwp-dev summary{cursor:pointer;color:#646970;font-size:13px;padding:8px 0}'
			. '.aiwp-code{background:#fff;padding:16px;border:1px solid #dcdcde;max-height:320px;overflow:auto;font-size:12px}';

		wp_register_style( 'aiwp-admin', false, array(), AIWP_VERSION );
		wp_enqueue_style( 'aiwp-admin' );
		wp_add_inline_style( 'aiwp-admin', $css );
	}

	public function dependency_notice(): void {
		if ( \AIWP\Designer\ACF\ACFManager::is_available() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>AIWP Designer:</strong> ';
		echo esc_html__( 'Advanced Custom Fields (free) is not active. The plugin stays installed and existing pages keep rendering, but page generation is switched off until ACF is active.', 'aiwp-designer' );
		echo '</p></div>';
	}
}
