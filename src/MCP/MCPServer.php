<?php
declare( strict_types = 1 );

namespace AIWP\Designer\MCP;

use AIWP\Designer\MCP\Auth\McpAuthProviderInterface;
use AIWP\Designer\MCP\Resources\ResourceRegistry;
use AIWP\Designer\MCP\Tools\ToolRegistry;
use AIWP\Designer\Plugin;
use AIWP\Designer\Prompts\PromptCompiler;
use AIWP\Designer\Prompts\PromptRegistry;
use AIWP\Designer\Security\AuditLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The MCP endpoint: JSON-RPC 2.0 over one WordPress REST route.
 *
 * Protocol handling sits behind this class so a future MCP revision does not
 * reach into the tools.
 */
final class MCPServer {

	private const MAX_BODY_BYTES = 4194304; // 4 MB

	private Plugin $plugin;
	private McpAuthProviderInterface $auth;
	private ToolRegistry $tools;
	private ResourceRegistry $resources;

	public function __construct( Plugin $plugin, McpAuthProviderInterface $auth ) {
		$this->plugin    = $plugin;
		$this->auth      = $auth;
		$this->tools     = new ToolRegistry( $plugin );
		$this->resources = new ResourceRegistry( $plugin, $this->tools );
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			AIWP_REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST,GET,DELETE',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function tools(): ToolRegistry {
		return $this->tools;
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		if ( 'GET' === $request->get_method() || 'DELETE' === $request->get_method() ) {
			// This server is request/response only; there is no SSE stream to open.
			return new WP_REST_Response( null, 405 );
		}

		$raw = $request->get_body();
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			return $this->rpc_error( null, -32600, 'Request body is too large.', 413 );
		}

		$user_id = $this->auth->authenticate( $request );
		if ( 0 === $user_id ) {
			return $this->rpc_error(
				null,
				-32001,
				'AIWP_MCP_UNAUTHORIZED: send a valid "Authorization: Bearer <token>" header. Generate a token in WordPress under AIWP Designer → MCP Connection.',
				401
			);
		}

