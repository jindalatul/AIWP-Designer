<?php
declare( strict_types = 1 );

namespace AIWP\Designer\ACF;

use AIWP\Designer\Pages\PageSchema;

/**
 * Moves content between the AI's nested shape and ACF's flat field names.
 *
 * AI shape : content['hero']['headline']
 * ACF name : hero_headline
 */
final class FieldValueManager {

	/**
	 * Read every field of a page back into the nested shape the renderer wants.
	 *
	 * @return array<string,mixed>
	 */
	public function read( int $page_id, PageSchema $schema, string $uuid = '' ): array {
		$content = array();

		foreach ( $schema->sections() as $section ) {
			$content[ $section['id'] ] = array();

			foreach ( $section['fields'] as $field ) {
				$acf_name = PageSchema::acf_name( $section['id'] . '.' . $field['name'] );

				if ( ACFManager::is_available() ) {
					$lookup = '' !== $uuid
						? FieldKeyGenerator::field_key( $uuid, $section['id'] . '_' . $field['id'] )
						: $acf_name;
					$value  = get_field( $lookup, $page_id );
				} else {
					$value = get_post_meta( $page_id, $acf_name, true );
				}

				$content[ $section['id'] ][ $field['name'] ] = $this->normalise( $value, $field );
			}
		}

		return $content;
	}

	/**
	 * Write the AI's nested content into ACF.
	 *
	 * @param array<string,mixed> $content
	 * @return string[] Problems worth reporting; an empty array means clean.
	 */
	public function write( int $page_id, PageSchema $schema, array $content, string $uuid = '' ): array {
		$problems = array();

		foreach ( $schema->sections() as $section ) {
			$section_content = $content[ $section['id'] ] ?? null;
			if ( ! is_array( $section_content ) ) {
				continue;
			}

			foreach ( $section['fields'] as $field ) {
				if ( ! array_key_exists( $field['name'], $section_content ) ) {
					continue;
				}

				$path  = $section['id'] . '.' . $field['name'];
				$value = $this->prepare( $section_content[ $field['name'] ], $field );

				if ( ! $this->write_path( $page_id, $schema, $path, $value, $uuid ) ) {
					$problems[] = sprintf( 'Could not store content for "%s".', $path );
				}
			}
		}

		return $problems;
	}

	/**
	 * Write one field by its dotted path. Used by page.update_content.
	 */
	public function write_path( int $page_id, PageSchema $schema, string $path, mixed $value, string $uuid = '' ): bool {
		$field = $schema->field( $path );
		if ( null === $field ) {
			return false;
		}

		$acf_name = PageSchema::acf_name( $path );
		$value    = $this->prepare( $value, $field );

		if ( ACFManager::is_available() ) {
			// The uuid identifies whoever owns the schema, which is not always
			// the post being written. A page owns its own fields, so its uuid is
			// on the post. An article design owns fields on every post of a
			// type, so the caller passes its uuid in.
			$uuid = '' !== $uuid
				? $uuid
				: (string) get_post_meta( $page_id, \AIWP\Designer\Pages\PageRepository::META_UUID, true );

			$key  = FieldKeyGenerator::field_key(
				$uuid,
				explode( '.', $path )[0] . '_' . $field['id']
			);

			if ( update_field( $key, $value, $page_id ) ) {
				return true;
			}

			// update_field() also returns false when the value did not change.
			return $this->matches_stored( $key, $page_id, $value );
		}

		if ( update_post_meta( $page_id, $acf_name, $value ) ) {
			return true;
		}

		return get_post_meta( $page_id, $acf_name, true ) == $value; // phpcs:ignore WordPress.PHP.StrictComparisons
	}

	/**
	 * Did the write land, even though ACF reported "nothing changed"?
	 */
	private function matches_stored( string $key, int $page_id, mixed $value ): bool {
		// Values are read unformatted so they can be compared with what we sent.
		$stored = get_field( $key, $page_id, false );

		if ( is_array( $value ) || is_array( $stored ) ) {
			return wp_json_encode( $value ) === wp_json_encode( $stored );
		}

		return $stored == $value; // phpcs:ignore WordPress.PHP.StrictComparisons
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function prepare( mixed $value, array $field ): mixed {
		switch ( $field['type'] ) {
			case 'aiwp_repeater':
				if ( ! is_array( $value ) ) {
					return array();
				}
				$rows = array();
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = array();
					foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
						if ( array_key_exists( $sub['name'], $row ) ) {
							$clean[ $sub['name'] ] = $this->prepare( $row[ $sub['name'] ], $sub );
						}
					}
					if ( array() !== $clean ) {
						$rows[] = $clean;
					}
				}
				return $rows;

			case 'image':
			case 'file':
				if ( is_array( $value ) ) {
					$value = $value['id'] ?? ( $value['ID'] ?? 0 );
				}
				return is_numeric( $value ) ? (int) $value : null;

			case 'number':
				return is_numeric( $value ) ? $value + 0 : null;

			case 'true_false':
				return ! empty( $value ) ? 1 : 0;

			case 'url':
				return esc_url_raw( (string) ( is_scalar( $value ) ? $value : '' ) );

			case 'email':
				return sanitize_email( (string) ( is_scalar( $value ) ? $value : '' ) );

			case 'wysiwyg':
				return wp_kses_post( (string) ( is_scalar( $value ) ? $value : '' ) );

			case 'textarea':
				return sanitize_textarea_field( (string) ( is_scalar( $value ) ? $value : '' ) );

			case 'link':
				if ( is_string( $value ) ) {
					return array(
						'title'  => '',
						'url'    => esc_url_raw( $value ),
						'target' => '',
					);
				}
				if ( ! is_array( $value ) ) {
					return array(
						'title'  => '',
						'url'    => '',
						'target' => '',
					);
				}
				return array(
					'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
					'url'    => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
					'target' => '_blank' === ( $value['target'] ?? '' ) ? '_blank' : '',
				);

			case 'checkbox':
				return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();

			default:
				return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		}
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function normalise( mixed $value, array $field ): mixed {
		if ( 'aiwp_repeater' === $field['type'] ) {
			return is_array( $value ) ? $value : array();
		}
		if ( null === $value || false === $value ) {
			return '';
		}
		return $value;
	}
}
