<?php
declare( strict_types = 1 );

use AIWP\Designer\Template\FieldReference;
use AIWP\Designer\Template\TemplateParser;
use PHPUnit\Framework\TestCase;

/**
 * The line we show beside a field has to be the line that actually works. If
 * these two ever drift, we are teaching people to write templates that fail.
 */
final class FieldReferenceTest extends TestCase {

	public function test_plain_text_uses_the_text_filter(): void {
		$this->assertSame(
			'{{text:hero.headline}}',
			FieldReference::for_field( 'hero', 'headline', 'text' )
		);
	}

	public function test_formatted_text_uses_html(): void {
		$this->assertSame(
			'{{html:body.intro}}',
			FieldReference::for_field( 'body', 'intro', 'wysiwyg' )
		);
	}

	public function test_an_image_gives_a_url_not_an_id(): void {
		$this->assertSame(
			'{{image_url:hero.photo}}',
			FieldReference::for_field( 'hero', 'photo', 'image' )
		);
	}

	public function test_an_image_also_offers_its_alt_text(): void {
		$this->assertSame(
			'{{image_alt:hero.photo}}',
			FieldReference::also( 'hero', 'photo', 'image' )
		);
	}

	public function test_only_an_image_has_alt_text(): void {
		$this->assertSame( '', FieldReference::also( 'hero', 'headline', 'text' ) );
	}

	public function test_a_type_we_do_not_know_falls_back_to_text(): void {
		$this->assertSame(
			'{{text:hero.mystery}}',
			FieldReference::for_field( 'hero', 'mystery', 'something_new' )
		);
	}

	public function test_a_repeating_list_is_a_whole_block(): void {
		$snippet = FieldReference::snippet(
			'hours',
			'days',
			'aiwp_repeater',
			array(
				array( 'name' => 'day', 'type' => 'text' ),
				array( 'name' => 'shot', 'type' => 'image' ),
			)
		);

		$this->assertSame(
			"{{#each:hours.days}}\n  {{text:@item.day}}\n  {{image_url:@item.shot}}\n{{/each}}",
			$snippet
		);
	}

	public function test_a_sub_field_with_no_name_is_skipped(): void {
		$snippet = FieldReference::snippet(
			'hours',
			'days',
			'aiwp_repeater',
			array( array( 'type' => 'text' ) )
		);

		$this->assertSame( "{{#each:hours.days}}\n{{/each}}", $snippet );
	}

	/**
	 * Every filter we hand out has to be one the parser accepts.
	 */
	public function test_every_reference_we_show_is_a_filter_the_parser_knows(): void {
		$types = array( 'text', 'textarea', 'url', 'email', 'number', 'true_false', 'wysiwyg', 'image', 'file' );

		foreach ( $types as $type ) {
			$reference = FieldReference::for_field( 'sec', 'field', $type );
			$this->assertSame( 1, preg_match( '/^\{\{([a-z_]+):/', $reference, $m ), $type );
			$this->assertContains( $m[1], TemplateParser::FILTERS, $type . ' hands out an unknown filter' );
		}
	}

	/**
	 * The real point of the whole class: what we print beside a field must
	 * parse without errors.
	 */
	public function test_what_we_show_parses_cleanly(): void {
		$parser = new TemplateParser();
		$parser->parse( '<p>' . FieldReference::for_field( 'hero', 'headline', 'text' ) . '</p>' );
		$this->assertSame( array(), $parser->errors() );

		$parser = new TemplateParser();
		$parser->parse(
			'<ul>' . FieldReference::snippet(
				'hours',
				'days',
				'aiwp_repeater',
				array( array( 'name' => 'day', 'type' => 'text' ) )
			) . '</ul>'
		);
		$this->assertSame( array(), $parser->errors() );
	}
}
