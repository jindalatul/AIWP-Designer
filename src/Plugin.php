<?php
declare( strict_types = 1 );

namespace AIWP\Designer;

use AIWP\Designer\ACF\ACFManager;
use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\ACF\Fields\AIRepeaterField;
use AIWP\Designer\ACF\SchemaRegistrar;
use AIWP\Designer\Admin\AdminMenu;
use AIWP\Designer\Chrome\ChromeManager;
use AIWP\Designer\Chrome\MenuRenderer;
use AIWP\Designer\Design\DesignSystem;
use AIWP\Designer\Design\DesignSystemRepository;
use AIWP\Designer\Forms\EntryRepository;
use AIWP\Designer\Forms\FormHandler;
use AIWP\Designer\Forms\FormRenderer;
use AIWP\Designer\MCP\Auth\McpAuthProviderInterface;
use AIWP\Designer\MCP\Auth\TokenAuthProvider;
use AIWP\Designer\MCP\MCPServer;
use AIWP\Designer\Pages\PageManager;
use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Prompts\PromptLoader;
use AIWP\Designer\Prompts\WorkflowManager;
use AIWP\Designer\REST\Routes;
use AIWP\Designer\Rendering\AssetManager;
use AIWP\Designer\Rendering\PageRenderer;
use AIWP\Designer\Rendering\TemplateLoader;
use AIWP\Designer\Security\CapabilityManager;
use AIWP\Designer\Storage\FileStore;
use AIWP\Designer\Versioning\VersionManager;

