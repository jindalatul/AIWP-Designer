<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\CSSValidator;
use PHPUnit\Framework\TestCase;

/**
 * `behavior:` is Internet Explorer's HTC binding and has to stay refused.
 * `scroll-behavior:` is ordinary CSS, and the plugin's own prompt asks every
 * site to write a reduced-motion block that contains it. A plain substring
 * search refused the stylesheet we told the author to write.
 */
final class CssBehaviorTest extends TestCase {

	private function errors( string $css ): array {
		$validator = new CSSValidator();
		$result    = $validator->validate( $css, true );

		return $result['errors'] ?? array();
	}

	public function test_internet_explorer_binding_is_still_refused(): void {
		$this->assertNotEmpty( $this->errors( '.a { behavior: url(evil.htc); }' ) );
	}

	public function test_a_space_before_the_colon_does_not_get_it_past(): void {
		$this->assertNotEmpty( $this->errors( '.a { behavior : url(evil.htc); }' ) );
	}

	public function test_scroll_behavior_is_allowed(): void {
		$this->assertSame( array(), $this->errors( 'html { scroll-behavior: smooth; }' ) );
	}

	public function test_overscroll_behavior_is_allowed(): void {
		$this->assertSame( array(), $this->errors( '.panel { overscroll-behavior: contain; }' ) );
	}

	public function test_the_reduced_motion_block_we_ask_for_is_allowed(): void {
		$css = '@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after {
				animation-duration: .01ms !important;
				transition-duration: .01ms !important;
				scroll-behavior: auto !important;
			}
		}';

		$this->assertSame( array(), $this->errors( $css ) );
	}
}
