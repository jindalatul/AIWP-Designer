<?php
declare( strict_types = 1 );

namespace AIWP\Designer\ACF\Fields;

/**
 * The aiwp_repeater ACF field type.
 *
 * Independently written against ACF's public extension API. No ACF Pro code is
 * used, and ACF Pro's database layout is not imitated: the whole repeater is a
 * single post meta value owned by this field.
 */
final class AIRepeaterField extends \acf_field {

	public $name     = 'aiwp_repeater';
	public $label    = 'AIWP Repeater';
	public $category = 'layout';
	public $defaults = array(
		'sub_fields'   => array(),
		'min'          => 0,
		'max'          => 0,
		'button_label' => 'Add Row',
	);

	/** Maximum rows accepted from any single request. */
	private const HARD_MAX_ROWS = 200;

	public function initialize(): void {
		$this->label        = __( 'AIWP Repeater', 'aiwp-designer' );
		$this->public       = true;
		$this->show_in_rest = true;

		// ACF flattens nested definitions at import time; each field type does its own.
		$this->add_field_filter( 'acf/prepare_field_for_import', array( $this, 'prepare_field_for_import' ) );
		$this->add_field_filter( 'acf/duplicate_field', array( $this, 'duplicate_field' ) );
	}

	/**
	 * Split "sub_fields" out into their own local fields, parented to this one.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>|array<int,array<string,mixed>>
	 */
	public function prepare_field_for_import( $field ) {
		if ( empty( $field['sub_fields'] ) ) {
			return $field;
		}

		$sub_fields = acf_extract_var( $field, 'sub_fields' );
		$extra      = array();

		foreach ( $sub_fields as $index => $sub_field ) {
			$sub_field['parent']     = $field['key'];
			$sub_field['menu_order'] = $index;
			$extra[]                 = $sub_field;
		}

		$field['sub_fields'] = array();

		return array_merge( array( $field ), $extra );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public function load_field( $field ) {
		$field['sub_fields'] = acf_get_fields( $field );
		return $field;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public function duplicate_field( $field ) {
		$sub_fields = acf_extract_var( $field, 'sub_fields' );
		if ( $sub_fields ) {
			acf_duplicate_fields( $sub_fields, $field['key'] );
		}
		return $field;
	}

	// -------------------------------------------------------------- rendering

	/**
	 * @param array<string,mixed> $field
	 */
	public function render_field( $field ): void {
		$sub_fields = $field['sub_fields'] ?? array();
		if ( ! $sub_fields ) {
			echo '<p class="aiwp-repeater-empty">' . esc_html__( 'This repeater has no sub fields.', 'aiwp-designer' ) . '</p>';
			return;
		}

		$value = is_array( $field['value'] ?? null ) ? $field['value'] : array();
		$min   = absint( $field['min'] ?? 0 );
		$max   = absint( $field['max'] ?? 0 );

		$attrs = sprintf(
			'class="aiwp-repeater" data-min="%d" data-max="%d" data-name="%s"',
			$min,
			$max,
			esc_attr( (string) $field['name'] )
		);

		echo '<div ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		printf(
			'<input type="hidden" name="%s[__count]" class="aiwp-repeater-count" value="%d" />',
			esc_attr( (string) $field['name'] ),
			count( $value )
		);

		echo '<div class="aiwp-repeater-rows">';
		foreach ( array_values( $value ) as $index => $row ) {
			$this->render_row( $field, $sub_fields, (int) $index, is_array( $row ) ? $row : array() );
		}
		echo '</div>';

		// Hidden template used by JS when a new row is added.
		echo '<div class="aiwp-repeater-template" style="display:none" aria-hidden="true">';
		$this->render_row( $field, $sub_fields, 'acfcloneindex', array(), true );
		echo '</div>';

		printf(
			'<p class="aiwp-repeater-actions"><button type="button" class="button aiwp-repeater-add">%s</button> <span class="aiwp-repeater-hint"></span></p>',
			esc_html( (string) ( $field['button_label'] ?: __( 'Add Row', 'aiwp-designer' ) ) )
		);

		echo '</div>';
	}

	/**
	 * @param array<string,mixed>              $field
	 * @param array<int,array<string,mixed>>   $sub_fields
	 * @param int|string                       $index
	 * @param array<string,mixed>              $row
	 */
	private function render_row( array $field, array $sub_fields, int|string $index, array $row, bool $is_template = false ): void {
		printf(
			'<div class="aiwp-repeater-row" data-index="%s">',
			esc_attr( (string) $index )
		);

		echo '<div class="aiwp-repeater-handle" title="' . esc_attr__( 'Drag to reorder', 'aiwp-designer' ) . '">';
		echo '<span class="aiwp-repeater-order"></span><span class="dashicons dashicons-menu"></span>';
		echo '</div>';

		echo '<div class="aiwp-repeater-fields acf-fields -left">';
		foreach ( $sub_fields as $sub_field ) {
			$sub_field['prefix'] = $field['name'] . '[' . $index . ']';
			$sub_field['value']  = $is_template ? null : ( $row[ $sub_field['_name'] ?? $sub_field['name'] ] ?? null );

			// Required sub fields are validated by this field, not by ACF's own
			// required machinery, because template rows would otherwise fail.
			$sub_field['required'] = 0;

			unset( $sub_field['_prepare'] );

			acf_render_field_wrap( $sub_field );
		}
		echo '</div>';

		echo '<div class="aiwp-repeater-row-actions">';
		printf( '<button type="button" class="button-link aiwp-repeater-duplicate" title="%1$s">%1$s</button>', esc_attr__( 'Duplicate', 'aiwp-designer' ) );
		printf( '<button type="button" class="button-link aiwp-repeater-remove" title="%1$s">%1$s</button>', esc_attr__( 'Remove', 'aiwp-designer' ) );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $field
	 */
	public function render_field_settings( $field ): void {
		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Minimum rows', 'aiwp-designer' ),
				'instructions' => '',
				'type'         => 'number',
				'name'         => 'min',
			)
		);
		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Maximum rows', 'aiwp-designer' ),
				'instructions' => __( '0 means no limit.', 'aiwp-designer' ),
				'type'         => 'number',
				'name'         => 'max',
			)
		);
		acf_render_field_setting(
			$field,
			array(
				'label' => __( 'Add row button label', 'aiwp-designer' ),
				'type'  => 'text',
				'name'  => 'button_label',
			)
		);
	}

	public function input_admin_enqueue_scripts(): void {
		wp_enqueue_script(
			'aiwp-repeater',
			AIWP_PLUGIN_URL . 'assets/dist/repeater.js',
			array( 'acf-input', 'jquery-ui-sortable' ),
			AIWP_VERSION,
			true
		);
		wp_enqueue_style(
			'aiwp-repeater',
			AIWP_PLUGIN_URL . 'assets/dist/repeater.css',
			array( 'acf-input' ),
			AIWP_VERSION
		);
	}

	// ----------------------------------------------------------------- values

	/**
	 * @param mixed               $value
	 * @param int|string          $post_id
	 * @param array<string,mixed> $field
	 * @return array<int,array<string,mixed>>
	 */
	public function load_value( $value, $post_id, $field ) {
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = maybe_unserialize( $value );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Turns the posted rows (keyed by sub field key) into clean, name-keyed rows.
	 *
	 * @param mixed               $value
	 * @param int|string          $post_id
	 * @param array<string,mixed> $field
	 * @return array<int,array<string,mixed>>
	 */
	public function update_value( $value, $post_id, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		unset( $value['__count'] );

		$sub_fields = $this->sub_field_map( $field );
		$rows       = array();

		foreach ( $value as $key => $raw_row ) {
			if ( 'acfcloneindex' === $key || ! is_array( $raw_row ) ) {
				continue;
			}
			if ( count( $rows ) >= self::HARD_MAX_ROWS ) {
				break;
			}

			$row = array();
			foreach ( $raw_row as $sub_key => $sub_value ) {
				// Posted rows are keyed by ACF field key; programmatic ones by name.
				$sub_field = $sub_fields['key'][ $sub_key ] ?? ( $sub_fields['name'][ $sub_key ] ?? null );
				if ( null === $sub_field ) {
					continue;
				}
				$row[ $sub_field['_name'] ?? $sub_field['name'] ] = $this->sanitize_sub_value( $sub_value, (string) $sub_field['type'] );
			}

			if ( array() !== $row ) {
				$rows[] = $row;
			}
		}

		$max = absint( $field['max'] ?? 0 );
		if ( $max > 0 && count( $rows ) > $max ) {
			$rows = array_slice( $rows, 0, $max );
		}

		return $rows;
	}

	private function sanitize_sub_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'image':
			case 'file':
			case 'number':
				return is_array( $value ) ? 0 : ( '' === $value ? null : (int) $value );

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
				if ( ! is_array( $value ) ) {
					return array(
						'title'  => '',
						'url'    => esc_url_raw( (string) ( is_scalar( $value ) ? $value : '' ) ),
						'target' => '',
					);
				}
				return array(
					'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
					'url'    => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
					'target' => '_blank' === ( $value['target'] ?? '' ) ? '_blank' : '',
				);

			default:
				return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		}
	}

	/**
	 * @param mixed               $value
	 * @param int|string          $post_id
	 * @param array<string,mixed> $field
	 * @return array<int,array<string,mixed>>
	 */
	public function format_value( $value, $post_id, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sub_fields = $field['sub_fields'] ?? array();
		$out        = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$formatted = array();
			foreach ( $sub_fields as $sub_field ) {
				$name               = $sub_field['_name'] ?? $sub_field['name'];
				$raw                = $row[ $name ] ?? null;
				$formatted[ $name ] = $this->format_sub_value( $raw, $post_id, $sub_field );
			}
			$out[] = $formatted;
		}

		return $out;
	}

	/**
	 * Format one sub value.
	 *
	 * acf_format_value() caches per post id + field name. Every row shares a sub
	 * field name, so going through it would return the first row's value for all
	 * rows. The type filter is applied directly instead.
	 *
	 * @param mixed               $value
	 * @param int|string          $post_id
	 * @param array<string,mixed> $sub_field
	 */
	private function format_sub_value( $value, $post_id, array $sub_field ): mixed {
		return apply_filters(
			'acf/format_value/type=' . $sub_field['type'],
			$value,
			$post_id,
			$sub_field,
			false
		);
	}

	/**
	 * @param bool|string         $valid
	 * @param mixed               $value
	 * @param array<string,mixed> $field
	 * @param string              $input
	 * @return bool|string
	 */
	public function validate_value( $valid, $value, $field, $input ) {
		$rows  = is_array( $value ) ? array_filter( $value, 'is_array' ) : array();
		$count = count( $rows );

		$min = absint( $field['min'] ?? 0 );
		$max = absint( $field['max'] ?? 0 );

		if ( $min > 0 && $count < $min ) {
			/* translators: 1: field label, 2: minimum rows */
			return sprintf( __( '%1$s needs at least %2$d row(s).', 'aiwp-designer' ), $field['label'], $min );
		}

		if ( $max > 0 && $count > $max ) {
			/* translators: 1: field label, 2: maximum rows */
			return sprintf( __( '%1$s allows at most %2$d row(s).', 'aiwp-designer' ), $field['label'], $max );
		}

		$required_subs = array();
		foreach ( $this->sub_field_map( $field )['name'] as $sub_field ) {
			if ( ! empty( $sub_field['required'] ) ) {
				$required_subs[ $sub_field['_name'] ?? $sub_field['name'] ] = $sub_field['label'];
			}
		}

		if ( $required_subs ) {
			$row_number = 0;
			foreach ( $rows as $row ) {
				++$row_number;
				foreach ( $required_subs as $name => $label ) {
					$row_value = $row[ $name ] ?? null;
					if ( null === $row_value || '' === $row_value || array() === $row_value ) {
						/* translators: 1: sub field label, 2: row number */
						return sprintf( __( '%1$s is required in row %2$d.', 'aiwp-designer' ), $label, $row_number );
					}
				}
			}
		}

		return $valid;
	}

	/**
	 * Sub fields indexed both by ACF key and by field name.
	 *
	 * @param array<string,mixed> $field
	 * @return array{key:array<string,array<string,mixed>>,name:array<string,array<string,mixed>>}
	 */
	private function sub_field_map( array $field ): array {
		$sub_fields = $field['sub_fields'] ?? array();
		if ( ! $sub_fields && ! empty( $field['key'] ) ) {
			$sub_fields = acf_get_fields( $field );
		}

		$map = array(
			'key'  => array(),
			'name' => array(),
		);

		foreach ( (array) $sub_fields as $sub_field ) {
			if ( ! is_array( $sub_field ) || empty( $sub_field['key'] ) ) {
				continue;
			}
			$map['key'][ $sub_field['key'] ]                            = $sub_field;
			$map['name'][ $sub_field['_name'] ?? $sub_field['name'] ]   = $sub_field;
		}

		return $map;
	}

	// ------------------------------------------------------------------- REST

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public function get_rest_schema( array $field ) {
		$properties = array();

		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub_field ) {
			$name                = $sub_field['_name'] ?? $sub_field['name'];
			$properties[ $name ] = $this->sub_field_rest_schema( (string) $sub_field['type'] );
		}

		return array(
			'type'  => array( 'array', 'null' ),
			'items' => array(
				'type'       => 'object',
				'properties' => $properties,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sub_field_rest_schema( string $type ): array {
		switch ( $type ) {
			case 'image':
			case 'file':
			case 'number':
				return array( 'type' => array( 'integer', 'null' ) );

			case 'true_false':
				return array( 'type' => array( 'boolean', 'integer', 'null' ) );

			case 'link':
				return array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'title'  => array( 'type' => array( 'string', 'null' ) ),
						'url'    => array( 'type' => array( 'string', 'null' ) ),
						'target' => array( 'type' => array( 'string', 'null' ) ),
					),
				);

			default:
				return array( 'type' => array( 'string', 'null' ) );
		}
	}
}
