<?php
declare( strict_types = 1 );

use AIWP\Designer\ACF\Fields\AIRepeaterField;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'acf_field' ) ) {
	/** Enough of ACF's base class to construct one field type standalone. */
	class acf_field { // phpcs:ignore
		public function add_field_filter( $name, $callback, $priority = 10, $args = 1 ) {}
		public function add_filter( $name, $callback, $priority = 10, $args = 1 ) {}
		public function add_action( $name, $callback, $priority = 10, $args = 1 ) {}
	}
}

/**
 * What the browser posts back when somebody edits a repeater in wp-admin.
 *
 * Two things in a submitted repeater are not rows the owner filled in: the
 * hidden template row JavaScript clones, keyed acfcloneindex, and the row
 * counter __count. And every sub value is named after its ACF field key, not
 * its field name, because that is what acf_render_field_wrap writes.
 *
 * update_value always knew all three facts. validate_value knew none of them,
 * so it saw one phantom empty row and read every real row as blank. No page
 * with a repeater could be saved from wp-admin on any site this plugin has
 * built — which defeats the one thing ACF fields are there for.
 *
 * These tests drive the real field object, not a copy of its logic.
 */
final class RepeaterValidationTest extends TestCase {

	private AIRepeaterField $field_type;

	protected function setUp(): void {
		$this->field_type = new AIRepeaterField();
	}

	/** @return array<string,mixed> */
	private function field( int $max = 0, bool $required = true ): array {
		return array(
			'label'      => 'Montessori principles',
			'name'       => 'montessori_principles',
			'key'        => 'field_principles',
			'min'        => 0,
			'max'        => $max,
			'sub_fields' => array(
				array(
					'key'      => 'field_title',
					'name'     => 'title',
					'_name'    => 'title',
					'label'    => 'Principle',
					'type'     => 'text',
					'required' => $required ? 1 : 0,
				),
				array(
					'key'   => 'field_body',
					'name'  => 'body',
					'_name' => 'body',
					'label' => 'Explanation',
					'type'  => 'textarea',
				),
			),
		);
	}

	/**
	 * Three filled rows, named the way a real browser names them.
	 *
	 * @return array<string,mixed>
	 */
	private function submitted(): array {
		return array(
			'__count'       => '3',
			0               => array( 'field_title' => 'Child-led learning', 'field_body' => 'Children choose.' ),
			1               => array( 'field_title' => 'Prepared environments', 'field_body' => 'The room teaches.' ),
			2               => array( 'field_title' => 'Respectful guidance', 'field_body' => 'Guides observe.' ),
			'acfcloneindex' => array( 'field_title' => '', 'field_body' => '' ),
		);
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $field
	 */
	private function validate( $value, array $field ) {
		return $this->field_type->validate_value( true, $value, $field, 'acf[field_principles]' );
	}

	public function test_three_rows_pass_a_maximum_of_three(): void {
		$this->assertTrue( $this->validate( $this->submitted(), $this->field( 3 ) ) );
	}

	public function test_the_template_row_does_not_read_as_a_missing_principle(): void {
		$this->assertTrue( $this->validate( $this->submitted(), $this->field( 0 ) ) );
	}

	public function test_rows_named_by_field_key_are_read(): void {
		// The browser names every sub input after its key. Reading names only
		// made all three rows look empty.
		$this->assertTrue( $this->validate( $this->submitted(), $this->field( 0 ) ) );
	}

	public function test_rows_named_by_field_name_are_read_too(): void {
		// Rows written programmatically are keyed by name.
		$value = array(
			0 => array( 'title' => 'Child-led learning', 'body' => 'Children choose.' ),
		);
		$this->assertTrue( $this->validate( $value, $this->field( 0 ) ) );
	}

	public function test_a_row_the_owner_left_blank_is_still_refused(): void {
		$value      = $this->submitted();
		$value[1]['field_title'] = '';

		$this->assertSame( 'Principle is required in row 2.', $this->validate( $value, $this->field( 0 ) ) );
	}

	public function test_a_fourth_real_row_is_refused_by_the_maximum(): void {
		$value    = $this->submitted();
		$value[3] = array( 'field_title' => 'One too many', 'field_body' => 'Extra.' );

		$this->assertSame( 'Montessori principles allows at most 3 row(s).', $this->validate( $value, $this->field( 3 ) ) );
	}

	public function test_a_minimum_still_counts_real_rows_only(): void {
		$field        = $this->field( 0 );
		$field['min'] = 4;

		$this->assertSame( 'Montessori principles needs at least 4 row(s).', $this->validate( $this->submitted(), $field ) );
	}

	public function test_nothing_posted_is_no_rows(): void {
		$field        = $this->field( 0 );
		$field['min'] = 1;

		$this->assertSame( 'Montessori principles needs at least 1 row(s).', $this->validate( 'not an array', $field ) );
	}
}