/**
 * Wires the plugin together.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private FileStore $files;
	private PageRepository $page_repository;
	private VersionManager $version_manager;
	private FieldValueManager $field_values;
	private DesignSystemRepository $design_repository;
	private PageManager $page_manager;
	private PageRenderer $page_renderer;
	private WorkflowManager $workflow_manager;
	private PromptLoader $prompt_loader;
	private TokenAuthProvider $auth_provider;
	private MCPServer $mcp_server;
	private SchemaRegistrar $schema_registrar;
	private \AIWP\Designer\Articles\ArticleManager $article_manager;
	private ChromeManager $chrome_manager;
	private MenuRenderer $menu_renderer;
	private EntryRepository $entry_repository;
	private FormHandler $form_handler;
	private bool $booted = false;

	private function __construct() {
		$this->files             = new FileStore();
		$this->page_repository   = new PageRepository( $this->files );
		$this->version_manager   = new VersionManager( $this->files, $this->page_repository );
		$this->field_values      = new FieldValueManager();
		$this->design_repository = new DesignSystemRepository( $this->files );
		$this->page_manager      = new PageManager(
			$this->files,
			$this->page_repository,
			$this->version_manager,
			$this->field_values,
			$this->design_repository
		);
		$this->menu_renderer    = new MenuRenderer( $this->page_repository );
		$this->chrome_manager   = new ChromeManager(
			$this->files,
			$this->page_repository,
			$this->version_manager,
			$this->field_values,
			$this->menu_renderer
		);
		$this->page_renderer    = new PageRenderer(
			$this->page_repository,
			$this->field_values,
			new FormRenderer(),
			$this->menu_renderer
		);
		$this->entry_repository = new EntryRepository();
		$this->form_handler     = new FormHandler( $this->page_repository, $this->entry_repository );
		$this->workflow_manager = new WorkflowManager();
		$this->prompt_loader    = new PromptLoader();
		$this->auth_provider    = new TokenAuthProvider();
		$this->mcp_server       = new MCPServer( $this, $this->auth_provider );
		$this->schema_registrar = new SchemaRegistrar( $this->page_repository );
		$this->article_manager  = new \AIWP\Designer\Articles\ArticleManager(
			$this->files,
			$this->field_values,
			$this->schema_registrar
		);
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'aiwp-designer', false, dirname( plugin_basename( AIWP_PLUGIN_FILE ) ) . '/lang' );

		// The custom field type must exist before any schema is registered.
		add_action( 'acf/include_field_types', array( $this, 'register_field_types' ) );

		$this->schema_registrar->register();
		add_action( 'aiwp/refresh_acf', array( $this->schema_registrar, 'refresh' ) );

		( new TemplateLoader( $this->page_repository ) )->register();
		( new AssetManager( $this->page_repository, $this->design_repository, $this->chrome_manager, $this->article_manager ) )->register();
		$this->form_handler->register();
		$this->menu_renderer->register();
		$this->article_manager->register();
		( new \AIWP\Designer\SEO\MetaTags() )->register();
		( new \AIWP\Designer\SEO\SchemaGraph( $this->page_repository, $this->page_renderer ) )->register();
		( new Routes( $this ) )->register();
		$this->mcp_server->register();

		if ( is_admin() ) {
			( new AdminMenu( $this ) )->register();
		}

		$this->maybe_upgrade();

		// Keep the page list cache honest.
		add_action( 'save_post_page', array( $this->page_repository, 'flush_page_list_cache' ) );
		add_action( 'deleted_post', array( $this->page_repository, 'flush_page_list_cache' ) );
		// Before, not after: the uuid that names the files is in post meta.
		add_action( 'before_delete_post', array( $this->page_manager, 'forget_deleted_page' ) );
	}

	public function register_field_types(): void {
		if ( ! function_exists( 'acf_register_field_type' ) ) {
			return;
		}
		acf_register_field_type( AIRepeaterField::class );
	}

	// ------------------------------------------------------------ accessors

	public function files(): FileStore {
		return $this->files;
	}

	public function pages(): PageRepository {
		return $this->page_repository;
	}

	public function versions(): VersionManager {
		return $this->version_manager;
	}

	public function field_values(): FieldValueManager {
		return $this->field_values;
	}

	public function design(): DesignSystemRepository {
		return $this->design_repository;
	}

	public function page_manager(): PageManager {
		return $this->page_manager;
	}

	public function renderer(): PageRenderer {
		return $this->page_renderer;
	}

	public function workflows(): WorkflowManager {
		return $this->workflow_manager;
	}

	public function prompt_loader(): PromptLoader {
		return $this->prompt_loader;
	}

	public function auth_provider(): TokenAuthProvider {
		return $this->auth_provider;
	}

	public function mcp(): MCPServer {
		return $this->mcp_server;
	}

	public function schemas(): SchemaRegistrar {
		return $this->schema_registrar;
	}

	public function articles(): \AIWP\Designer\Articles\ArticleManager {
		return $this->article_manager;
	}

	public function chrome(): ChromeManager {
		return $this->chrome_manager;
	}

	public function menus(): MenuRenderer {
		return $this->menu_renderer;
	}

	public function entries(): EntryRepository {
		return $this->entry_repository;
	}

	public function forms(): FormHandler {
		return $this->form_handler;
	}

	/**
	 * Create tables added after the plugin was first activated.
	 */
	private function maybe_upgrade(): void {
		$installed = (string) get_option( 'aiwp_db_version', '0' );
		if ( AIWP_VERSION === $installed ) {
			return;
		}

		VersionManager::install_table();
		EntryRepository::install_table();

		update_option( 'aiwp_db_version', AIWP_VERSION, true );
	}

	// ----------------------------------------------------------- lifecycle

	public static function activate(): void {
		$plugin = self::instance();

		CapabilityManager::add_capabilities();
		VersionManager::install_table();
		EntryRepository::install_table();
		update_option( 'aiwp_db_version', AIWP_VERSION, true );

		if ( ! $plugin->files()->ensure_structure() ) {
			// A plugin that cannot write its own storage is worth saying so loudly.
			set_transient( 'aiwp_storage_error', 1, DAY_IN_SECONDS );
		}

		// Ship a usable default design system so the first page has tokens.
		if ( 0 === $plugin->design()->current_version() ) {
			$plugin->design()->store( DesignSystem::defaults(), '' );
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
