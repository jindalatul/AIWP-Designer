<?php
declare( strict_types = 1 );

use AIWP\Designer\Pages\PageSchema;
use PHPUnit\Framework\TestCase;

/**
 * A site owner can add their own fields. The AI sends the whole section list on
 * every update, so the only thing standing between their work and a redesign is
 * the owner flag surviving and the merge honouring it.
 */
final class OwnerSectionsTest extends TestCase {

	private function schema(): PageSchema {
		return PageSchema::from_array(
			array(
				array( 'id' => 'hero', 'label' => 'Hero', 'fields' => array(
					array( 'id' => 'h1', 'name' => 'h1', 'label' => 'H1', 'type' => 'text' ),
				) ),
				array( 'id' => 'hours', 'label' => 'Opening hours', 'owner' => true, 'fields' => array(
					array( 'id' => 'weekdays', 'name' => 'weekdays', 'label' => 'Weekdays', 'type' => 'text' ),
				) ),
			)
		);
	}

	public function test_the_owner_flag_survives_storage(): void {
		$stored = $this->schema()->to_array();
		$again  = PageSchema::from_array( $stored );

		$this->assertSame( array( 'hours' ), array_column( $again->owner_sections(), 'id' ) );
	}

	public function test_owner_and_ai_sections_are_told_apart(): void {
		$this->assertSame( array( 'hero' ), array_column( $this->schema()->ai_sections(), 'id' ) );
		$this->assertSame( array( 'hours' ), array_column( $this->schema()->owner_sections(), 'id' ) );
		$this->assertTrue( $this->schema()->has_owner_sections() );
	}

	public function test_a_schema_with_none_says_so(): void {
		$plain = PageSchema::from_array(
			array( array( 'id' => 'hero', 'label' => 'Hero', 'fields' => array(
				array( 'id' => 'h1', 'name' => 'h1', 'label' => 'H1', 'type' => 'text' ),
			) ) )
		);

		$this->assertFalse( $plain->has_owner_sections() );
	}

	public function test_an_ai_update_that_omits_them_cannot_delete_them(): void {
		$incoming = array(
			array( 'id' => 'hero', 'label' => 'Hero rewritten', 'fields' => array(
				array( 'id' => 'h1', 'name' => 'h1', 'label' => 'H1', 'type' => 'text' ),
			) ),
		);

		$merged = $this->schema()->keeping_owner_sections( $incoming );
		$ids    = array_column( $merged->to_array(), 'id' );

		$this->assertContains( 'hours', $ids );
		$this->assertContains( 'hero', $ids );
	}

	public function test_the_ai_may_still_change_its_own_sections(): void {
		$incoming = array(
			array( 'id' => 'hero', 'label' => 'Hero rewritten', 'fields' => array(
				array( 'id' => 'h1', 'name' => 'h1', 'label' => 'Headline', 'type' => 'text' ),
			) ),
		);

		$merged = $this->schema()->keeping_owner_sections( $incoming );
		$hero   = array_values( array_filter( $merged->to_array(), static fn( $s ) => 'hero' === $s['id'] ) )[0];

		$this->assertSame( 'Hero rewritten', $hero['label'] );
	}

	public function test_an_id_clash_resolves_in_the_owners_favour(): void {
		$incoming = array(
			array( 'id' => 'hours', 'label' => 'The AI version', 'fields' => array(
				array( 'id' => 'x', 'name' => 'x', 'label' => 'X', 'type' => 'text' ),
			) ),
		);

		$merged = $this->schema()->keeping_owner_sections( $incoming );
		$hours  = array_values( array_filter( $merged->to_array(), static fn( $s ) => 'hours' === $s['id'] ) );

		$this->assertCount( 1, $hours );
		$this->assertSame( 'Opening hours', $hours[0]['label'] );
		$this->assertTrue( $hours[0]['owner'] );
	}

	public function test_the_owner_can_still_remove_their_own_section(): void {
		// Removal happens by rebuilding the schema without it, which is what the
		// admin screen does. Nothing in the merge should put it back.
		$without = PageSchema::from_array(
			array_values( array_filter( $this->schema()->to_array(), static fn( $s ) => 'hours' !== $s['id'] ) )
		);

		$this->assertFalse( $without->has_owner_sections() );
	}
}
