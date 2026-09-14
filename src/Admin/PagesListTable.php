<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Chrome\ChromeManager;
use AIWP\Designer\Pages\FrontPage;
use AIWP\Designer\Admin\CodeEditor;
use AIWP\Designer\Plugin;
use WP_List_Table;
use WP_Query;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The AI Pages screen, built on WordPress's own list table so it behaves like
 * Pages: status views with counts, search, sortable columns, pagination, row
 * actions and bulk actions.
 */
final class PagesListTable extends WP_List_Table {

	public const PER_PAGE_OPTION = 'aiwp_pages_per_page';

	private Plugin $plugin;
	private int $per_page = 20;

	/** @var array<string,int> */
	private array $counts = array(
		'all'     => 0,
		'publish' => 0,
		'draft'   => 0,
	);

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		parent::__construct(
			array(
				'singular' => 'aiwp_page',
				'plural'   => 'aiwp_pages',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'title'    => __( 'Page', 'aiwp-designer' ),
			'status'   => __( 'Status', 'aiwp-designer' ),
			'version'  => __( 'AIWP version', 'aiwp-designer' ),
			'design'   => __( 'Design', 'aiwp-designer' ),
			'chrome'   => __( 'Header & footer', 'aiwp-designer' ),
			'goal'     => __( 'Page goal', 'aiwp-designer' ),
			'modified' => __( 'Updated', 'aiwp-designer' ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'title'    => array( 'title', false ),
			'modified' => array( 'modified', true ),
			'status'   => array( 'status', false ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'trash' => __( 'Move to Trash', 'aiwp-designer' ) );
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=aiwp-pages' );

		$make = function ( string $key, string $label ) use ( $current, $base ): string {
			$url   = 'all' === $key ? $base : add_query_arg( 'post_status', $key, $base );
			$class = $current === $key ? ' class="current"' : '';

			return sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				$this->counts[ $key ]
			);
		};

		return array(
			'all'     => $make( 'all', __( 'All', 'aiwp-designer' ) ),
			'publish' => $make( 'publish', __( 'Published', 'aiwp-designer' ) ),
			'draft'   => $make( 'draft', __( 'Drafts', 'aiwp-designer' ) ),
		);
	}

	public function no_items(): void {
		$search = $this->requested_search();

		if ( '' !== $search ) {
			printf(
				/* translators: %s: search term */
				esc_html__( 'No AI pages match “%s”.', 'aiwp-designer' ),
				esc_html( $search )
			);
			return;
		}

		switch ( $this->current_status() ) {
			case 'draft':
				esc_html_e( 'No drafts. Pages your AI is still working on appear here before you publish them.', 'aiwp-designer' );
				break;

			case 'publish':
				esc_html_e( 'Nothing published yet. Ask your AI to publish a page when you are happy with it.', 'aiwp-designer' );
				break;

			default:
				esc_html_e( 'No AI pages yet. Connect your AI over MCP and ask it to build one.', 'aiwp-designer' );
		}
	}

	public function prepare_items(): void {
		$this->process_bulk_action();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->count_pages();

		$this->per_page = max( 1, (int) $this->get_items_per_page( self::PER_PAGE_OPTION, 20 ) );

		$paged   = max( 1, (int) $this->get_pagenum() );
		$status  = $this->current_status();
		$orderby = $this->requested_orderby();
		$order   = $this->requested_order();
		$search  = $this->requested_search();

		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'all' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
			'posts_per_page' => $this->per_page,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
			'meta_query'     => $this->meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $this->per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * AIWP pages only, and never the hidden header/footer holder.
	 *
	 * @return array<int,mixed>
	 */
	private function meta_query(): array {
		return array(
			'relation' => 'AND',
			array(
				'key'   => \AIWP\Designer\Pages\PageRepository::META_ENABLED,
				'value' => '1',
			),
			array(
				'key'     => ChromeManager::META_CHROME,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	private function count_pages(): void {
		$counts = array(
			'all'     => 0,
			'publish' => 0,
			'draft'   => 0,
		);

		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => $this->meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		foreach ( (array) $ids as $id ) {
			++$counts['all'];
			$status = get_post_status( (int) $id );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		$this->counts = $counts;
	}

	// ------------------------------------------------------------- columns

	/**
	 * @param \WP_Post $item
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="pages[]" value="%d" />', (int) $item->ID );
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_title( $item ): string {
		$page_id = (int) $item->ID;
		$edit    = (string) get_edit_post_link( $page_id );

		$title = sprintf(
			'<strong><a class="row-title" href="%s">%s</a>%s</strong>',
			esc_url( $edit ),
			esc_html( get_the_title( $page_id ) ?: __( '(no title)', 'aiwp-designer' ) ),
			FrontPage::is_front( $page_id )
				? ' — <span class="post-state">' . esc_html__( 'Front Page', 'aiwp-designer' ) . '</span>'
				: ''
		);

		$slug = sprintf( '<div class="aiwp-slug">/%s/</div>', esc_html( (string) $item->post_name ) );

		$actions = array(
			'edit'    => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit fields', 'aiwp-designer' ) ),
			'preview' => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $this->plugin->page_manager()->preview_url( $page_id ) ),
				esc_html__( 'Preview', 'aiwp-designer' )
			),
			'view'    => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( (string) get_permalink( $page_id ) ),
				esc_html__( 'Open page', 'aiwp-designer' )
			),
		);

		$actions['fields'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( FieldsScreen::url_for( $page_id ) ),
			esc_html__( 'Fields', 'aiwp-designer' )
		);

		if ( CodeEditor::can_use() ) {
			$actions['code'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( CodeEditor::url_for_page( $page_id ) ),
				esc_html__( 'Code', 'aiwp-designer' )
			);
		}

		if ( ! FrontPage::is_front( $page_id ) ) {
			$actions['trash'] = sprintf(
				'<a class="submitdelete" href="%s">%s</a>',
				esc_url( (string) get_delete_post_link( $page_id ) ),
				esc_html__( 'Trash', 'aiwp-designer' )
			);
		}

		return $title . $slug . $this->row_actions( $actions );
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_status( $item ): string {
		$status = get_post_status( (int) $item->ID );

		$labels = array(
			'publish' => __( 'Published', 'aiwp-designer' ),
			'draft'   => __( 'Draft', 'aiwp-designer' ),
			'pending' => __( 'Pending', 'aiwp-designer' ),
			'private' => __( 'Private', 'aiwp-designer' ),
			'future'  => __( 'Scheduled', 'aiwp-designer' ),
		);

		return sprintf(
			'<span class="aiwp-pill aiwp-pill--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $labels[ $status ] ?? $status )
		);
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_version( $item ): string {
		$page_id  = (int) $item->ID;
		$version  = $this->plugin->pages()->active_version( $page_id );
		$history  = count( $this->plugin->versions()->history( $page_id ) );

		return sprintf(
			'<strong>v%d</strong>%s',
			$version,
			$history > 1
				? sprintf(
					'<div class="aiwp-sub">%s</div>',
					esc_html( sprintf( /* translators: %d: number of versions */ _n( '%d version kept', '%d versions kept', $history, 'aiwp-designer' ), $history ) )
				)
				: ''
		);
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_design( $item ): string {
		$page_design = $this->plugin->pages()->design_version( (int) $item->ID );
		$current     = $this->plugin->design()->current_version();

		if ( $page_design === $current ) {
			return sprintf( '<span class="aiwp-sub">v%d</span>', $page_design );
		}

		return sprintf(
			'<span class="aiwp-sub">v%d</span><div class="aiwp-sub aiwp-sub--warn">%s</div>',
			$page_design,
			esc_html( sprintf( /* translators: %d: current design system version */ __( 'site is on v%d', 'aiwp-designer' ), $current ) )
		);
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_chrome( $item ): string {
		$chrome = (string) $this->plugin->pages()->manifest( (int) $item->ID )->get( 'chrome', 'theme' );

		$labels = array(
			'site'  => __( 'Shared', 'aiwp-designer' ),
			'theme' => __( 'Theme', 'aiwp-designer' ),
			'blank' => __( 'None', 'aiwp-designer' ),
		);

		return sprintf( '<span class="aiwp-sub">%s</span>', esc_html( $labels[ $chrome ] ?? $chrome ) );
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_goal( $item ): string {
		$goal = (string) $this->plugin->pages()->manifest( (int) $item->ID )->get( 'page_goal', '' );

		return '' === $goal
			? '<span class="aiwp-sub">—</span>'
			: sprintf( '<span class="aiwp-sub">%s</span>', esc_html( $goal ) );
	}

	/**
	 * @param \WP_Post $item
	 */
	protected function column_modified( $item ): string {
		$stamp = (int) get_post_timestamp( $item, 'modified' );

		return sprintf(
			'%s<div class="aiwp-sub">%s</div>',
			esc_html( wp_date( (string) get_option( 'date_format' ), $stamp ) ),
			esc_html( sprintf( /* translators: %s: human time difference */ __( '%s ago', 'aiwp-designer' ), human_time_diff( $stamp ) ) )
		);
	}

	// ------------------------------------------------------------ requests

	private function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( $_GET['post_status'] ?? 'all' );
		return in_array( $status, array( 'all', 'publish', 'draft' ), true ) ? $status : 'all';
	}

	private function requested_orderby(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = sanitize_key( $_GET['orderby'] ?? 'modified' );
		return in_array( $orderby, array( 'title', 'modified', 'status' ), true ) ? $orderby : 'modified';
	}

	private function requested_order(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'asc' === strtolower( sanitize_key( $_GET['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
	}

	private function requested_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( (string) ( $_REQUEST['s'] ?? '' ) ) );
	}

	private function process_bulk_action(): void {
		if ( 'trash' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! current_user_can( 'delete_pages' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids     = array_map( 'absint', (array) ( $_REQUEST['pages'] ?? array() ) );
		$trashed = 0;

		foreach ( $ids as $id ) {
			if ( $id > 0 && $this->plugin->pages()->is_aiwp_page( $id ) && ! $this->plugin->chrome()->is_holder( $id ) && ! FrontPage::is_front( $id ) ) {
				if ( wp_trash_post( $id ) ) {
					++$trashed;
				}
			}
		}

		if ( $trashed > 0 ) {
			$this->plugin->pages()->flush_page_list_cache();
			add_settings_error(
				'aiwp_pages',
				'aiwp_trashed',
				sprintf( /* translators: %d: number of pages */ _n( '%d page moved to the trash.', '%d pages moved to the trash.', $trashed, 'aiwp-designer' ), $trashed ),
				'success'
			);
		}
	}
}
