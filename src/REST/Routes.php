<?php
declare( strict_types = 1 );

namespace AIWP\Designer\REST;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API for the editor and for headless consumers.
 *
 * Browser mutations still need a WordPress REST nonce; a nonce is CSRF
 * protection, never authentication. Capabilities decide what is allowed.
 */
final class Routes {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns = AIWP_REST_NAMESPACE;

		( new CodeController( $this->plugin ) )->register_routes();
		( new SectionsController( $this->plugin ) )->register_routes();

		register_rest_route(
			$ns,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_pages' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_page' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $this->id_arg(),
			)
		);

		// Normalised content for Next.js, Astro, mobile apps and so on.
		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)/data',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_page_data' ),
				'permission_callback' => array( $this, 'can_read_page' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)/fields',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'patch_fields' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)/versions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_versions' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/pages/(?P<id>\d+)/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			$ns,
			'/design-system',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'design_system' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function id_arg(): array {
		return array(
			'id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
			),
		);
	}

	public function can_edit(): bool {
		return CapabilityManager::current_user_can( CapabilityManager::EDIT_PAGES );
	}

	/**
	 * Published AIWP pages are readable by anyone; drafts are not.
	 */
	public function can_read_page( WP_REST_Request $request ): bool {
		$page_id = absint( $request['id'] );

		if ( 'publish' === get_post_status( $page_id ) ) {
			return true;
		}

		return CapabilityManager::current_user_can( CapabilityManager::EDIT_PAGES );
	}

	public function list_pages(): WP_REST_Response {
		return new WP_REST_Response( $this->plugin->mcp()->tools()->pages_list(), 200 );
	}

	public function get_page( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->plugin->mcp()->tools()->page_get( array( 'page_id' => absint( $request['id'] ) ) );
		return new WP_REST_Response( $result, isset( $result['error'] ) ? 404 : 200 );
	}

	/**
	 * The headless shape: fields nested by section, nothing internal.
	 */
	public function get_page_data( WP_REST_Request $request ): WP_REST_Response {
		$page_id = absint( $request['id'] );
		$pages   = $this->plugin->pages();

		if ( ! $pages->is_aiwp_page( $page_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Not an AIWP page.' ), 404 );
		}

		$schema = $pages->schema( $page_id );

		return new WP_REST_Response(
			array(
				'id'     => $page_id,
				'slug'   => get_post_field( 'post_name', $page_id ),
				'title'  => get_the_title( $page_id ),
				'status' => get_post_status( $page_id ),
				'fields' => $schema ? ( new FieldValueManager() )->read( $page_id, $schema ) : array(),
			),
			200
		);
	}

	public function patch_fields( WP_REST_Request $request ): WP_REST_Response {
		$page_id = absint( $request['id'] );
		$updates = (array) $request->get_param( 'updates' );

		$result = $this->plugin->page_manager()->update_content( $page_id, $updates );

		return new WP_REST_Response( $result, empty( $result['success'] ) ? 400 : 200 );
	}

	public function get_versions( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->plugin->mcp()->tools()->page_versions( array( 'page_id' => absint( $request['id'] ) ) );
		return new WP_REST_Response( $result, isset( $result['error'] ) ? 404 : 200 );
	}

	public function rollback( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->plugin->page_manager()->rollback(
			absint( $request['id'] ),
			absint( $request->get_param( 'version' ) )
		);
		return new WP_REST_Response( $result, empty( $result['success'] ) ? 400 : 200 );
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->plugin->mcp()->tools()->performance_static_audit( array( 'page_id' => absint( $request['id'] ) ) );
		return new WP_REST_Response( $result, 200 );
	}

	public function design_system(): WP_REST_Response {
		return new WP_REST_Response( $this->plugin->mcp()->tools()->build_design_system(), 200 );
	}
}
