<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

/**
 * Short-lived signed token so an AI can open a draft preview without a login.
 *
 * Holds no credentials: page uuid, version, expiry and a signature only.
 */
final class PreviewToken {

	private const OPTION_SECRET = 'aiwp_preview_secret';
	public const  TTL           = 3600;

	public static function secret(): string {
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPTION_SECRET, $secret, false );
		}
		return $secret;
	}

	public static function rotate(): void {
		delete_option( self::OPTION_SECRET );
		self::secret();
	}

	public static function create( string $page_uuid, int $version, int $ttl = self::TTL ): string {
		$expires = time() + max( 60, $ttl );
		$nonce   = wp_generate_password( 12, false, false );
		$payload = $page_uuid . '|' . $version . '|' . $expires . '|' . $nonce;
		$sig     = hash_hmac( 'sha256', $payload, self::secret() );

		return rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * @return array{valid:bool,page_uuid:string,version:int}
	 */
	public static function verify( string $token ): array {
		$fail = array(
			'valid'     => false,
			'page_uuid' => '',
			'version'   => 0,
		);

		$padded  = strtr( $token, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		$decoded = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( ! is_string( $decoded ) ) {
			return $fail;
		}

		$parts = explode( '|', $decoded );
		if ( 5 !== count( $parts ) ) {
			return $fail;
		}

		list( $uuid, $version, $expires, $nonce, $sig ) = $parts;

		$expected = hash_hmac( 'sha256', $uuid . '|' . $version . '|' . $expires . '|' . $nonce, self::secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $fail;
		}

		if ( (int) $expires < time() ) {
			return $fail;
		}

		return array(
			'valid'     => true,
			'page_uuid' => $uuid,
			'version'   => (int) $version,
		);
	}
}
