<?php
declare( strict_types = 1 );

use AIWP\Designer\Rendering\PageRenderer;
use PHPUnit\Framework\TestCase;

/**
 * One main landmark per document.
 *
 * Writing <main> around a page is the natural thing to do and the allowlist
 * permits it, so templates do it. Wrapping that in a second <main> gives the
 * document two main landmarks: invalid HTML, and a screen reader offering a
 * choice of two "main" regions, neither of which is wrong. Nothing looks
 * broken on screen, which is why it survived every check for months.
 */
final class PageElementTest extends TestCase {

	private function count_mains( string $html ): int {
		return preg_match_all( '/<main\b/i', $html );
	}

	public function test_a_template_that_opens_its_own_main_does_not_get_a_second(): void {
		$out = PageRenderer::as_page_element( '<main class="nq"><h1>A</h1></main>', 'uuid-1' );

		$this->assertSame( 1, $this->count_mains( $out ) );
	}

	public function test_the_template_classes_are_kept(): void {
		$out = PageRenderer::as_page_element( '<main class="nq wide"><h1>A</h1></main>', 'uuid-1' );

		$this->assertStringContainsString( 'aiwp-page', $out );
		$this->assertStringContainsString( 'nq wide', $out );
	}

	public function test_the_scope_id_is_on_the_page_element(): void {
		$out = PageRenderer::as_page_element( '<main class="nq">x</main>', 'uuid-42' );

		$this->assertMatchesRegularExpression( '/<main[^>]*data-aiwp-page="uuid-42"/', $out );
	}

	public function test_a_main_with_no_class_still_gets_one(): void {
		$out = PageRenderer::as_page_element( '<main><h1>A</h1></main>', 'uuid-1' );

		$this->assertSame( 1, $this->count_mains( $out ) );
		$this->assertStringContainsString( 'class="aiwp-page"', $out );
	}

	public function test_a_template_without_main_is_wrapped_as_before(): void {
		$out = PageRenderer::as_page_element( '<section><h1>A</h1></section>', 'uuid-1' );

		$this->assertSame( 1, $this->count_mains( $out ) );
		$this->assertStringStartsWith( '<main class="aiwp-page"', $out );
	}

	/**
	 * Two of them in a template is the template's problem, not something to
	 * unpick by guessing which one was meant.
	 */
	public function test_two_mains_in_a_template_are_wrapped_not_merged(): void {
		$out = PageRenderer::as_page_element( '<main>a</main><main>b</main>', 'uuid-1' );

		$this->assertSame( 3, $this->count_mains( $out ) );
	}

	public function test_a_main_that_is_not_the_outermost_element_is_left_alone(): void {
		$out = PageRenderer::as_page_element( '<section>x</section><main>y</main>', 'uuid-1' );

		$this->assertStringStartsWith( '<main class="aiwp-page"', $out );
	}
}
