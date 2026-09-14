<?php
declare( strict_types = 1 );

use AIWP\Designer\Forms\FormToken;
use PHPUnit\Framework\TestCase;

final class FormTokenTest extends TestCase {

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

	public function test_a_token_round_trips(): void {
		$result = FormToken::verify( FormToken::create( 142, 'enquiry' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 142, $result['page_id'] );
		$this->assertSame( 'enquiry', $result['form_id'] );
	}

	public function test_the_page_and_form_cannot_be_swapped_by_the_sender(): void {
		$token   = FormToken::create( 142, 'enquiry' );
		$decoded = base64_decode( strtr( $token, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $token ) % 4 ) % 4 ), true );
		$forged  = str_replace( '142|enquiry', '999|enquiry', (string) $decoded );
		$token   = rtrim( strtr( base64_encode( $forged ), '+/', '-_' ), '=' );

		$this->assertFalse( FormToken::verify( $token )['valid'] );
	}

	/**
	 * @dataProvider rubbish
	 */
	public function test_rubbish_is_refused_without_throwing( string $token ): void {
		$this->assertFalse( FormToken::verify( $token )['valid'] );
	}

	public static function rubbish(): array {
		return array(
			array( '' ),
			array( 'nonsense' ),
			array( '....' ),
			array( base64_encode( 'a|b|c' ) ),
			array( base64_encode( '1|x|' . time() . '|deadbeef' ) ),
		);
	}

	public function test_the_age_is_reported_so_instant_submissions_can_be_refused(): void {
		$result = FormToken::verify( FormToken::create( 1, 'f' ) );

		$this->assertTrue( $result['valid'] );
		$this->assertLessThan( FormToken::MIN_SECONDS, $result['age'], 'a token just created is younger than the minimum fill time' );
	}
}
