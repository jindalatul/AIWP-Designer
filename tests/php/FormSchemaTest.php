<?php
declare( strict_types = 1 );

use AIWP\Designer\Forms\FormSchema;
use PHPUnit\Framework\TestCase;

final class FormSchemaTest extends TestCase {

	/**
	 * @param array<int,mixed> $forms
	 */
	private function schema( array $forms ): FormSchema {
		return FormSchema::from_array( $forms );
	}

	private function valid_form(): array {
		return array(
			'id'     => 'enquiry',
			'title'  => 'Get in touch',
			'fields' => array(
				array( 'id' => 'name', 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true ),
				array( 'id' => 'email', 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
			),
		);
	}

	public function test_a_valid_form_has_no_errors(): void {
		$schema = $this->schema( array( $this->valid_form() ) );
		$this->assertSame( array(), $schema->errors() );
		$this->assertTrue( $schema->has( 'enquiry' ) );
		$this->assertSame( array( 'enquiry' ), $schema->ids() );
	}

	public function test_a_form_with_no_way_to_reply_is_an_error(): void {
		$form           = $this->valid_form();
		$form['fields'] = array( array( 'id' => 'name', 'name' => 'name', 'type' => 'text' ) );

		$this->assertNotEmpty( $this->schema( array( $form ) )->errors() );
	}

	public function test_a_form_with_no_fields_is_an_error(): void {
		$this->assertNotEmpty( $this->schema( array( array( 'id' => 'x', 'fields' => array() ) ) )->errors() );
	}

	public function test_a_select_without_choices_is_an_error(): void {
		$form             = $this->valid_form();
		$form['fields'][] = array( 'id' => 'budget', 'name' => 'budget', 'type' => 'select' );

		$this->assertNotEmpty( $this->schema( array( $form ) )->errors() );
	}

	public function test_an_unknown_field_type_becomes_text(): void {
		$form           = $this->valid_form();
		$form['fields'][] = array( 'id' => 'evil', 'name' => 'evil', 'type' => 'file_upload_to_server' );

		$field = $this->schema( array( $form ) )->get( 'enquiry' )['fields']['evil'];
		$this->assertSame( 'text', $field['type'] );
	}

	public function test_widths_are_constrained(): void {
		$form             = $this->valid_form();
		$form['fields'][] = array( 'id' => 'x', 'name' => 'x', 'type' => 'text', 'width' => 'ninety-percent' );

		$this->assertSame( 'full', $this->schema( array( $form ) )->get( 'enquiry' )['fields']['x']['width'] );
	}

	public function test_the_number_of_forms_and_fields_is_capped(): void {
		$forms = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$form       = $this->valid_form();
			$form['id'] = 'form' . $i;
			$forms[]    = $form;
		}
		$this->assertCount( FormSchema::MAX_FORMS, $this->schema( $forms )->ids() );

		$form = $this->valid_form();
		for ( $i = 0; $i < 40; $i++ ) {
			$form['fields'][] = array( 'id' => 'f' . $i, 'name' => 'f' . $i, 'type' => 'text' );
		}
		$this->assertLessThanOrEqual( FormSchema::MAX_FIELDS, count( $this->schema( array( $form ) )->get( 'enquiry' )['fields'] ) );
	}

	public function test_a_notify_email_must_be_an_email(): void {
		$form                 = $this->valid_form();
		$form['notify_email'] = 'not an address';

		$this->assertSame( '', $this->schema( array( $form ) )->get( 'enquiry' )['notify_email'] );
	}

	public function test_labels_and_titles_are_sanitised(): void {
		$form          = $this->valid_form();
		$form['title'] = '<script>alert(1)</script>Get in touch';

		$this->assertStringNotContainsString( '<script', $this->schema( array( $form ) )->get( 'enquiry' )['title'] );
	}
}
