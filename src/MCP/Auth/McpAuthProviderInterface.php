<?php
declare( strict_types = 1 );

namespace AIWP\Designer\MCP\Auth;

use WP_REST_Request;

/**
 * Authentication is isolated behind this interface so OAuth can be added later
 * without touching a single MCP tool.
 */
interface McpAuthProviderInterface {

	/**
	 * @return int WordPress user id, or 0 when the request is not authenticated.
	 */
	public function authenticate( WP_REST_Request $request ): int;

	public function label(): string;
}
