<?php
declare( strict_types = 1 );

use AIWP\Designer\ACF\FieldKeyGenerator;
use AIWP\Designer\Pages\PageSchema;
use PHPUnit\Framework\TestCase;

/**
 * Two different fields that are stored in one place.
 *
 * "hero" with a field "sub_title" and "hero_sub" with a field "title" both
 * come out as hero_sub_title: the dot between the two halves becomes an
 * underscore, and the ACF key drops separators entirely. Whoever filled in the
 * second box would quietly wipe the first. Ids are [a-z0-9_], so there is no
 * spare character to separate with — the pair has to be refused instead.
 */
final class KeyCollisionTest extends TestCase {

	/** @param array<int,array<string,string>> $pairs */
	private function schema_of( array $pairs ): PageSchema {
		$sections = array();

		foreach ( $pairs as $pair ) {
			$sections[] = array(
				'id'     => $pair['section'],
				'label'  => $pair['section'],
				'fields' => array(
					array( 'id' => $pair['field'], 'name' => $pair['field'], 'label' => $pair['field'], 'type' => 'text' ),
				),
			);
		}

		return PageSchema::from_array( $sections );
	}

	public function test_the_two_halves_really_do_flatten_to_one_key(): void {
		$this->assertSame(
			PageSchema::acf_name( 'hero_sub.title' ),
			PageSchema::acf_name( 'hero.sub_title' )
		);

		$this->assertSame(
			FieldKeyGenerator::fingerprint( 'hero_sub', 'title' ),
			FieldKeyGenerator::fingerprint( 'hero', 'sub_title' )
		);
	}

	public function test_a_schema_that_would_overwrite_itself_is_refused(): void {
		$errors = $this->schema_of(
			array(
				array( 'section' => 'hero', 'field' => 'sub_title' ),
				array( 'section' => 'hero_sub', 'field' => 'title' ),
			)
		)->errors();

		$this->assertNotSame( array(), $errors );
		$this->assertStringContainsString( 'hero.sub_title', $errors[0] );
		$this->assertStringContainsString( 'hero_sub.title', $errors[0] );
		$this->assertStringContainsString( 'overwrite', $errors[0] );
	}

	public function test_the_same_trap_inside_one_section(): void {
		$schema = PageSchema::from_array(
			array(
				array(
					'id'     => 'a_b',
					'label'  => 'A B',
					'fields' => array(
						array( 'id' => 'c_d', 'name' => 'c_d', 'label' => 'C D', 'type' => 'text' ),
					),
				),
				array(
					'id'     => 'a',
					'label'  => 'A',
					'fields' => array(
						array( 'id' => 'b_c_d', 'name' => 'b_c_d', 'label' => 'B C D', 'type' => 'text' ),
					),
				),
			)
		);

		$this->assertNotSame( array(), $schema->errors() );
	}

	public function test_a_repeating_row_can_collide_too(): void {
		$schema = PageSchema::from_array(
			array(
				array(
					'id'     => 'hours',
					'label'  => 'Hours',
					'fields' => array(
						array(
							'id'         => 'days',
							'name'       => 'days',
							'label'      => 'Days',
							'type'       => 'aiwp_repeater',
							'sub_fields' => array(
								array( 'id' => 'open_at', 'name' => 'open_at', 'label' => 'Open at', 'type' => 'text' ),
								array( 'id' => 'openat', 'name' => 'openat', 'label' => 'Open at', 'type' => 'text' ),
							),
						),
					),
				),
			)
		);

		$errors = $schema->errors();
		$this->assertNotSame( array(), $errors );
		$this->assertStringContainsString( 'hours.days.openat', implode( ' ', $errors ) );
	}

	public function test_ordinary_fields_are_left_alone(): void {
		$schema = PageSchema::from_array(
			array(
				array(
					'id'     => 'hero',
					'label'  => 'Hero',
					'fields' => array(
						array( 'id' => 'headline', 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ),
						array( 'id' => 'sub_title', 'name' => 'sub_title', 'label' => 'Sub title', 'type' => 'text' ),
					),
				),
				array(
					'id'     => 'contact',
					'label'  => 'Contact',
					'fields' => array(
						array( 'id' => 'headline', 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ),
					),
				),
				array(
					'id'     => 'contact_form',
					'label'  => 'Contact form',
					'fields' => array(
						array( 'id' => 'heading', 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ),
					),
				),
			)
		);

		$this->assertSame( array(), $schema->errors() );
	}

	public function test_a_name_that_only_looks_similar_is_fine(): void {
		$this->assertNotSame(
			FieldKeyGenerator::fingerprint( 'hero', 'subtitle' ),
			FieldKeyGenerator::fingerprint( 'hero', 'sub_titles' )
		);
	}
}
