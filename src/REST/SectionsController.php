<?php
declare( strict_types = 1 );

namespace AIWP\Designer\REST;

use AIWP\Designer\ACF\FieldKeyGenerator;
use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;
use AIWP\Designer\Security\Sanitizer;
use AIWP\Designer\Template\FieldReference;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Adding and removing fields on a page, from the page editor, without saving
 * the page.
 *
 * The first version of this used checkboxes and the Update button, which meant
 * scrolling to the top of a long screen to add one field, and a checkbox as the
 * only thing between somebody and deleting content they had typed. Both were
 * wrong. Adding happens where you are looking; removing asks you to type.
 */
final class SectionsController {

	/** Field types a person can pick. Deliberately short. */
	public const TYPES = array(
		'text'          => 'Single line of text',
		'textarea'      => 'Several lines of text',
		'wysiwyg'       => 'Formatted text',
		'url'           => 'Web address',
		'email'         => 'Email address',
		'number'        => 'Number',
		'image'         => 'Image',
		'file'          => 'File',
		'true_false'    => 'Yes or no',
		'aiwp_repeater' => 'A repeating list',
	);

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_routes(): void {
		$ns   = AIWP_REST_NAMESPACE;
		$auth = array( $this, 'may_edit' );

		foreach ( array( 'sections', 'fields' ) as $thing ) {
			register_rest_route(
				$ns,
				'/' . $thing,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'sections' === $thing ? 'add_section' : 'add_field' ),
					'permission_callback' => $auth,
				)
			);

