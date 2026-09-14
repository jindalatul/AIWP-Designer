<?php
declare( strict_types = 1 );

use AIWP\Designer\Pages\FrontPage;
use PHPUnit\Framework\TestCase;

/**
 * The naming rule that lets a homepage claim the site root on its own.
 */
final class FrontPageTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'get_option' ) ) {
			eval(
				'function get_option( $key, $default = false ) { return $GLOBALS["aiwp_options"][ $key ] ?? $default; }
				function update_option( $key, $value, $autoload = null ) { $GLOBALS["aiwp_options"][ $key ] = $value; return true; }
				function delete_option( $key ) { unset( $GLOBALS["aiwp_options"][ $key ] ); return true; }
				function wp_generate_password( $length = 12, $special = true, $extra = false ) { return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ); }'
			);
		}
		if ( ! function_exists( 'get_post_type' ) ) {
			eval( 'function get_post_type( $id = 0 ) { return $GLOBALS["aiwp_post_types"][ (int) $id ] ?? false; }' );
		}
	}

	protected function setUp(): void {
		$GLOBALS['aiwp_options']    = array();
		$GLOBALS['aiwp_post_types'] = array( 42 => 'page' );
	}

	private function front_is_set(): void {
		$GLOBALS['aiwp_options']['show_on_front'] = 'page';
		$GLOBALS['aiwp_options']['page_on_front'] = 42;
	}

	/**
	 * @dataProvider home_names
	 */
	public function test_a_page_named_like_a_homepage_claims_the_root( string $field, string $value ): void {
		$this->assertTrue( FrontPage::wants_front_page( array( $field => $value ) ), $field . ' = ' . $value );
	}

	public static function home_names(): array {
		return array(
			array( 'slug', 'home' ),
			array( 'slug', 'homepage' ),
			array( 'slug', 'index' ),
			array( 'slug', 'front-page' ),
			array( 'title', 'Home' ),
			array( 'title', 'HOME' ),
			array( 'title', ' Homepage ' ),
		);
	}

	/**
	 * @dataProvider other_names
	 */
	public function test_an_ordinary_page_does_not_claim_the_root( string $value ): void {
		$this->assertFalse( FrontPage::wants_front_page( array( 'slug' => $value, 'title' => $value ) ) );
	}

	public static function other_names(): array {
		return array(
			array( 'pricing' ),
			array( 'about' ),
			array( 'home-insurance' ),
			array( 'homeware' ),
			array( 'our-home' ),
			array( '' ),
		);
	}

	public function test_an_existing_front_page_is_never_replaced_by_accident(): void {
		$this->front_is_set();

		$this->assertFalse(
			FrontPage::wants_front_page( array( 'slug' => 'home', 'title' => 'Home' ) ),
			'a second page named Home must not take the slot from the first'
		);
	}

	public function test_explicit_true_wins_even_when_a_front_page_exists(): void {
		$this->front_is_set();
		$this->assertTrue( FrontPage::wants_front_page( array( 'slug' => 'pricing', 'front_page' => true ) ) );
	}

	public function test_explicit_false_wins_over_the_naming_rule(): void {
		$this->assertFalse( FrontPage::wants_front_page( array( 'slug' => 'home', 'front_page' => false ) ) );
	}

	public function test_the_slot_counts_as_empty_when_the_site_shows_posts(): void {
		$GLOBALS['aiwp_options']['show_on_front'] = 'posts';
		$GLOBALS['aiwp_options']['page_on_front'] = 42;

		$this->assertTrue( FrontPage::is_unset() );
		$this->assertSame( 0, FrontPage::current() );
	}

	public function test_the_slot_counts_as_empty_when_it_points_at_something_gone(): void {
		$GLOBALS['aiwp_options']['show_on_front'] = 'page';
		$GLOBALS['aiwp_options']['page_on_front'] = 999;

		$this->assertTrue( FrontPage::is_unset(), 'a missing page is not a front page' );
	}

	public function test_setting_refuses_a_page_that_does_not_exist(): void {
		$this->assertFalse( FrontPage::set( 999 ) );
		$this->assertFalse( FrontPage::set( 0 ) );
	}
}
