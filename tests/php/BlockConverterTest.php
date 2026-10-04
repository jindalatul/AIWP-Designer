<?php
declare( strict_types = 1 );

use AIWP\Designer\Articles\BlockConverter;
use AIWP\Designer\Articles\MarkdownToHtml;
use PHPUnit\Framework\TestCase;

final class BlockConverterTest extends TestCase {

	private function convert( string $html, ?callable $images = null ): array {
		return ( new BlockConverter( $images ) )->convert( $html );
	}

	public function test_paragraph_and_heading(): void {
		$r = $this->convert( '<h2>What it is</h2><p>Plain <strong>bold</strong> and <em>soft</em>.</p>' );
		$this->assertSame(
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">What it is</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:paragraph -->\n<p>Plain <strong>bold</strong> and <em>soft</em>.</p>\n<!-- /wp:paragraph -->",
			$r['content']
		);
		$this->assertSame( 2, $r['blocks'] );
	}

	public function test_heading_levels_carry_an_attribute_except_two(): void {
		$r = $this->convert( '<h3>Three</h3><h4>Four</h4>' );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $r['content'] );
		$this->assertStringContainsString( '<h4 class="wp-block-heading">Four</h4>', $r['content'] );
	}

	public function test_h1_becomes_h2_with_one_warning(): void {
		$r = $this->convert( '<h1>Title</h1><h1>Again</h1>' );
		$this->assertStringNotContainsString( '<h1', $r['content'] );
		$this->assertSame( 2, substr_count( $r['content'], '<h2 class="wp-block-heading">' ) );
		$this->assertCount( 1, $r['warnings'] );
	}

	public function test_lists_use_list_item_blocks_and_nest(): void {
		$r = $this->convert( '<ol><li>One</li><li>Two<ul><li>Two a</li></ul></li></ol>' );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $r['content'] );
		$this->assertStringContainsString( '<ol class="wp-block-list">', $r['content'] );
		$this->assertStringContainsString( "<!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->", $r['content'] );
		$this->assertStringContainsString( '<li>Two<!-- wp:list -->', $r['content'] );
		$this->assertStringContainsString( '<ul class="wp-block-list">', $r['content'] );
	}

	public function test_quote_wraps_paragraphs_and_keeps_cite(): void {
		$r = $this->convert( '<blockquote><p>Said well.</p><cite>Anna</cite></blockquote>' );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote"><!-- wp:paragraph -->', $r['content'] );
		$this->assertStringContainsString( '<cite>Anna</cite></blockquote>', $r['content'] );
	}

	public function test_table_gets_header_and_body(): void {
		$r = $this->convert( '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>' );
		$this->assertStringContainsString( '<!-- wp:table {"hasFixedLayout":false} -->', $r['content'] );
		$this->assertStringContainsString( '<figure class="wp-block-table"><table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table></figure>', $r['content'] );
	}

	public function test_image_from_library_becomes_image_block(): void {
		$lib = fn( string $src ) => 'https://site.test/a.jpg' === $src ? array( 'id' => 42, 'url' => 'https://site.test/a-1024.jpg' ) : null;
		$r   = $this->convert( '<figure><img src="https://site.test/a.jpg" alt="A chart"><figcaption>The chart</figcaption></figure>', $lib );
		$this->assertStringContainsString( '<!-- wp:image {"id":42,"sizeSlug":"large","linkDestination":"none"} -->', $r['content'] );
		$this->assertStringContainsString( '<img src="https://site.test/a-1024.jpg" alt="A chart" class="wp-image-42"/>', $r['content'] );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">The chart</figcaption>', $r['content'] );
	}

	public function test_image_outside_library_is_left_out_with_a_warning(): void {
		$r = $this->convert( '<p><img src="https://elsewhere.test/x.png" alt="x"></p>', fn() => null );
		$this->assertSame( '', $r['content'] );
		$this->assertStringContainsString( 'media_import', $r['warnings'][0] );
	}

	public function test_scripts_and_wrappers(): void {
		$r = $this->convert( '<div><section><p>Kept</p><script>alert(1)</script></section></div>' );
		$this->assertStringNotContainsString( 'alert', $r['content'] );
		$this->assertStringContainsString( '<p>Kept</p>', $r['content'] );
	}

	public function test_loose_text_becomes_a_paragraph(): void {
		$r = $this->convert( 'Just a line with <a href="/x">a link</a>.' );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>Just a line with <a href=\"/x\">a link</a>.</p>\n<!-- /wp:paragraph -->", $r['content'] );
	}

	public function test_bad_links_lose_their_href(): void {
		$r = $this->convert( '<p><a href="javascript:alert(1)">x</a></p>' );
		$this->assertStringNotContainsString( 'javascript', $r['content'] );
	}

	public function test_separator_and_code(): void {
		$r = $this->convert( '<hr><pre><code>a < b</code></pre>' );
		$this->assertStringContainsString( '<hr class="wp-block-separator has-alpha-channel-opacity"/>', $r['content'] );
		$this->assertStringContainsString( '<pre class="wp-block-code"><code>a &lt; b</code></pre>', $r['content'] );
	}

	public function test_unknown_element_keeps_its_words(): void {
		$r = $this->convert( '<dl><dt>Term</dt><dd>Meaning</dd></dl>' );
		$this->assertStringContainsString( '<p>Term Meaning</p>', $r['content'] );
		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_every_block_comment_is_closed(): void {
		$r = $this->convert( ( new MarkdownToHtml() )->convert( "## A\n\nText\n\n- x\n- y\n\n> q\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n---\n\n```\ncode\n```" ) );
		preg_match_all( '/<!-- wp:([a-z-]+)/', $r['content'], $open );
		preg_match_all( '/<!-- \/wp:([a-z-]+)/', $r['content'], $close );
		sort( $open[1] );
		sort( $close[1] );
		$this->assertSame( $open[1], $close[1] );
	}

	// ---- Markdown

	public function test_markdown_basics(): void {
		$html = ( new MarkdownToHtml() )->convert( "# Title\n\nSome **bold**, *soft* and `code` with a [link](https://x.test).\n\n1. one\n2. two\n   - two a\n\n---" );
		$this->assertStringContainsString( '<h1>Title</h1>', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>soft</em>', $html );
		$this->assertStringContainsString( '<code>code</code>', $html );
		$this->assertStringContainsString( '<a href="https://x.test">link</a>', $html );
		$this->assertStringContainsString( '<ol><li>one</li><li>two<ul><li>two a</li></ul></li></ol>', $html );
		$this->assertStringContainsString( '<hr>', $html );
	}

	public function test_markdown_escapes_html_in_text(): void {
		$html = ( new MarkdownToHtml() )->convert( 'Use <script> carefully & safely' );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_markdown_links_bare_addresses_but_not_twice(): void {
		$html = ( new MarkdownToHtml() )->convert( 'See https://www.sec.gov/edgar?a=1&b=2. Or [the guide](https://x.test/guide).' );
		$this->assertStringContainsString( '<a href="https://www.sec.gov/edgar?a=1&amp;b=2">https://www.sec.gov/edgar?a=1&amp;b=2</a>.', $html );
		$this->assertSame( 1, substr_count( $html, 'href="https://x.test/guide"' ) );
	}

	public function test_markdown_table_and_quote(): void {
		$html = ( new MarkdownToHtml() )->convert( "| A | B |\n|---|---|\n| 1 | 2 |\n\n> Quoted line" );
		$this->assertStringContainsString( '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>', $html );
		$this->assertStringContainsString( '<blockquote><p>Quoted line</p></blockquote>', $html );
	}
}
