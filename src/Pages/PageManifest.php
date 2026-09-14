<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\Security\Sanitizer;

/**
 * Small record of the design decisions worth keeping. No chain-of-thought.
 */
final class PageManifest {

	/** @var array<string,mixed> */
	private array $data;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public static function build( array $input ): self {
		$behaviors = array();
		foreach ( (array) ( $input['behaviors'] ?? array() ) as $behavior ) {
			$behavior = Sanitizer::key( $behavior );
			if ( '' !== $behavior ) {
				$behaviors[] = $behavior;
			}
		}

		return new self(
			array(
				'schema_version'        => 1,
				'page_uuid'             => (string) ( $input['page_uuid'] ?? '' ),
				'wordpress_page_id'     => absint( $input['wordpress_page_id'] ?? 0 ),
				'template_version'      => absint( $input['template_version'] ?? 1 ),
				'design_system_version' => absint( $input['design_system_version'] ?? 0 ),
				'created_by'            => Sanitizer::text( $input['created_by'] ?? 'mcp' ),
				// What kind of page this is. Pages of the same kind are meant to
				// look like each other, so the next one of this kind is built by
				// reading one that already exists rather than starting over.
				'page_type'             => Sanitizer::key( $input['page_type'] ?? '' ),
				// How long this page was meant to be. Whoever wrote the brief
				// knows; the plugin only has to remember, so something can
				// notice when a page ships at half the length it was planned at.
				'target_words'          => absint( $input['target_words'] ?? 0 ),
				'page_goal'             => Sanitizer::text( $input['page_goal'] ?? '' ),
				'audience'              => Sanitizer::text( $input['audience'] ?? '' ),
				'visual_direction'      => Sanitizer::text( $input['visual_direction'] ?? '' ),
				'primary_conversion'    => Sanitizer::text( $input['primary_conversion'] ?? '' ),
				'story'                 => array_map( array( Sanitizer::class, 'text' ), array_slice( (array) ( $input['story'] ?? array() ), 0, 12 ) ),
				'behaviors'             => array_values( array_unique( $behaviors ) ),
				'forms'                 => array_values( (array) ( $input['forms'] ?? array() ) ),
				'chrome'                => in_array( $input['chrome'] ?? 'theme', array( 'theme', 'blank', 'site' ), true ) ? $input['chrome'] : 'theme',
				'updated_at'            => gmdate( 'c' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}
}
