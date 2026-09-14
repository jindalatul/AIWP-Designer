<?php
declare( strict_types = 1 );

use AIWP\Designer\Template\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase {

	private const CONTENT = array(
		'hero'     => array(
			'headline' => 'Buy <b>your</b> first home',
			'alt'      => 'A "quoted" alt',
			'link'     => 'https://example.com/apply',
			'body'     => '<p>Rich <script>alert(1)</script>text</p>',
			'image'    => 42,
			'flag'     => '',
		),
		'benefits' => array(
			'cards' => array(
				array( 'title' => 'One', 'image' => 7 ),
				array( 'title' => 'Two', 'image' => 0 ),
			),
			'empty' => array(),
		),
	);

	private function render( string $template ): string {
		return ( new TemplateEngine() )->render_source( $template, self::CONTENT );
	}

	public function test_text_is_html_escaped(): void {
		$this->assertSame( 'Buy &lt;b&gt;your&lt;/b&gt; first home', $this->render( '{{text:hero.headline}}' ) );
	}

	public function test_attr_is_attribute_escaped(): void {
		$this->assertStringContainsString( '&quot;quoted&quot;', $this->render( '{{attr:hero.alt}}' ) );
	}

	public function test_url_is_url_escaped(): void {
		$this->assertSame( 'https://example.com/apply', $this->render( '{{url:hero.link}}' ) );
	}

	public function test_html_filter_strips_scripts(): void {
		$out = $this->render( '{{html:hero.body}}' );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( '<p>', $out );
	}

	public function test_image_url_resolves_an_attachment_id(): void {
		$this->assertSame( 'http://example.test/wp-content/uploads/image-42.jpg', $this->render( '{{image_url:hero.image}}' ) );
	}

	public function test_if_true(): void {
		$this->assertSame( 'yes', $this->render( '{{#if:hero.headline}}yes{{/if}}' ) );
	}

	public function test_if_false_on_empty_string(): void {
		$this->assertSame( '', $this->render( '{{#if:hero.flag}}yes{{/if}}' ) );
	}

	public function test_if_false_on_missing_field(): void {
		$this->assertSame( '', $this->render( '{{#if:hero.nope}}yes{{/if}}' ) );
	}

	public function test_each_renders_every_row(): void {
		$this->assertSame( '[One][Two]', $this->render( '{{#each:benefits.cards}}[{{text:@item.title}}]{{/each}}' ) );
	}

	public function test_each_with_zero_rows_renders_nothing(): void {
		$this->assertSame( '', $this->render( '{{#each:benefits.empty}}x{{/each}}' ) );
	}

	public function test_if_inside_each_uses_the_current_row(): void {
		$this->assertSame( 'One', $this->render( '{{#each:benefits.cards}}{{#if:@item.image}}{{text:@item.title}}{{/if}}{{/each}}' ) );
	}

	public function test_missing_field_renders_empty_rather_than_failing(): void {
		$this->assertSame( 'a|b', $this->render( 'a|{{text:nowhere.at_all}}b' ) );
	}

	public function test_item_outside_each_renders_empty(): void {
		$this->assertSame( '', $this->render( '{{text:@item.title}}' ) );
	}
}
