<?php
declare( strict_types = 1 );

namespace AIWP\Designer\REST;

use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Pages\PageValidator;
use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;
use AIWP\Designer\Template\TemplateValidator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reading, checking and saving template and CSS source from the admin.
 *
 * Hand-edited code goes through exactly the same validators as anything the AI
 * sends, and a save creates a version like any other change. There is nothing
 * here that lets a person store markup the AI could not have stored.
 */
final class CodeController {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_routes(): void {
		$ns = AIWP_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/code',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_edit_code' ),
			)
		);

		register_rest_route(
			$ns,
			'/code/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'can_edit_code' ),
			)
		);

		register_rest_route(
			$ns,
			'/code/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'can_edit_code' ),
			)
		);
	}

	public function can_edit_code(): bool {
		return CapabilityManager::current_user_can( CapabilityManager::MANAGE_DESIGN );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ) {
		$target = $this->target( $request );

		if ( 'chrome' === $target ) {
			$chrome = $this->plugin->chrome();

			return new WP_REST_Response(
				array(
					'target'  => 'chrome',
					'files'   => array(
						'header' => array(
							'label'    => __( 'Header', 'aiwp-designer' ),
							'mode'     => 'aiwp',
							'contents' => $chrome->header_template(),
						),
						'footer' => array(
							'label'    => __( 'Footer', 'aiwp-designer' ),
							'mode'     => 'aiwp',
							'contents' => $chrome->footer_template(),
						),
						'css'    => array(
							'label'    => __( 'CSS', 'aiwp-designer' ),
							'mode'     => 'css',
							'contents' => $chrome->authored_css(),
						),
					),
					'version' => $chrome->version(),
				),
				200
			);
		}

		$page_id = absint( $request->get_param( 'page_id' ) );

		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Not an AIWP page.' ), 404 );
		}

		return new WP_REST_Response(
			array(
				'target'  => 'page',
				'page_id' => $page_id,
				'title'   => get_the_title( $page_id ),
				'files'   => array(
					'template' => array(
						'label'    => __( 'Template', 'aiwp-designer' ),
						'mode'     => 'aiwp',
						'contents' => $this->plugin->pages()->template( $page_id ),
					),
					'css'      => array(
						'label'    => __( 'CSS', 'aiwp-designer' ),
						'mode'     => 'css',
						'contents' => $this->plugin->pages()->authored_css( $page_id ),
					),
				),
				'version' => $this->plugin->pages()->active_version( $page_id ),
			),
			200
		);
	}

	/**
	 * Check without storing anything.
	 *
	 * @return WP_REST_Response
	 */
	public function check( WP_REST_Request $request ) {
		return new WP_REST_Response( $this->validate( $request ), 200 );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function save( WP_REST_Request $request ) {
		$result = $this->validate( $request );

		if ( ! $result['valid'] ) {
			$result['saved'] = false;
			return new WP_REST_Response( $result, 200 );
		}

		$target = $this->target( $request );

		// A save that changes nothing should not make a version. Otherwise
		// opening a file and pressing save walks the version number up, and the
		// history stops being a list of changes.
		$unchanged = $this->unchanged( $request, $target );
		if ( $unchanged ) {
			$result['saved']     = true;
			$result['unchanged'] = true;
			$result['version']   = 'chrome' === $target
				? $this->plugin->chrome()->version()
				: $this->plugin->pages()->active_version( absint( $request->get_param( 'page_id' ) ) );
			$result['message']   = __( 'Nothing changed, so nothing was saved.', 'aiwp-designer' );

			return new WP_REST_Response( $result, 200 );
		}

		if ( 'chrome' === $target ) {
			$chrome = $this->plugin->chrome();
			$schema = $chrome->schema();

			$stored = $chrome->store(
				array(
					'sections' => $schema ? $schema->to_array() : array(),
					'content'  => $chrome->content(),
					'header'   => (string) $request->get_param( 'header' ),
					'footer'   => (string) $request->get_param( 'footer' ),
					'css'      => (string) $request->get_param( 'css' ),
					'note'     => 'Edited in the code editor',
				)
			);

			$result['saved']   = ! empty( $stored['success'] );
			$result['version'] = $stored['version'] ?? 0;
			$result['errors']  = array_merge( $result['errors'], $stored['errors'] ?? array() );

			return new WP_REST_Response( $result, 200 );
		}

		$page_id = absint( $request->get_param( 'page_id' ) );

		$stored = $this->plugin->page_manager()->update(
			array(
				'page_id'  => $page_id,
				'template' => (string) $request->get_param( 'template' ),
				'css'      => (string) $request->get_param( 'css' ),
			),
			'code_edit'
		);

		$result['saved']   = ! empty( $stored['success'] );
		$result['version'] = $stored['version'] ?? 0;

		if ( empty( $stored['success'] ) ) {
			$result['errors'] = array_merge(
				$result['errors'],
				(array) ( $stored['validation']['errors'] ?? array() ),
				array( (string) ( $stored['error']['message'] ?? '' ) )
			);
			$result['errors'] = array_values( array_filter( $result['errors'] ) );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Is every file the same as the one already stored?
	 *
	 * Compared on the authored text, because that is what the editor shows and
	 * what the person actually edited. Whitespace at the very end is ignored:
	 * some editors add a trailing newline on open and that is not a change.
	 */
	private function unchanged( WP_REST_Request $request, string $target ): bool {
		$same = static fn( mixed $sent, string $stored ): bool =>
			rtrim( (string) $sent, "\r\n" ) === rtrim( $stored, "\r\n" );

		if ( 'chrome' === $target ) {
			$chrome = $this->plugin->chrome();

			return $same( $request->get_param( 'header' ), $chrome->header_template() )
				&& $same( $request->get_param( 'footer' ), $chrome->footer_template() )
				&& $same( $request->get_param( 'css' ), $chrome->authored_css() );
		}

		$page_id = absint( $request->get_param( 'page_id' ) );
		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return false;
		}

		return $same( $request->get_param( 'template' ), $this->plugin->pages()->template( $page_id ) )
			&& $same( $request->get_param( 'css' ), $this->plugin->pages()->authored_css( $page_id ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validate( WP_REST_Request $request ): array {
		$target = $this->target( $request );

		if ( 'chrome' === $target ) {
			$chrome    = $this->plugin->chrome();
			$schema    = $chrome->schema() ?? PageSchema::from_array( array() );
			$validator = new TemplateValidator();
			$errors    = array();
			$warnings  = array();

			foreach ( array( 'header', 'footer' ) as $part ) {
				$markup = (string) $request->get_param( $part );
				if ( '' === trim( $markup ) ) {
					continue;
				}
				$result = $validator->validate( $markup, $schema );
				foreach ( $result['errors'] as $error ) {
					$errors[] = array_merge( $this->locate( $error ), array( 'file' => $part ) );
				}
				foreach ( $result['warnings'] as $warning ) {
					$warnings[] = array( 'file' => $part, 'message' => $warning );
				}
			}

			$css = ( new \AIWP\Designer\Design\CSSValidator() )->validate_and_scope_to(
				(string) $request->get_param( 'css' ),
				\AIWP\Designer\Chrome\ChromeManager::SCOPE,
				true
			);

			foreach ( $css['errors'] as $error ) {
				$errors[] = array_merge( $this->locate( $error ), array( 'file' => 'css' ) );
			}
			foreach ( $css['warnings'] as $warning ) {
				$warnings[] = array( 'file' => 'css', 'message' => $warning );
			}

			return array(
				'valid'    => array() === $errors,
				'errors'   => $errors,
				'warnings' => $warnings,
			);
		}

		$page_id  = absint( $request->get_param( 'page_id' ) );
		$schema   = $this->plugin->pages()->schema( $page_id ) ?? PageSchema::from_array( array() );
		$manifest = $this->plugin->pages()->manifest( $page_id );

		$result = ( new PageValidator() )->validate(
			array(
				'schema'    => $schema,
				'template'  => (string) $request->get_param( 'template' ),
				'css'       => (string) $request->get_param( 'css' ),
				'behaviors' => (array) $manifest->get( 'behaviors', array() ),
				'forms'     => (array) $manifest->get( 'forms', array() ),
			),
			$this->plugin->pages()->uuid( $page_id )
		);

		$errors = array();
		foreach ( $result['errors'] as $error ) {
			$errors[] = array_merge( $this->locate( $error ), array( 'file' => $this->file_for( $error ) ) );
		}

		$warnings = array();
		foreach ( $result['warnings'] as $warning ) {
			$warnings[] = array( 'file' => $this->file_for( $warning ), 'message' => $warning );
		}

		return array(
			'valid'    => array() === $errors,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Pull a line number out of a validator message when it has one.
	 *
	 * @return array{line:int,message:string}
	 */
	private function locate( string $message ): array {
		$line = 0;

		if ( preg_match( '/\bline (\d+)/i', $message, $match ) ) {
			$line = (int) $match[1];
		}

		return array(
			'line'    => $line,
			'message' => $message,
		);
	}

	private function file_for( string $message ): string {
		return false !== strpos( $message, 'AIWP_CSS_INVALID' ) || false !== strpos( $message, 'CSS' )
			? 'css'
			: 'template';
	}

	private function target( WP_REST_Request $request ): string {
		return 'chrome' === $request->get_param( 'target' ) ? 'chrome' : 'page';
	}
}
