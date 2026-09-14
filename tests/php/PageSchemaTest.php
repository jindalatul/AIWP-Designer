<?php
declare( strict_types = 1 );

use AIWP\Designer\ACF\FieldKeyGenerator;
use AIWP\Designer\Pages\PageSchema;
use PHPUnit\Framework\TestCase;

final class PageSchemaTest extends TestCase {

	private function schema(): PageSchema {
		return PageSchema::from_array(
			array(
				array(
					'id'     => 'Hero Section',
					'label'  => 'Hero',
					'fields' => array(
						array( 'id' => 'headline', 'name' => 'Head Line', 'label' => 'Headline', 'type' => 'text' ),
						array( 'id' => 'evil', 'name' => 'evil', 'label' => 'Evil', 'type' => 'php_exec' ),
						array(
							'id'         => 'cards',
							'name'       => 'cards',
							'label'      => 'Cards',
							'type'       => 'aiwp_repeater',
							'sub_fields' => array(
								array( 'id' => 'title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
								array( 'id' => 'nested', 'name' => 'nested', 'label' => 'Nested', 'type' => 'aiwp_repeater' ),
							),
						),
					),
				),
			)
		);
	}

	public function test_section_and_field_names_are_normalised(): void {
		$schema = $this->schema();
		$this->assertTrue( $schema->has_field( 'hero_section.head_line' ) );
	}

	public function test_unknown_field_type_falls_back_to_text(): void {
		$this->assertSame( 'text', $this->schema()->field( 'hero_section.evil' )['type'] );
	}

	public function test_repeaters_cannot_nest(): void {
		$field = $this->schema()->field( 'hero_section.cards' );
		$this->assertSame( 'text', $field['sub_fields']['nested']['type'] );
	}

	public function test_is_repeater_and_subfields(): void {
		$schema = $this->schema();
		$this->assertTrue( $schema->is_repeater( 'hero_section.cards' ) );
		$this->assertFalse( $schema->is_repeater( 'hero_section.head_line' ) );
		$this->assertTrue( $schema->has_subfield( 'hero_section.cards', 'title' ) );
		$this->assertFalse( $schema->has_subfield( 'hero_section.cards', 'nope' ) );
	}

	public function test_acf_name_mapping(): void {
		$this->assertSame( 'hero_headline', PageSchema::acf_name( 'hero.headline' ) );
		$this->assertSame( 'benefits_cards', PageSchema::acf_name( 'Benefits.Cards' ) );
	}

	public function test_empty_repeater_is_an_error(): void {
		$schema = PageSchema::from_array(
			array(
				array(
					'id'     => 'a',
					'fields' => array( array( 'id' => 'r', 'name' => 'r', 'type' => 'aiwp_repeater' ) ),
				),
			)
		);
		$this->assertNotEmpty( $schema->errors() );
	}

	public function test_field_keys_are_stable_and_unique(): void {
		$uuid = '11111111-2222-4333-8444-555555555555';
		$a    = FieldKeyGenerator::field_key( $uuid, 'hero_headline' );
		$b    = FieldKeyGenerator::field_key( $uuid, 'hero_headline' );
		$c    = FieldKeyGenerator::field_key( $uuid, 'faq_headline' );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertStringStartsWith( 'field_aiwp_', $a );
	}
}
