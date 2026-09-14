<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\CSSValidator;
use PHPUnit\Framework\TestCase;

final class CSSValidatorTest extends TestCase {

	private const UUID  = '11111111-2222-4333-8444-555555555555';
	private const SCOPE = '[data-aiwp-page="11111111-2222-4333-8444-555555555555"]';

	private function scope( string $css ): array {
		return ( new CSSValidator() )->validate_and_scope( $css, self::UUID );
	}

	/**
	 * @dataProvider malicious_css
	 */
	public function test_malicious_css_is_rejected( string $css ): void {
		$this->assertFalse( $this->scope( $css )['valid'], 'Should have been rejected: ' . $css );
	}

	public static function malicious_css(): array {
		return array(
			'import'          => array( '@import url("https://evil.test/x.css");' ),
			'expression'      => array( '.a{width:expression(alert(1))}' ),
			'javascript url'  => array( '.a{background:url(javascript:alert(1))}' ),
			'moz binding'     => array( '.a{-moz-binding:url(https://evil.test/x.xml)}' ),
			'behavior'        => array( '.a{behavior:url(x.htc)}' ),
			'style escape'    => array( '.a{color:red}</style><script>alert(1)</script>' ),
			'html data uri'   => array( '.a{background:url("data:text/html,<script>alert(1)</script>")}' ),
			'unbalanced'      => array( '.a{color:red' ),
		);
	}

	public function test_plain_rules_are_scoped(): void {
		$out = $this->scope( '.hero{color:red}' );
		$this->assertTrue( $out['valid'] );
		$this->assertSame( self::SCOPE . ' .hero{color:red}', $out['css'] );
	}

	public function test_body_and_root_collapse_onto_the_page_container(): void {
		$out = $this->scope( 'body{background:#fff}' );
		$this->assertStringNotContainsString( 'body{', $out['css'] );
		$this->assertStringContainsString( self::SCOPE, $out['css'] );
	}

	public function test_universal_selector_cannot_escape(): void {
		$out = $this->scope( '*{margin:0}' );
		$this->assertSame( self::SCOPE . ' *{margin:0}', $out['css'] );
	}

	public function test_selector_lists_are_each_scoped(): void {
		$out = $this->scope( '.a,.b{color:red}' );
		$this->assertSame( self::SCOPE . ' .a,' . self::SCOPE . ' .b{color:red}', $out['css'] );
	}

	public function test_commas_inside_is_are_not_split(): void {
		$out = $this->scope( '.a:is(.b,.c){color:red}' );
		$this->assertSame( self::SCOPE . ' .a:is(.b,.c){color:red}', $out['css'] );
	}

	public function test_media_query_contents_are_scoped(): void {
		$out = $this->scope( '@media (max-width:40rem){.a{color:red}}' );
		$this->assertSame( '@media (max-width:40rem){' . self::SCOPE . ' .a{color:red}}', $out['css'] );
	}

	public function test_keyframes_are_left_alone(): void {
		$out = $this->scope( '@keyframes fade{from{opacity:0}to{opacity:1}}' );
		$this->assertStringNotContainsString( self::SCOPE . ' from', $out['css'] );
		$this->assertStringContainsString( '@keyframes fade', $out['css'] );
	}

	public function test_already_scoped_css_is_not_double_scoped(): void {
		$css = self::SCOPE . ' .a{color:red}';
		$this->assertSame( $css, $this->scope( $css )['css'] );
	}

	public function test_remote_asset_is_a_warning_not_an_error(): void {
		$out = $this->scope( '.a{background:url(https://cdn.test/a.png)}' );
		$this->assertTrue( $out['valid'] );
		$this->assertNotEmpty( $out['warnings'] );
	}

	public function test_a_leading_comment_does_not_become_a_selector(): void {
		$out = $this->scope( "/* Brand override, applies here only. */\n.hero{color:red}" );
		$this->assertTrue( $out['valid'] );
		$this->assertSame( self::SCOPE . ' .hero{color:red}', trim( $out['css'] ) );
	}

	public function test_a_comment_containing_braces_does_not_break_the_brace_count(): void {
		$out = $this->scope( "/* like .a{b} but not */\n.hero{color:red}" );
		$this->assertTrue( $out['valid'], implode( ' | ', $out['errors'] ) );
	}

	public function test_a_comment_mentioning_import_is_not_a_rejection(): void {
		$out = $this->scope( "/* we never use @import here */\n.hero{color:red}" );
		$this->assertTrue( $out['valid'], implode( ' | ', $out['errors'] ) );
	}

	public function test_a_comment_between_rules_is_removed(): void {
		$out = $this->scope( ".a{color:red}/* note, with a comma */.b{color:blue}" );
		$this->assertSame( self::SCOPE . ' .a{color:red}' . self::SCOPE . ' .b{color:blue}', trim( $out['css'] ) );
	}

