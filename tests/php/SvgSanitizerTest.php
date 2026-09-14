<?php
declare( strict_types = 1 );

use AIWP\Designer\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase {

	private function clean( string $svg ): array {
		return ( new SvgSanitizer() )->sanitize( $svg );
	}

	private function wrap( string $inner, string $root = '' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"' . $root . '>' . $inner . '</svg>';
	}

	public function test_a_plain_drawing_survives(): void {
		$result = $this->clean( $this->wrap( '<rect width="100" height="100" fill="#0a0"></rect>' ) );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertStringContainsString( '<rect', $result['svg'] );
		$this->assertStringContainsString( '#0a0', $result['svg'] );
	}

	public function test_a_gradient_and_its_reference_survive(): void {
		$svg = $this->wrap(
			'<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"></stop></linearGradient></defs>'
			. '<rect width="100" height="100" fill="url(#g)"></rect>'
		);
		$result = $this->clean( $svg );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertStringContainsString( 'url(#g)', $result['svg'] );
	}

	/**
	 * @dataProvider hostile_parts
	 */
	public function test_hostile_parts_are_removed( string $inner, string $root, string $gone ): void {
		$result = $this->clean( $this->wrap( $inner, $root ) );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertStringNotContainsStringIgnoringCase( $gone, $result['svg'] );
	}

	public static function hostile_parts(): array {
		return array(
			'script tag'        => array( '<script>alert(1)</script>', '', 'script' ),
			'event on root'     => array( '<rect width="1" height="1"></rect>', ' onload="alert(1)"', 'onload' ),
			'event on shape'    => array( '<rect width="1" height="1" onclick="alert(1)"></rect>', '', 'onclick' ),
			'external image'    => array( '<image href="https://evil.test/x.png"></image>', '', 'evil.test' ),
			'link'              => array( '<a href="https://evil.test"><rect width="1" height="1"></rect></a>', '', 'evil.test' ),
			'foreign object'    => array( '<foreignObject><div>x</div></foreignObject>', '', 'foreignObject' ),
			'animation'         => array( '<animate attributeName="x" to="9"></animate>', '', 'animate' ),
			'style block'       => array( '<style>*{x:y}</style>', '', 'style' ),
			'external use'      => array( '<use href="https://evil.test/x.svg#a"></use>', '', 'evil.test' ),
			'external fill'     => array( '<rect width="1" height="1" fill="url(https://evil.test/x)"></rect>', '', 'evil.test' ),
			'javascript in fill'=> array( '<rect width="1" height="1" fill="javascript:alert(1)"></rect>', '', 'javascript:' ),
			'comment'           => array( '<!-- a note --><rect width="1" height="1"></rect>', '', 'a note' ),
		);
	}

	public function test_an_internal_use_reference_survives(): void {
		$svg = $this->wrap( '<defs><rect id="r" width="1" height="1"></rect></defs><use href="#r"></use>' );
		$result = $this->clean( $svg );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertStringContainsString( 'href="#r"', $result['svg'] );
	}

	public function test_removed_parts_are_reported(): void {
		$result = $this->clean( $this->wrap( '<script>alert(1)</script><rect width="1" height="1" onclick="x"></rect>' ) );
		$this->assertContains( '<script>', $result['removed'] );
		$this->assertContains( 'onclick', $result['removed'] );
	}

	public function test_a_doctype_is_refused_outright(): void {
		$svg = '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $this->wrap( '<rect width="1" height="1"></rect>' );
		$result = $this->clean( $svg );
		$this->assertFalse( $result['ok'] );
	}

	public function test_an_entity_declaration_is_refused_outright(): void {
		$result = $this->clean( '<!ENTITY a "b">' . $this->wrap( '<rect width="1" height="1"></rect>' ) );
		$this->assertFalse( $result['ok'] );
	}

	public function test_markup_that_is_not_svg_is_refused(): void {
		$this->assertFalse( $this->clean( '<html><body>hi</body></html>' )['ok'] );
	}

	public function test_broken_xml_is_refused(): void {
		$this->assertFalse( $this->clean( '<svg viewBox="0 0 1 1"><rect></svg>' )['ok'] );
	}

	public function test_an_svg_without_a_viewbox_is_refused(): void {
		$result = $this->clean( '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"></rect></svg>' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'viewBox', $result['error'] );
	}

	public function test_an_empty_string_is_refused(): void {
		$this->assertFalse( $this->clean( '   ' )['ok'] );
	}

	public function test_the_namespace_is_added_when_it_is_missing(): void {
		$result = $this->clean( '<svg viewBox="0 0 1 1"><rect width="1" height="1"></rect></svg>' );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertStringContainsString( 'http://www.w3.org/2000/svg', $result['svg'] );
	}
}
