<?php
declare( strict_types = 1 );

use AIWP\Designer\Articles\ArchiveContent;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Template\TemplateValidator;
use PHPUnit\Framework\TestCase;

/**
 * The archive schema is what tells the validator which archive.* paths exist.
 * If it drifts from what the renderer supplies, templates fail at save time
 * for paths that are real, or pass for paths that are not.
 */
final class ArchiveContentTest extends TestCase {

	private function schema(): PageSchema {
		return PageSchema::from_array( ArchiveContent::schema_sections() );
	}

	private function validate( string $template ): array {
		return ( new TemplateValidator() )->validate( $template, $this->schema() );
	}

	public function test_the_paths_a_listing_needs_are_all_declared(): void {
		foreach ( array( 'title', 'kind', 'count', 'page', 'pages', 'empty', 'paginated', 'newer_url', 'older_url' ) as $field ) {
			$this->assertNotNull(
				$this->schema()->field( 'archive.' . $field ),
				'archive.' . $field . ' is missing from the schema'
			);
		}
	}

	public function test_items_is_a_repeater_with_the_fields_a_row_needs(): void {
		$this->assertTrue( $this->schema()->is_repeater( 'archive.items' ) );

		foreach ( array( 'title', 'url', 'excerpt', 'date', 'reading_time', 'category' ) as $sub ) {
			$this->assertTrue(
				$this->schema()->has_subfield( 'archive.items', $sub ),
				'archive.items.' . $sub . ' is missing'
			);
		}
	}

	public function test_a_normal_listing_template_validates(): void {
		$template = '<main>'
			. '<h1>{{text:archive.title}}</h1>'
			. '{{#if:archive.empty}}<p>Nothing here.</p>{{/if}}'
			. '<ol>{{#each:archive.items}}<li>'
			. '<a href="{{attr:@item.url}}">{{text:@item.title}}</a>'
			. '<p>{{text:@item.excerpt}}</p>'
			. '<span>{{text:@item.reading_time}}</span>'
			. '</li>{{/each}}</ol>'
			. '{{#if:archive.paginated}}<a href="{{attr:archive.older_url}}">Older</a>{{/if}}'
			. '</main>';

		$result = $this->validate( $template );
		$this->assertTrue( $result['valid'], implode( ' | ', $result['errors'] ) );
	}

	public function test_a_path_that_does_not_exist_is_refused(): void {
		$result = $this->validate( '<main><p>{{text:archive.subtitle}}</p></main>' );
		$this->assertFalse( $result['valid'] );
	}

	public function test_categories_carry_a_current_flag_for_highlighting(): void {
		$this->assertTrue( $this->schema()->has_subfield( 'archive.categories', 'is_current' ) );
	}
}