		wp_set_current_user( $user_id );

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return $this->rpc_error( null, -32700, 'Parse error: the body is not valid JSON.', 200 );
		}

		// A batch is an array of request objects.
		if ( array_is_list( $payload ) ) {
			$responses = array();
			foreach ( $payload as $single ) {
				$response = is_array( $single ) ? $this->dispatch( $single, $user_id ) : null;
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			return new WP_REST_Response( array() === $responses ? null : $responses, array() === $responses ? 202 : 200 );
		}

		$response = $this->dispatch( $payload, $user_id );

		if ( null === $response ) {
			// A notification gets no body.
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * @param array<string,mixed> $message
	 * @return array<string,mixed>|null
	 */
	private function dispatch( array $message, int $user_id ): ?array {
		$method = (string) ( $message['method'] ?? '' );
		$id     = $message['id'] ?? null;
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();

		// Notifications carry no id and get no response.
		$is_notification = ! array_key_exists( 'id', $message ) || null === $id;

		switch ( $method ) {
			case 'initialize':
				return $this->result( $id, $this->initialize( $params ) );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return $this->result( $id, new \stdClass() );

			case 'server/discover':
				return $this->result( $id, $this->initialize( $params ) );

			case 'tools/list':
				return $this->result( $id, array( 'tools' => $this->tools->definitions() ) );

			case 'tools/call':
				return $this->result( $id, $this->call_tool( $params, $user_id ) );

			case 'prompts/list':
				return $this->result( $id, array( 'prompts' => $this->prompt_list() ) );

			case 'prompts/get':
				return $this->prompt_get( $id, $params );

			case 'resources/list':
				return $this->result( $id, array( 'resources' => $this->resources->list() ) );

			case 'resources/templates/list':
				return $this->result( $id, array( 'resourceTemplates' => array() ) );

			case 'resources/read':
				$contents = $this->resources->read( (string) ( $params['uri'] ?? '' ), $user_id );
				if ( null === $contents ) {
					return $this->error( $id, -32602, sprintf( 'Unknown resource "%s".', (string) ( $params['uri'] ?? '' ) ) );
				}
				return $this->result( $id, array( 'contents' => $contents ) );

			case 'logging/setLevel':
				return $this->result( $id, new \stdClass() );
		}

		if ( $is_notification ) {
			return null;
		}

		return $this->error( $id, -32601, sprintf( 'Method "%s" is not supported by this server.', $method ) );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function initialize( array $params ): array {
		$requested = (string) ( $params['protocolVersion'] ?? AIWP_MCP_PROTOCOL_VERSION );

		AuditLogger::log( 'mcp_connected', array( 'client' => (string) ( $params['clientInfo']['name'] ?? 'unknown' ) ) );

		return array(
			'protocolVersion' => $requested,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'prompts'   => array( 'listChanged' => false ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
			),
			'serverInfo'      => array(
				'name'    => 'AIWP Designer',
				'title'   => 'AIWP Designer — ' . get_bloginfo( 'name' ),
				'version' => AIWP_VERSION,
			),
			'instructions'    => $this->server_instructions(),
		);
	}

	private function server_instructions(): string {
		return implode(
			"\n",
			array(
				'This WordPress site is designed through AIWP Designer. You are the designer; the plugin is the safe execution environment.',
				'',
				'Always start with workflow_prepare. It returns the plugin\'s current design instructions plus the workflow_id that every mutating tool requires.',
				'Then read site_get_capabilities before choosing field types or behaviors — never assume what is installed.',
				'',
				'You write template markup, page CSS, a field schema and starting content. You never write PHP or JavaScript.',
				'After creating or updating a page, open its preview_url in your browser, look at the result, and improve the weakest parts before calling the work done.',
				'Publishing is the user\'s decision: call page_publish only when they ask.',
			)
		);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function call_tool( array $params, int $user_id ): array {
		$name = (string) ( $params['name'] ?? '' );
		$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();

		if ( ! $this->tools->exists( $name ) ) {
			return $this->tool_text(
				array(
					'success' => false,
					'error'   => array(
						'code'    => 'AIWP_UNKNOWN_TOOL',
						'message' => sprintf( 'There is no tool called "%s".', $name ),
					),
				),
				true
			);
		}

		try {
			$result = $this->tools->call( $name, $args, $user_id );
		} catch ( \Throwable $e ) {
			AuditLogger::log( 'mcp_tool_exception', array( 'tool' => $name ) );
			return $this->tool_text(
				array(
					'success' => false,
					'error'   => array(
						'code'    => 'AIWP_INTERNAL_ERROR',
						'message' => $e->getMessage(),
					),
				),
				true
			);
		}

		$is_error = isset( $result['success'] ) && false === $result['success'];

		return $this->tool_text( $result, $is_error );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function tool_text( array $data, bool $is_error ): array {
		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			),
			'structuredContent' => $data,
			'isError'           => $is_error,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function prompt_list(): array {
		$prompts = array();

		foreach ( PromptRegistry::WORKFLOWS as $type => $definition ) {
			$prompts[] = array(
				'name'        => $type,
				'title'       => $definition['title'],
				'description' => $definition['title'] . '. Compiled AIWP Designer instructions for this workflow.',
				'arguments'   => array(
					array(
						'name'        => 'page_id',
						'description' => 'Optional WordPress page id this workflow is about.',
						'required'    => false,
					),
				),
			);
		}

		return $prompts;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function prompt_get( mixed $id, array $params ): array {
		$name = (string) ( $params['name'] ?? '' );

		if ( ! PromptRegistry::exists( $name ) ) {
			return $this->error( $id, -32602, sprintf( 'Unknown prompt "%s".', $name ) );
		}

		$definition = PromptRegistry::get( $name );
		$context    = array();

		foreach ( (array) $definition['context'] as $needed ) {
			switch ( $needed ) {
				case 'site_context':
					$context['site_context'] = $this->tools->build_site_context();
					break;
				case 'capabilities':
					$context['capabilities'] = $this->tools->build_capabilities();
					break;
				case 'design_system':
					$context['design_system'] = $this->tools->build_design_system();
					break;
			}
		}

		$compiled = ( new PromptCompiler( $this->plugin->prompt_loader() ) )->compile( $name, $context );

		return $this->result(
			$id,
			array(
				'description' => $definition['title'],
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => $compiled['instructions'] . "\n\nRemember: call workflow_prepare before any mutating tool, even though you already have these instructions.",
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed>|object $result
	 * @return array<string,mixed>
	 */
	private function result( mixed $id, mixed $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function error( mixed $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private function rpc_error( mixed $id, int $code, string $message, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $this->error( $id, $code, $message ), $status );

		if ( 401 === $status ) {
			$response->header( 'WWW-Authenticate', 'Bearer realm="AIWP Designer"' );
		}

		return $response;
	}
}
