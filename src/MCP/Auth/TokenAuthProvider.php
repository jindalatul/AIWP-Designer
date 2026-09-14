<?php
declare( strict_types = 1 );

namespace AIWP\Designer\MCP\Auth;

use AIWP\Designer\Security\AuditLogger;
use WP_REST_Request;

/**
 * Manual bearer-token connection mode, for development and self-hosted setups.
 *
 * Only a hash is stored. The full token is shown once, when it is created.
 */
final class TokenAuthProvider implements McpAuthProviderInterface {

	private const OPTION = 'aiwp_mcp_tokens';

	public function label(): string {
		return 'bearer-token (development / manual connection)';
	}

	public function authenticate( WP_REST_Request $request ): int {
		$token = $this->extract_token( $request );
		if ( '' === $token ) {
			return 0;
		}

		$hash   = hash( 'sha256', $token );
		$tokens = $this->tokens();

		foreach ( $tokens as $index => $record ) {
			if ( ! hash_equals( (string) $record['hash'], $hash ) ) {
				continue;
			}

			$tokens[ $index ]['last_used'] = gmdate( 'c' );
			update_option( self::OPTION, $tokens, false );

			return (int) $record['user_id'];
		}

		return 0;
	}

	private function extract_token( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $header ) {
			// Some Apache setups drop the header before PHP sees it.
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$header = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					break;
				}
			}
		}

		if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			foreach ( (array) $headers as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$header = (string) $value;
					break;
				}
			}
		}

		if ( 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function tokens(): array {
		$tokens = get_option( self::OPTION, array() );
		return is_array( $tokens ) ? array_values( $tokens ) : array();
	}

	/**
	 * @return array{token:string,id:string}
	 */
	public function create( int $user_id, string $label ): array {
		$token = 'aiwp_' . bin2hex( random_bytes( 24 ) );
		$id    = wp_generate_uuid4();

		$tokens   = $this->tokens();
		$tokens[] = array(
			'id'        => $id,
			'label'     => sanitize_text_field( $label ),
			'hash'      => hash( 'sha256', $token ),
			'user_id'   => $user_id,
			'created'   => gmdate( 'c' ),
			'last_used' => '',
		);

		update_option( self::OPTION, $tokens, false );
		AuditLogger::log( 'mcp_token_created', array( 'id' => $id, 'user_id' => $user_id ) );

		return array(
			'token' => $token,
			'id'    => $id,
		);
	}

	public function revoke( string $id ): bool {
		$tokens = $this->tokens();
		$kept   = array();
		$found  = false;

		foreach ( $tokens as $record ) {
			if ( $record['id'] === $id ) {
				$found = true;
				continue;
			}
			$kept[] = $record;
		}

		if ( $found ) {
			update_option( self::OPTION, $kept, false );
			AuditLogger::log( 'mcp_token_revoked', array( 'id' => $id ) );
		}

		return $found;
	}

	public function revoke_all(): void {
		update_option( self::OPTION, array(), false );
		AuditLogger::log( 'mcp_tokens_cleared' );
	}
}