	public function test_a_comment_marker_inside_a_string_survives(): void {
		$out = $this->scope( '.a::before{content:"/* not a comment */"}' );
		$this->assertStringContainsString( 'not a comment', $out['css'] );
	}

	public function test_root_is_rewritten_to_the_page_scope(): void {
		$out = $this->scope( ':root{--aiwp-color-accent:#a8613a}' );
		$this->assertSame( self::SCOPE . '{--aiwp-color-accent:#a8613a}', $out['css'] );
	}

	public function test_a_chrome_scope_can_match_its_own_root_element(): void {
		$out = ( new CSSValidator() )->validate_and_scope_to( '.aiwp-chrome--footer{background:#000}', '[data-aiwp-chrome]', true );

		$this->assertStringContainsString( '[data-aiwp-chrome].aiwp-chrome--footer', $out['css'], 'the element carrying the scope must be styleable' );
		$this->assertStringContainsString( '[data-aiwp-chrome] .aiwp-chrome--footer', $out['css'], 'descendants must still match' );
	}

	public function test_page_scoping_does_not_emit_the_root_form(): void {
		$out = $this->scope( '.hero{color:red}' );
		$this->assertSame( self::SCOPE . ' .hero{color:red}', $out['css'] );
	}

	public function test_minified_css_is_laid_out_for_editing(): void {
		$out = CSSValidator::format( '[data-aiwp-page="x"] .a{color:red;margin:0}[data-aiwp-page="x"] .b{color:blue}' );

		// The formatter adds line breaks and indentation only. It deliberately does
		// not rewrite declarations, so it can never mangle :hover or url(data:…).
		$this->assertStringContainsString( "color:red;\n", $out, 'declarations go on their own lines' );
		$this->assertStringContainsString( "}\n", $out, 'rules are separated' );
		$this->assertGreaterThan( 4, substr_count( $out, "\n" ), 'the result is more than one line' );
	}

	public function test_formatting_indents_inside_a_media_query(): void {
		$out = CSSValidator::format( '@media (max-width:40rem){.a{color:red}}' );

		$this->assertStringContainsString( '@media (max-width:40rem) {', $out );
		$this->assertStringContainsString( '  .a {', $out );
	}

	public function test_formatting_leaves_hand_written_css_alone(): void {
		$css = ".hero {\n  color: red;\n}\n\n.other {\n  color: blue;\n}\n";
		$this->assertSame( trim( $css ), trim( CSSValidator::format( $css ) ) );
	}

	public function test_formatting_does_not_break_quoted_values(): void {
		$out = CSSValidator::format( '.a::before{content:"a;b{c}d"}' );
		$this->assertStringContainsString( 'content:"a;b{c}d"', $out, 'a brace or semicolon inside a string is not a break' );
	}

	public function test_formatting_survives_empty_input(): void {
		$this->assertSame( '', CSSValidator::format( '' ) );
		$this->assertSame( '', CSSValidator::format( '   ' ) );
	}

	public function test_formatted_css_still_validates_and_scopes(): void {
		$formatted = CSSValidator::format( '.a{color:red}.b{color:blue}' );
		$out       = $this->scope( $formatted );

		$this->assertTrue( $out['valid'], implode( ' | ', $out['errors'] ) );
		$this->assertStringContainsString( self::SCOPE . ' .a', $out['css'] );
		$this->assertStringContainsString( self::SCOPE . ' .b', $out['css'] );
	}

	public function test_global_css_is_not_scoped(): void {
		$out = ( new CSSValidator() )->validate( 'body{margin:0}', true );
		$this->assertTrue( $out['valid'] );
		$this->assertSame( 'body{margin:0}', $out['css'] );
	}

	/** The same invisible trick, in a stylesheet. */
	public function test_a_control_character_is_refused(): void {
		$result = ( new CSSValidator() )->validate( ".a{color:red}\x00@import url('https://evil.test/x.css');" );

		$this->assertNotSame( array(), $result['errors'] );
	}

	public function test_a_scheme_split_by_whitespace_in_a_url_is_refused(): void {
		foreach ( array( "java\tscript:alert(1)", "java\nscript:alert(1)", "  javascript:alert(1)" ) as $url ) {
			$result = ( new CSSValidator() )->validate( sprintf( '.a{background:url("%s")}', $url ) );

			$this->assertNotSame( array(), $result['errors'], rawurlencode( $url ) );
		}
	}

	public function test_an_ordinary_url_still_works(): void {
		$result = ( new CSSValidator() )->validate( '.a{background:url("/wp-content/uploads/x.png")}' );

		$this->assertSame( array(), $result['errors'] );
	}
}
