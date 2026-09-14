<?php
declare( strict_types = 1 );

use AIWP\Designer\Template\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * A 1024px file stretched across a 1440px hero is the difference between a page
 * that looks designed and one that looks cheap, so these filters matter.
 */
final class ImageFiltersTest extends TestCase {

	private const CONTENT = array(
		'hero' => array(
			'image' => 42,
			'none'  => '',
			'array' => array( 'ID' => 7 ),
		),
	);

	private function render( string $template ): string {
		return ( new TemplateEngine() )->render_source( $template, self::CONTENT );
	}

	public function test_image_url_returns_the_full_size_file(): void {
		$this->assertSame( 'http://example.test/wp-content/uploads/image-42.jpg', $this->render( '{{image_url:hero.image}}' ) );
	}

	public function test_srcset_is_emitted(): void {
		$out = $this->render( '{{image_srcset:hero.image}}' );
		$this->assertStringContainsString( '2400w', $out );
		$this->assertStringContainsString( '800w', $out );
	}

	public function test_alt_comes_from_the_media_library(): void {
		$this->assertSame( 'Alt from the media library', $this->render( '{{image_alt:hero.image}}' ) );
	}

	public function test_intrinsic_dimensions_are_emitted(): void {
		$this->assertSame( '2400', $this->render( '{{image_width:hero.image}}' ) );
		$this->assertSame( '1600', $this->render( '{{image_height:hero.image}}' ) );
	}

	public function test_an_attachment_array_still_resolves(): void {
		$this->assertStringContainsString( '2400w', $this->render( '{{image_srcset:hero.array}}' ) );
	}

	public function test_an_empty_field_produces_empty_attributes(): void {
		$this->assertSame( '', $this->render( '{{image_srcset:hero.none}}' ) );
		$this->assertSame( '0', $this->render( '{{image_width:hero.none}}' ) );
		$this->assertSame( '', $this->render( '{{image_alt:hero.none}}' ) );
	}

	public function test_a_missing_field_does_not_fail(): void {
		$this->assertSame( '', $this->render( '{{image_srcset:hero.nope}}' ) );
	}
}
