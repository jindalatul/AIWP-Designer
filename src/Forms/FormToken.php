<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Forms;

/**
 * Signed token embedded in every rendered form.
 *
 * It identifies the page and the form, and carries when it was issued so a
 * submission that arrives impossibly fast can be refused. It is not a session
 * and holds nothing about the visitor.
 */
final class FormToken {

	private const OPTION_SECRET = 'aiwp_form_secret';

	/** Long enough to survive full-page caching. */
	public const TTL = 604800;

	/** A human cannot fill in a real form this fast. */
	public const MIN_SECONDS = 2;

	private static function secret(): string {
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPTION_SECRET, $secret, false );
		}
		return $secret;
	}

	public static function create( int $page_id, string $form_id ): string {
		$payload = $page_id . '|' . $form_id . '|' . time();
		$sig     = hash_hmac( 'sha256', $payload, self::secret() );

		return rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * @return array{valid:bool,page_id:int,form_id:string,age:int,reason:string}
	 */
	public static function verify( string $token ): array {
		$fail = static fn( string $reason ): array => array(
			'valid'   => false,
			'page_id' => 0,
			'form_id' => '',
			'age'     => 0,
			'reason'  => $reason,
		);

		$padded  = strtr( $token, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		$decoded = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( ! is_string( $decoded ) ) {
			return $fail( 'malformed' );
		}

		$parts = explode( '|', $decoded );
		if ( 4 !== count( $parts ) ) {
			return $fail( 'malformed' );
		}

		list( $page_id, $form_id, $issued, $sig ) = $parts;

		$expected = hash_hmac( 'sha256', $page_id . '|' . $form_id . '|' . $issued, self::secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $fail( 'bad_signature' );
		}

		$age = time() - (int) $issued;

		if ( $age > self::TTL ) {
			return $fail( 'expired' );
		}

		if ( $age < 0 ) {
			return $fail( 'bad_signature' );
		}

		return array(
			'valid'   => true,
			'page_id' => (int) $page_id,
			'form_id' => $form_id,
			'age'     => $age,
			'reason'  => '',
		);
	}
}
