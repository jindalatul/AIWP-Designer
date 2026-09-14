<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * How a field is written inside a template.
 *
 * Someone looking at a box called "Opening hours" has no way to guess it is
 * written {{text:contact.opening_hours}}. They were guessing, getting the
 * filter wrong, and seeing nothing print. So the answer is shown next to the
 * field on every screen that names one, and it is worked out here so the
 * Fields screen, the value form and any future screen cannot drift apart.
 */
final class FieldReference {

	/** The filter that suits each field type. */
	private const FILTERS = array(
		'text'       => 'text',
		'textarea'   => 'text',
		'url'        => 'url',
		'email'      => 'text',
		'number'     => 'text',
		'true_false' => 'text',
		'wysiwyg'    => 'html',
		'image'      => 'image_url',
		'file'       => 'url',
	);

	/**
	 * The one line to copy. A repeater is a block, so it gets the opening tag
	 * and the full shape is in snippet().
	 */
	public static function for_field( string $section_id, string $field_name, string $type ): string {
		$path = $section_id . '.' . $field_name;

		if ( 'aiwp_repeater' === $type ) {
			return '{{#each:' . $path . '}}';
		}

		return '{{' . ( self::FILTERS[ $type ] ?? 'text' ) . ':' . $path . '}}';
	}

	/**
	 * The whole thing, for a type that needs more than one line to be useful.
	 *
	 * @param array<int,array<string,mixed>> $sub_fields
	 */
	public static function snippet( string $section_id, string $field_name, string $type, array $sub_fields = array() ): string {
		if ( 'aiwp_repeater' !== $type ) {
			return self::for_field( $section_id, $field_name, $type );
		}

		$lines = array( '{{#each:' . $section_id . '.' . $field_name . '}}' );

		foreach ( $sub_fields as $sub ) {
			$name   = (string) ( $sub['name'] ?? $sub['id'] ?? '' );
			$sub_ty = (string) ( $sub['type'] ?? 'text' );
			if ( '' === $name ) {
				continue;
			}
			$lines[] = '  {{' . ( self::FILTERS[ $sub_ty ] ?? 'text' ) . ':@item.' . $name . '}}';
		}

		$lines[] = '{{/each}}';

		return implode( "\n", $lines );
	}

	/**
	 * The same thing again, ready to sit under a box on the value form.
	 *
	 * ACF prints instructions through wp_kses, so this stays inside the small
	 * set of tags it keeps.
	 */
	public static function hint( string $section_id, string $field_name, string $type, string $existing = '' ): string {
		$lines = array( self::for_field( $section_id, $field_name, $type ) );

		if ( 'aiwp_repeater' === $type ) {
			$lines[] = '{{/each}}';
		}

		$also = self::also( $section_id, $field_name, $type );
		if ( '' !== $also ) {
			$lines[] = $also;
		}

		$code = '<code>' . implode( '</code> <code>', array_map( 'esc_html', $lines ) ) . '</code>';
		$hint = sprintf(
			/* translators: %s: one or more template tags, for example {{text:hero.h1}} */
			esc_html__( 'In the template: %s', 'aiwp-designer' ),
			$code
		);

		return '' === $existing ? $hint : $existing . '<br>' . $hint;
	}

	/** The line for one row inside a repeating list. */
	public static function for_sub_field( string $sub_name, string $type ): string {
		return '{{' . ( self::FILTERS[ $type ] ?? 'text' ) . ':@item.' . $sub_name . '}}';
	}

	/** An image needs its alt text too, or the markup is wrong. */
	public static function also( string $section_id, string $field_name, string $type ): string {
		if ( 'image' !== $type ) {
			return '';
		}

		return '{{image_alt:' . $section_id . '.' . $field_name . '}}';
	}
}
