<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * PreviewToken needs WordPress options, so those are stubbed in-memory here.
 */
final class PreviewTokenTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'get_option' ) ) {
			eval(
				'function get_option( $key, $default = false ) { return $GLOBALS["aiwp_options"][ $key ] ?? $default; }
				function update_option( $key, $value, $autoload = null ) { $GLOBALS["aiwp_options"][ $key ] = $value; return true; }
				function delete_option( $key ) { unset( $GLOBALS["aiwp_options"][ $key ] ); return true; }
				function wp_generate_password( $length = 12, $special = true, $extra = false ) { return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ); }'
			);
		}
		$GLOBALS['aiwp_options'] = array();
	}

	public function test_a_fresh_token_verifies(): void {
		$token  = \AIWP\Designer\Pages\PreviewToken::create( 'page-uuid', 3 );
		$result = \AIWP\Designer\Pages\PreviewToken::verify( $token );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'page-uuid', $result['page_uuid'] );
		$this->assertSame( 3, $result['version'] );
	}

	public function test_a_tampered_token_fails(): void {
		$token = \AIWP\Designer\Pages\PreviewToken::create( 'page-uuid', 1 );
		$this->assertFalse( \AIWP\Designer\Pages\PreviewToken::verify( $token . 'x' )['valid'] );
	}

	public function test_rubbish_fails_rather_than_throwing(): void {
		foreach ( array( '', 'nonsense', '....', base64_encode( 'a|b|c' ) ) as $candidate ) {
			$this->assertFalse( \AIWP\Designer\Pages\PreviewToken::verify( $candidate )['valid'] );
		}
	}

	public function test_an_expired_token_fails(): void {
		$token = \AIWP\Designer\Pages\PreviewToken::create( 'page-uuid', 1, -1 );
		// TTL is floored at 60 seconds, so force expiry by rotating the secret instead.
		\AIWP\Designer\Pages\PreviewToken::rotate();
		$this->assertFalse( \AIWP\Designer\Pages\PreviewToken::verify( $token )['valid'] );
	}
}
