<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Forms;

use AIWP\Designer\Security\Sanitizer;

/**
 * The forms declared by one page.
 *
 * AI never writes form markup. It declares what the form asks for; the plugin
 * owns the HTML, the action, the validation and the storage.
 */
final class FormSchema {

	/** Field types a form may ask for. */
	public const FIELD_TYPES = array(
		'text', 'email', 'tel', 'url', 'number', 'textarea', 'select', 'radio', 'checkbox', 'consent',
	);

	public const MAX_FORMS       = 4;
	public const MAX_FIELDS      = 20;
	public const MAX_VALUE_BYTES = 5000;

	/** @var array<string,array<string,mixed>> */
	private array $forms;

	/**
	 * @param array<string,array<string,mixed>> $forms
	 */
	private function __construct( array $forms ) {
		$this->forms = $forms;
	}

	/**
	 * @param array<int,mixed> $raw
	 */
	public static function from_array( array $raw ): self {
		$forms = array();

		foreach ( $raw as $form ) {
			if ( ! is_array( $form ) || count( $forms ) >= self::MAX_FORMS ) {
				continue;
			}

			$id = Sanitizer::key( $form['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$fields = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || count( $fields ) >= self::MAX_FIELDS ) {
					continue;
				}
				$normalised = self::normalise_field( $field );
				if ( null !== $normalised ) {
					$fields[ $normalised['name'] ] = $normalised;
				}
			}

			$forms[ $id ] = array(
				'id'              => $id,
				'title'           => Sanitizer::text( $form['title'] ?? '' ),
				'submit_label'    => Sanitizer::text( $form['submit_label'] ?? 'Send' ) ?: 'Send',
				'success_message' => Sanitizer::text( $form['success_message'] ?? 'Thank you. Your message has been sent.' ),
				'notify_email'    => sanitize_email( (string) ( $form['notify_email'] ?? '' ) ),
				'consent_note'    => Sanitizer::text( $form['consent_note'] ?? '' ),
				'fields'          => $fields,
			);
		}

		return new self( $forms );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>|null
	 */
	private static function normalise_field( array $field ): ?array {
		$name = Sanitizer::key( $field['name'] ?? ( $field['id'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		$type = Sanitizer::key( $field['type'] ?? 'text' );
		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			$type = 'text';
		}

		$choices = array();
		foreach ( (array) ( $field['choices'] ?? array() ) as $key => $label ) {
			$key = Sanitizer::key( is_int( $key ) ? (string) $label : (string) $key );
			if ( '' !== $key ) {
				$choices[ $key ] = Sanitizer::text( $label );
			}
		}

		$width = Sanitizer::key( $field['width'] ?? 'full' );
		if ( ! in_array( $width, array( 'full', 'half', 'third' ), true ) ) {
			$width = 'full';
		}

		return array(
			'id'          => Sanitizer::key( $field['id'] ?? $name ),
			'name'        => $name,
			'label'       => Sanitizer::text( $field['label'] ?? ucwords( str_replace( '_', ' ', $name ) ) ),
			'type'        => $type,
			'required'    => ! empty( $field['required'] ),
			'placeholder' => Sanitizer::text( $field['placeholder'] ?? '' ),
			'help'        => Sanitizer::text( $field['help'] ?? '' ),
			'width'       => $width,
			'choices'     => $choices,
			'rows'        => min( 12, max( 2, absint( $field['rows'] ?? 5 ) ) ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function to_array(): array {
		$out = array();
		foreach ( $this->forms as $form ) {
			$form['fields'] = array_values( $form['fields'] );
			$out[]          = $form;
		}
		return $out;
	}

	public function has( string $id ): bool {
		return isset( $this->forms[ strtolower( $id ) ] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->forms[ strtolower( $id ) ] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->forms );
	}

	public function is_empty(): bool {
		return array() === $this->forms;
	}

	/**
	 * @return string[]
	 */
	public function errors(): array {
		$errors = array();

		foreach ( $this->forms as $id => $form ) {
			if ( array() === $form['fields'] ) {
				$errors[] = sprintf( 'Form "%s" has no fields.', $id );
				continue;
			}

			$has_contact = false;
			foreach ( $form['fields'] as $field ) {
				if ( in_array( $field['type'], array( 'email', 'tel' ), true ) ) {
					$has_contact = true;
				}
				if ( in_array( $field['type'], array( 'select', 'radio' ), true ) && array() === $field['choices'] ) {
					$errors[] = sprintf( 'Form field "%s.%s" is a %s with no choices.', $id, $field['name'], $field['type'] );
				}
			}

			if ( ! $has_contact ) {
				$errors[] = sprintf( 'Form "%s" has no email or telephone field, so a reply would be impossible.', $id );
			}
		}

		return $errors;
	}
}
