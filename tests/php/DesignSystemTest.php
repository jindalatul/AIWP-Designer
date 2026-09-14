<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\DesignSystem;
use PHPUnit\Framework\TestCase;

final class DesignSystemTest extends TestCase {

	/**
	 * @dataProvider rejected_font_urls
	 */
	public function test_font_urls_outside_the_allowlist_are_dropped( string $url ): void {
		$system = DesignSystem::from_array( array( 'typography' => array( 'font_url' => $url ) ) );
		$this->assertSame( '', $system->font_url(), 'Should have been dropped: ' . $url );
	}

	public static function rejected_font_urls(): array {
		return array(
			'other host'   => array( 'https://evil.test/fonts.css' ),
			'http'         => array( 'http://fonts.googleapis.com/css2?family=Inter' ),
			'javascript'   => array( 'javascript:alert(1)' ),
			'protocol rel' => array( '//fonts.googleapis.com/css2?family=Inter' ),
			'lookalike'    => array( 'https://fonts.googleapis.com.evil.test/x.css' ),
			'empty'        => array( '' ),
		);
	}

	public function test_an_allowlisted_font_url_is_kept(): void {
		$url    = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap';
		$system = DesignSystem::from_array( array( 'typography' => array( 'font_url' => $url ) ) );
		$this->assertSame( $url, $system->font_url() );
	}

	public function test_bunny_is_allowed_too(): void {
		$url    = 'https://fonts.bunny.net/css?family=inter:400,600';
		$this->assertSame( $url, DesignSystem::from_array( array( 'typography' => array( 'font_url' => $url ) ) )->font_url() );
	}

	public function test_merging_keeps_what_was_not_sent(): void {
		$base = DesignSystem::from_array(
			array(
				'colors'     => array( 'accent' => '#a8613a', 'primary' => '#101113' ),
				'typography' => array( 'heading_font' => 'Fraunces, serif' ),
				'container'  => array( 'max' => '1360px' ),
			)
		);

		$merged = $base->merged_with( array( 'colors' => array( 'accent' => '#3f6bff' ) ) )->tokens();

		$this->assertSame( '#3f6bff', $merged['colors']['accent'], 'the change should apply' );
		$this->assertSame( '#101113', $merged['colors']['primary'], 'other colours must survive' );
		$this->assertSame( 'Fraunces, serif', $merged['typography']['heading_font'], 'typography must survive' );
		$this->assertSame( '1360px', $merged['container']['max'], 'container must survive' );
	}

	public function test_a_bad_colour_falls_back_rather_than_injecting(): void {
		$tokens = DesignSystem::from_array( array( 'colors' => array( 'accent' => 'red; } body { display:none' ) ) )->tokens();
		$this->assertSame( '#000000', $tokens['colors']['accent'] );
	}

	public function test_lengths_cannot_carry_css_syntax(): void {
		$tokens = DesignSystem::from_array( array( 'spacing' => array( 'md' => '32px; } * { color: red' ) ) )->tokens();
		$this->assertStringNotContainsString( '{', $tokens['spacing']['md'] );
		$this->assertStringNotContainsString( '}', $tokens['spacing']['md'] );
	}

	public function test_tokens_compile_to_custom_properties(): void {
		$css = DesignSystem::defaults()->to_css();
		$this->assertStringContainsString( '--aiwp-color-primary:', $css );
		$this->assertStringContainsString( '--aiwp-space-lg:', $css );
		$this->assertStringContainsString( '--aiwp-font-heading:', $css );
		$this->assertStringNotContainsString( 'font_url', $css );
	}
}
