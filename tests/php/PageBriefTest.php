<?php
declare( strict_types = 1 );

use AIWP\Designer\SEO\PageBrief;
use PHPUnit\Framework\TestCase;

/**
 * The brief slot takes whatever shape a brief arrives in. ConvertRank sends
 * entities as objects, questions as a list, keywords as a list; a person typing
 * into a box sends lines of text. Both have to land in the same place.
 */
final class PageBriefTest extends TestCase {

	public function test_an_empty_brief_knows_it_is_empty(): void {
		$this->assertTrue( PageBrief::empty()->is_empty() );
	}

	public function test_a_keyword_alone_makes_it_not_empty(): void {
		$this->assertFalse( PageBrief::from_array( array( 'primary_keyword' => 'eb-1a' ) )->is_empty() );
	}

	public function test_entities_sent_as_objects_are_flattened(): void {
		$brief = PageBrief::from_array(
			array(
				'entities' => array(
					array( 'entity_text' => 'self-petition', 'type' => 'central' ),
					array( 'entity_text' => 'premium processing', 'type' => 'attribute' ),
				),
			)
		);

		$this->assertSame( array( 'self-petition', 'premium processing' ), $brief->get( 'entities' ) );
	}

	public function test_a_list_typed_as_lines_becomes_a_list(): void {
		$brief = PageBrief::from_array( array( 'questions' => "Do I need an employer?\nHow long does it take?" ) );

		$this->assertSame(
			array( 'Do I need an employer?', 'How long does it take?' ),
			$brief->get( 'questions' )
		);
	}

	public function test_blank_lines_are_dropped(): void {
		$brief = PageBrief::from_array( array( 'secondary_keywords' => "one\n\n\ntwo\n" ) );
		$this->assertSame( array( 'one', 'two' ), $brief->get( 'secondary_keywords' ) );
	}

	public function test_meta_text_is_capped_rather_than_refused(): void {
		$brief = PageBrief::from_array( array( 'meta_description' => str_repeat( 'x', 500 ) ) );
		$this->assertSame( 320, strlen( (string) $brief->get( 'meta_description' ) ) );
	}

	public function test_a_link_without_a_url_is_dropped(): void {
		$brief = PageBrief::from_array(
			array(
				'internal_links' => array(
					array( 'anchor' => 'nowhere' ),
					array( 'anchor' => 'fees', 'url' => 'https://example.test/fees' ),
				),
			)
		);

		$links = (array) $brief->get( 'internal_links' );
		$this->assertCount( 1, $links );
		$this->assertSame( 'fees', $links[0]['anchor'] );
	}

	public function test_a_title_sent_instead_of_an_anchor_still_works(): void {
		$brief = PageBrief::from_array(
			array( 'internal_links' => array( array( 'title' => 'Fees', 'url' => 'https://example.test/fees' ) ) )
		);

		$this->assertSame( 'Fees', ( (array) $brief->get( 'internal_links' ) )[0]['anchor'] );
	}

	public function test_every_field_survives_a_round_trip(): void {
		$in = array(
			'primary_keyword'  => 'eb-1a green card',
			'search_intent'    => 'commercial',
			'funnel_stage'     => 'BOFU',
			'meta_title'       => 'A title',
			'questions'        => array( 'One?', 'Two?' ),
			'entities'         => array( 'a', 'b' ),
			'schema_types'     => array( 'FAQPage' ),
		);

		$out = PageBrief::from_array( $in )->to_array();

		foreach ( $in as $key => $value ) {
			$this->assertSame( $value, $out[ $key ], $key . ' did not survive' );
		}
	}

	public function test_a_list_is_capped_so_a_huge_brief_cannot_bloat_the_row(): void {
		$brief = PageBrief::from_array( array( 'questions' => array_fill( 0, 200, 'Why?' ) ) );
		$this->assertLessThanOrEqual( 40, count( (array) $brief->get( 'questions' ) ) );
	}
}
