<?php
declare( strict_types = 1 );

use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Template\TemplateValidator;
use PHPUnit\Framework\TestCase;

final class TemplateValidatorTest extends TestCase {

	private function schema(): PageSchema {
		return PageSchema::from_array(
			array(
				array(
					'id'     => 'hero',
					'label'  => 'Hero',
					'fields' => array(
						array( 'id' => 'headline', 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ),
					),
				),
				array(
					'id'     => 'benefits',
					'label'  => 'Benefits',
					'fields' => array(
						array(
							'id'         => 'cards',
							'name'       => 'cards',
							'label'      => 'Cards',
							'type'       => 'aiwp_repeater',
							'sub_fields' => array(
								array( 'id' => 'title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
							),
						),
					),
				),
			)
		);
	}

	private function validate( string $template, bool $with_schema = false ): array {
		return ( new TemplateValidator() )->validate( $template, $with_schema ? $this->schema() : null );
	}

	/**
	 * @dataProvider malicious_templates
	 */
	public function test_malicious_templates_are_rejected( string $template ): void {
		$result = $this->validate( $template );
		$this->assertFalse( $result['valid'], 'Should have been rejected: ' . $template );
	}

	public static function malicious_templates(): array {
		return array(
			'php open tag'       => array( '<main><?php system("ls"); ?></main>' ),
			'php short echo'     => array( '<main><?= $x ?></main>' ),
			'script tag'         => array( '<main><script>alert(1)</script></main>' ),
			'script uppercase'   => array( '<main><SCRIPT>alert(1)</SCRIPT></main>' ),
			'onclick'            => array( '<main><button onclick="x()">go</button></main>' ),
			'onerror'            => array( '<main><img src="x" onerror="alert(1)"></main>' ),
			'onload'             => array( '<main><div onload="x()"></div></main>' ),
			'javascript url'     => array( '<main><a href="javascript:alert(1)">x</a></main>' ),
			'vbscript url'       => array( '<main><a href="vbscript:msgbox">x</a></main>' ),
			'iframe'             => array( '<main><iframe src="https://evil.test"></iframe></main>' ),
			'object'             => array( '<main><object data="x.swf"></object></main>' ),
			'embed'              => array( '<main><embed src="x"></main>' ),
			'form'               => array( '<main><form action="/x"><input name="a"></form></main>' ),
			'style tag'          => array( '<main><style>body{display:none}</style></main>' ),
			'link tag'           => array( '<main><link rel="stylesheet" href="https://evil.test/x.css"></main>' ),
			'meta refresh'       => array( '<main><meta http-equiv="refresh" content="0;url=https://evil.test"></main>' ),
			'data html uri'      => array( '<main><a href="data:text/html,<script>alert(1)</script>">x</a></main>' ),
			'disallowed element' => array( '<main><marquee>hi</marquee></main>' ),
			'empty template'     => array( '' ),
		);
	}

	public function test_clean_semantic_markup_passes(): void {
		$result = $this->validate( '<main><section class="a"><h1>Hello</h1><p>Text</p></section></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_data_aiwp_and_aria_attributes_are_allowed(): void {
		$result = $this->validate( '<main><div data-aiwp-behavior="accordion" aria-label="FAQ"></div></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_unknown_behavior_is_rejected(): void {
		$result = $this->validate( '<main><div data-aiwp-behavior="mindcontrol"></div></main>' );
		$this->assertFalse( $result['valid'] );
	}

	public function test_unknown_field_reference_is_rejected(): void {
		$result = $this->validate( '<main>{{text:hero.nope}}</main>', true );
		$this->assertFalse( $result['valid'] );
	}

	public function test_known_field_reference_passes(): void {
		$result = $this->validate( '<main><h1>{{text:hero.headline}}</h1></main>', true );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_each_over_a_non_repeater_is_rejected(): void {
		$result = $this->validate( '<main>{{#each:hero.headline}}x{{/each}}</main>', true );
		$this->assertFalse( $result['valid'] );
	}

	public function test_unknown_repeater_subfield_is_rejected(): void {
		$result = $this->validate( '<main>{{#each:benefits.cards}}{{text:@item.nope}}{{/each}}</main>', true );
		$this->assertFalse( $result['valid'] );
	}

	public function test_known_repeater_subfield_passes(): void {
		$result = $this->validate( '<main>{{#each:benefits.cards}}<h3>{{text:@item.title}}</h3>{{/each}}</main>', true );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_item_outside_each_is_rejected(): void {
		$result = $this->validate( '<main>{{text:@item.title}}</main>', true );
		$this->assertFalse( $result['valid'] );
	}

	public function test_accessible_table_markup_is_allowed(): void {
		$result = $this->validate(
			'<main><table><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><th scope="row">B</th><td>C</td></tr></tbody></table></main>'
		);
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_inline_svg_is_allowed(): void {
		$result = $this->validate( '<main><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16" stroke-width="2"></path></svg></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_a_style_attribute_is_still_refused(): void {
		$result = $this->validate( '<main><div style="position:fixed">x</div></main>' );
		$this->assertFalse( $result['valid'] );
	}

	public function test_missing_alt_is_a_warning_not_an_error(): void {
		$result = $this->validate( '<main><img src="/a.jpg"></main>' );
		$this->assertTrue( $result['valid'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_oversized_template_is_rejected(): void {
		$result = $this->validate( '<main>' . str_repeat( 'x', 600000 ) . '</main>' );
		$this->assertFalse( $result['valid'] );
	}

	public function test_a_missing_closing_tag_is_an_error(): void {
		$result = $this->validate( "<main>\n\t<div>\n\t\t<p>hi</p>\n</main>" );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'never closed', implode( ' | ', $result['errors'] ) );
	}

	public function test_the_error_names_the_line_the_tag_was_opened_on(): void {
		$result = $this->validate( "<main>\n\t<div>\n\t\t<p>hi</p>\n</main>" );
		$this->assertStringContainsString( 'line 2', implode( ' | ', $result['errors'] ) );
	}

	public function test_a_closing_tag_with_no_opening_tag_is_an_error(): void {
		$result = $this->validate( "<main>\n<p>hi</p>\n</div>\n</main>" );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'never opened', implode( ' | ', $result['errors'] ) );
	}

	public function test_void_tags_need_no_closing_tag(): void {
		$result = $this->validate( '<main><p>a<br>b</p><hr><img src="/a.jpg" alt="a"></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_self_closing_svg_children_are_balanced(): void {
		$result = $this->validate( '<main><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16"/><circle cx="4" cy="4" r="2"/></svg></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_a_tag_inside_a_comment_does_not_count(): void {
		$result = $this->validate( "<main>\n<!-- <div> a note -->\n<p>hi</p>\n</main>" );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_a_greater_than_sign_inside_an_attribute_does_not_break_the_count(): void {
		$result = $this->validate( '<main><div title="a > b"><p>hi</p></div></main>' );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_directives_spanning_lines_keep_the_line_numbers_right(): void {
		$result = $this->validate( "<main>\n{{#each:items}}\n<p>{{text:items.name}}</p>\n{{/each}}\n<div>\n</main>" );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'line 5', implode( ' | ', $result['errors'] ) );
	}

	public function test_html_on_a_plain_text_field_is_refused(): void {
		$schema = PageSchema::from_array(
			array( array( 'id' => 'foot', 'label' => 'Foot', 'fields' => array(
				array( 'id' => 'links', 'name' => 'links', 'label' => 'Links', 'type' => 'textarea' ),
			) ) )
		);

		$result = ( new TemplateValidator() )->validate( '<main><p>{{html:foot.links}}</p></main>', $schema );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'print nothing', implode( ' | ', $result['errors'] ) );
	}

	public function test_html_on_a_wysiwyg_field_is_fine(): void {
		$schema = PageSchema::from_array(
			array( array( 'id' => 'body', 'label' => 'Body', 'fields' => array(
				array( 'id' => 'rich', 'name' => 'rich', 'label' => 'Rich', 'type' => 'wysiwyg' ),
			) ) )
		);

		$result = ( new TemplateValidator() )->validate( '<main><div>{{html:body.rich}}</div></main>', $schema );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_html_on_a_plain_subfield_is_refused(): void {
		$schema = PageSchema::from_array(
			array( array( 'id' => 'foot', 'label' => 'Foot', 'fields' => array(
				array( 'id' => 'cols', 'name' => 'cols', 'label' => 'Cols', 'type' => 'aiwp_repeater',
					'sub_fields' => array(
						array( 'id' => 'links', 'name' => 'links', 'label' => 'Links', 'type' => 'textarea' ),
					) ),
			) ) )
		);

		$result = ( new TemplateValidator() )->validate(
			'<main>{{#each:foot.cols}}<p>{{html:@item.links}}</p>{{/each}}</main>',
			$schema
		);

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * A browser throws tabs and newlines out of a URL before it decides what
	 * the scheme is, so "java&#9;script:" runs. Checking the raw string reads a
	 * different URL from the one that gets followed.
	 */
	public function test_a_scheme_split_by_a_control_character_is_still_refused(): void {
		$hidden = array(
			"java\tscript:alert(1)",
			"java\nscript:alert(1)",
			"java\rscript:alert(1)",
			"jav\x00ascript:alert(1)",
			" javascript:alert(1)",
			"JAVA\tSCRIPT:alert(1)",
			"vb\tscript:alert(1)",
		);

		foreach ( $hidden as $url ) {
			$result = $this->validate( sprintf( '<main><a href="%s">x</a></main>', $url ) );
			$this->assertFalse( $result['valid'], 'Should have been refused: ' . $url );
		}
	}

	public function test_an_ordinary_link_still_works(): void {
		foreach ( array( 'https://example.com/x', 'http://example.com', '/about/', '#top', '?s=x', 'mailto:a@b.test', 'tel:+15551234' ) as $url ) {
			$result = $this->validate( sprintf( '<main><a href="%s">x</a></main>', $url ) );
			$this->assertTrue( $result['valid'], $url . ' => ' . implode( ' | ', (array) $result['errors'] ) );
		}
	}

	public function test_a_scheme_nobody_thought_of_is_refused(): void {
		foreach ( array( 'file:///etc/passwd', 'ftp://evil.test/x', 'chrome://settings' ) as $url ) {
			$result = $this->validate( sprintf( '<main><a href="%s">x</a></main>', $url ) );
			$this->assertFalse( $result['valid'], 'Should have been refused: ' . $url );
		}
	}

	/**
	 * The assumption the whole class rests on: what is inspected here is what
	 * the browser will get.
	 *
	 * DOMDocument truncates an attribute at the first null byte, so
	 * href="jav\0ascript:alert(1)" used to arrive as href="jav" and pass. The
	 * renderer prints the template as written, the browser drops the null, and
	 * the link runs.
	 */
	public function test_a_control_character_is_refused_outright(): void {
		$sneaky = array(
			"<main><a href=\"jav\x00ascript:alert(1)\">x</a></main>",
			"<main><p>hello\x0bworld</p></main>",
			"<main>\x7f<p>x</p></main>",
			"<main><p>a\x1bb</p></main>",
		);

		foreach ( $sneaky as $template ) {
			$result = $this->validate( $template );
			$this->assertFalse( $result['valid'], 'Should have been refused: ' . rawurlencode( $template ) );
		}
	}

	public function test_tabs_and_line_breaks_are_ordinary_formatting(): void {
		$result = $this->validate( "<main>\n\t<h1>A</h1>\r\n\t<p>b</p>\n</main>" );

		$this->assertTrue( $result['valid'], implode( ' | ', (array) $result['errors'] ) );
	}

	/**
	 * Inside an svg, <title> is the accessible name. Blocking it outright
	 * made every diagram on every site unreachable to a screen reader, and
	 * left aria-label as the only option, which is weaker.
	 */
	public function test_svg_title_and_desc_are_allowed(): void {
		$template = '<main><svg viewBox="0 0 10 10" role="img" aria-labelledby="t d">'
			. '<title id="t">A heating system</title><desc id="d">Boiler, cylinder, radiators.</desc>'
			. '<rect x="1" y="1" width="8" height="8"/></svg></main>';

		$this->assertTrue( $this->validate( $template )['valid'], implode( ' | ', (array) $this->validate( $template )['errors'] ) );
	}

	public function test_a_title_outside_an_svg_is_still_refused(): void {
		$this->assertFalse( $this->validate( '<main><title>Page title</title><h1>A</h1></main>' )['valid'] );
	}

	public function test_a_desc_outside_an_svg_is_still_refused(): void {
		$this->assertFalse( $this->validate( '<main><desc>Something</desc></main>' )['valid'] );
	}

	/**
	 * A dialog has to be able to take focus.
	 *
	 * The modal behavior focuses the dialog when it holds nothing focusable,
	 * and the focus trap falls back to it. role="dialog" plus aria-modal is a
	 * promise, and without tabindex="-1" the keyboard cannot keep it. Any other
	 * value rewrites the tab order of the whole page, so only "-1" is allowed.
	 */
	public function test_a_dialog_may_take_focus_out_of_the_tab_order(): void {
		$result = ( new TemplateValidator() )->validate(
			'<div data-aiwp-behavior="modal"><button data-aiwp-modal-open>Menu</button>'
			. '<div data-aiwp-modal-dialog tabindex="-1"><a href="/about/">About</a></div></div>',
			$this->schema()
		);

		$this->assertSame( array(), $result['errors'] );
	}

	public function test_a_template_cannot_reorder_the_page_with_tabindex(): void {
		$result = ( new TemplateValidator() )->validate(
			'<div tabindex="3">Jump the queue</div>',
			$this->schema()
		);

		$this->assertNotSame( array(), $result['errors'] );
	}
}