			register_rest_route(
				$ns,
				'/' . $thing . '/remove',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'sections' === $thing ? 'remove_section' : 'remove_field' ),
					'permission_callback' => $auth,
				)
			);
		}

		register_rest_route(
			$ns,
			'/sections/list',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'listing' ),
				'permission_callback' => $auth,
			)
		);
	}

	public function may_edit( WP_REST_Request $request ): bool {
		$page_id = absint( $request->get_param( 'page_id' ) );

		return current_user_can( CapabilityManager::EDIT_PAGES )
			&& $page_id > 0
			&& current_user_can( 'edit_post', $page_id );
	}

	// ---------------------------------------------------------------- read

	public function listing( WP_REST_Request $request ): WP_REST_Response {
		$page_id = absint( $request->get_param( 'page_id' ) );

		return new WP_REST_Response( $this->state( $page_id ), 200 );
	}

	// ---------------------------------------------------------------- write

	public function add_section( WP_REST_Request $request ): WP_REST_Response {
		$page_id = absint( $request->get_param( 'page_id' ) );
		$schema  = $this->plugin->pages()->schema( $page_id );

		if ( ! $schema instanceof PageSchema ) {
			return $this->fail( 'That page has no field model yet.' );
		}

		$label = Sanitizer::text( (string) $request->get_param( 'label' ) );
		if ( '' === $label ) {
			return $this->fail( 'Give the section a name.' );
		}

		$sections = $schema->to_array();
		$id       = $this->unique_id( $label, array_column( $sections, 'id' ), 24 );

		$sections[] = array(
			'id'     => $id,
			'label'  => $label,
			'owner'  => true,
			'fields' => array(
				$this->field_from(
					$id,
					(string) $request->get_param( 'field_label' ),
					(string) $request->get_param( 'field_type' ),
					array(),
					$this->taken_prints( $sections )
				),
			),
		);

		$this->store( $page_id, $sections );

		return $this->ok( $page_id, sprintf( 'Added "%s".', $label ), $id );
	}

	public function add_field( WP_REST_Request $request ): WP_REST_Response {
		$page_id = absint( $request->get_param( 'page_id' ) );
		$schema  = $this->plugin->pages()->schema( $page_id );

		if ( ! $schema instanceof PageSchema ) {
			return $this->fail( 'That page has no field model yet.' );
		}

		$section_id = sanitize_key( (string) $request->get_param( 'section_id' ) );
		$label      = Sanitizer::text( (string) $request->get_param( 'label' ) );

		if ( '' === $label ) {
			return $this->fail( 'Give the field a name.' );
		}

		$sections = $schema->to_array();
		$found    = false;

		foreach ( $sections as &$section ) {
			if ( $section['id'] !== $section_id ) {
				continue;
			}

			$found  = true;
			$names  = array_column( $section['fields'], 'name' );
			$field  = $this->field_from(
				$section_id,
				$label,
				(string) $request->get_param( 'type' ),
				$names,
				$this->taken_prints( $sections )
			);

			$section['fields'][] = $field;
		}
		unset( $section );

		if ( ! $found ) {
			return $this->fail( 'There is no section with that name on this page.' );
		}

		$this->store( $page_id, $sections );

		return $this->ok( $page_id, sprintf( 'Added "%s".', $label ), $section_id );
	}

	public function remove_field( WP_REST_Request $request ): WP_REST_Response {
		$page_id    = absint( $request->get_param( 'page_id' ) );
		$section_id = sanitize_key( (string) $request->get_param( 'section_id' ) );
		$name       = sanitize_key( (string) $request->get_param( 'name' ) );
		$schema     = $this->plugin->pages()->schema( $page_id );

		if ( ! $schema instanceof PageSchema ) {
			return $this->fail( 'That page has no field model yet.' );
		}

		$expected = $this->phrase_for_field( $schema, $section_id, $name );
		if ( null === $expected ) {
			return $this->fail( 'There is no field with that name.' );
		}

		$typed = trim( (string) $request->get_param( 'confirm' ) );
		if ( strtolower( $typed ) !== strtolower( $expected ) ) {
			return $this->fail( sprintf( 'Type %s to remove it.', $expected ) );
		}

		$sections = $schema->to_array();

		foreach ( $sections as &$section ) {
			if ( $section['id'] !== $section_id ) {
				continue;
			}
			$section['fields'] = array_values(
				array_filter( $section['fields'], static fn( array $f ): bool => $f['name'] !== $name )
			);
		}
		unset( $section );

		$this->forget( $page_id, $section_id, array( $name ) );
		$this->store( $page_id, $sections );

		return $this->ok( $page_id, 'Field removed, along with anything typed into it.', $section_id );
	}

	public function remove_section( WP_REST_Request $request ): WP_REST_Response {
		$page_id    = absint( $request->get_param( 'page_id' ) );
		$section_id = sanitize_key( (string) $request->get_param( 'section_id' ) );
		$schema     = $this->plugin->pages()->schema( $page_id );

		if ( ! $schema instanceof PageSchema ) {
			return $this->fail( 'That page has no field model yet.' );
		}

		$target = null;
		foreach ( $schema->to_array() as $section ) {
			if ( $section['id'] === $section_id ) {
				$target = $section;
				break;
			}
		}

		if ( null === $target ) {
			return $this->fail( 'There is no section with that name on this page.' );
		}

		// A section the owner added asks for a word. A section the design uses
		// asks for its own name, because deleting it leaves holes in the page.
		$expected = empty( $target['owner'] ) ? $section_id : 'remove';

		$typed = trim( (string) $request->get_param( 'confirm' ) );
		if ( strtolower( $typed ) !== strtolower( $expected ) ) {
			return $this->fail( sprintf( 'Type %s to remove it.', $expected ) );
		}

		$sections = array_values(
			array_filter( $schema->to_array(), static fn( array $s ): bool => $s['id'] !== $section_id )
		);

		$this->forget( $page_id, $section_id, array_column( $target['fields'], 'name' ) );
		$this->store( $page_id, $sections );

		return $this->ok( $page_id, sprintf( 'Removed "%s" and everything in it.', $target['label'] ) );
	}

	// ------------------------------------------------------------ internals

	/**
	 * What the page would lose if this section went.
	 *
	 * @return string[]
	 */
	private function used_by_template( int $page_id, string $section_id ): array {
		$template = $this->plugin->pages()->template( $page_id );

		if ( ! preg_match_all( '/\{\{[^}]*:\s*(' . preg_quote( $section_id, '/' ) . '\.[A-Za-z0-9_.]+)/', $template, $m ) ) {
			return array();
		}

		return array_values( array_unique( $m[1] ) );
	}

	private function phrase_for_field( PageSchema $schema, string $section_id, string $name ): ?string {
		$field = $schema->field( $section_id . '.' . $name );

		return null === $field ? null : 'remove';
	}

	/**
	 * @param string[] $taken
	 * @return array<string,mixed>
	 */
	private function field_from( string $section_id, string $label, string $type, array $taken, array $prints ): array {
		$label = Sanitizer::text( $label );
		if ( '' === $label ) {
			$label = 'Field';
		}

		if ( ! isset( self::TYPES[ $type ] ) ) {
			$type = 'text';
		}

		$name = $this->free_name( $label, $taken, 28, $section_id, $prints );

		return array(
			'id'         => $name,
			'name'       => $name,
			'label'      => $label,
			'type'       => $type,
			'sub_fields' => 'aiwp_repeater' === $type
				? array( array( 'id' => 'item', 'name' => 'item', 'label' => $label, 'type' => 'text' ) )
				: array(),
		);
	}

	/**
	 * Where every field on this page is already stored.
	 *
	 * Not the same question as "is this id free". "hero" with a field
	 * "sub_title" and "hero_sub" with a field "title" are two different
	 * fields that land in one place, and the second one silently replaces the
	 * first. So a new name has to clear the whole page, not just its section.
	 *
	 * @param array<int,array<string,mixed>> $sections
	 * @return array<string,true>
	 */
	private function taken_prints( array $sections ): array {
		$out = array();

		foreach ( $sections as $section ) {
			foreach ( (array) $section['fields'] as $field ) {
				$print         = FieldKeyGenerator::fingerprint( (string) $section['id'], (string) $field['name'] );
				$out[ $print ] = true;
			}
		}

		return $out;
	}

	/**
	 * A name that is free twice over: unused in its own section, and stored
	 * somewhere nothing else on the page is stored.
	 *
	 * @param string[]          $taken
	 * @param array<string,true> $prints
	 */
	private function free_name( string $label, array $taken, int $limit, string $section_id, array $prints ): string {
		$map  = array_flip( $taken );
		$base = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
		$base = '' !== $base ? substr( $base, 0, $limit ) : 'item';

		$name = $base;
		$n    = 2;

		while ( isset( $map[ $name ] ) || isset( $prints[ FieldKeyGenerator::fingerprint( $section_id, $name ) ] ) ) {
			$name = $base . '_' . $n++;
		}

		return $name;
	}

	/**
	 * @param string[] $taken
	 */
	private function unique_id( string $label, array $taken, int $limit ): string {
		$base = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
		$base = '' !== $base ? substr( $base, 0, $limit ) : 'item';

		$map = array_flip( $taken );
		$id  = $base;
		$n   = 2;

		while ( isset( $map[ $id ] ) ) {
			$id = $base . '_' . $n++;
		}

		return $id;
	}

	/**
	 * @param string[] $names
	 */
	private function forget( int $page_id, string $section_id, array $names ): void {
		foreach ( $names as $name ) {
			$key = PageSchema::acf_name( $section_id . '.' . $name );
			delete_post_meta( $page_id, $key );
			delete_post_meta( $page_id, '_' . $key );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 */
	private function store( int $page_id, array $sections ): void {
		$this->plugin->pages()->save_schema( $page_id, PageSchema::from_array( $sections ) );
		$this->plugin->schemas()->refresh();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state( int $page_id ): array {
		$schema = $this->plugin->pages()->schema( $page_id );

		$out = array();
		foreach ( $schema instanceof PageSchema ? $schema->to_array() : array() as $section ) {
			$fields = array();
			foreach ( $section['fields'] as $field ) {
				$fields[] = array(
					'name'  => $field['name'],
					'label' => $field['label'],
					'type'  => $field['type'],
					'type_label' => self::TYPES[ $field['type'] ] ?? $field['type'],
					// What to type in the template to print this field.
					'reference'  => FieldReference::for_field( (string) $section['id'], (string) $field['name'], (string) $field['type'] ),
					'snippet'    => FieldReference::snippet( (string) $section['id'], (string) $field['name'], (string) $field['type'], (array) ( $field['sub_fields'] ?? array() ) ),
					'also'       => FieldReference::also( (string) $section['id'], (string) $field['name'], (string) $field['type'] ),
				);
			}

			$used = $this->used_by_template( $page_id, (string) $section['id'] );

			$out[] = array(
				'id'      => $section['id'],
				'label'   => $section['label'],
				'owner'   => ! empty( $section['owner'] ),
				'fields'  => $fields,
				'used_by_design' => $used,
				'confirm' => empty( $section['owner'] ) ? $section['id'] : 'remove',
			);
		}

		return array( 'page_id' => $page_id, 'sections' => $out, 'types' => self::TYPES );
	}

	private function ok( int $page_id, string $message, string $focus = '' ): WP_REST_Response {
		// The id of whatever was just touched. The screen puts it where the
		// person is already looking rather than at the end of a growing list.
		return new WP_REST_Response(
			array_merge( array( 'ok' => true, 'message' => $message, 'focus' => $focus ), $this->state( $page_id ) ),
			200
		);
	}

	private function fail( string $message ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'message' => $message ), 200 );
	}
}
