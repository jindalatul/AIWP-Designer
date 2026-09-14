<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\ACF\FieldKeyGenerator;
use AIWP\Designer\Security\Sanitizer;

/**
 * The field model of one page: sections, each holding fields.
 *
 * Field path  : "hero.headline"
 * ACF name    : "hero_headline"
 * Field id    : immutable once content exists
 */
final class PageSchema {

	/** Field types this plugin will register without ACF Pro. */
	public const SUPPORTED_TYPES = array(
		'text', 'textarea', 'number', 'email', 'url', 'image', 'file',
		'wysiwyg', 'select', 'checkbox', 'radio', 'true_false', 'link', 'aiwp_repeater',
	);

	/** Types allowed inside an aiwp_repeater (no nesting in MVP). */
	public const SUPPORTED_SUB_TYPES = array(
		'text', 'textarea', 'number', 'email', 'url', 'image', 'wysiwyg', 'select', 'true_false', 'link',
	);

	/** @var array<int,array<string,mixed>> */
	private array $sections;

	/**
	 * @param array<int,array<string,mixed>> $sections
	 */
	private function __construct( array $sections ) {
		$this->sections = $sections;
	}

	/**
	 * @param array<int,array<string,mixed>> $raw
	 */
	public static function from_array( array $raw ): self {
		$sections = array();

		foreach ( $raw as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$id = Sanitizer::key( $section['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$fields = array();
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$normalised = self::normalise_field( $field );
				if ( null !== $normalised ) {
					$fields[ $normalised['name'] ] = $normalised;
				}
			}

			$sections[] = array(
				'id'     => $id,
				'label'  => Sanitizer::text( $section['label'] ?? ucwords( str_replace( '_', ' ', $id ) ) ),
				// Added by the site owner rather than by the AI. The flag is what
				// stops the next AI edit quietly deleting their work.
				'owner'  => ! empty( $section['owner'] ),
				'fields' => $fields,
			);
		}

		return new self( $sections );
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>|null
	 */
	private static function normalise_field( array $field, bool $is_sub = false ): ?array {
		$name = Sanitizer::key( $field['name'] ?? ( $field['id'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		$type    = Sanitizer::key( $field['type'] ?? 'text' );
		$allowed = $is_sub ? self::SUPPORTED_SUB_TYPES : self::SUPPORTED_TYPES;
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'text';
		}

		$out = array(
			'id'           => Sanitizer::key( $field['id'] ?? $name ),
			'name'         => $name,
			'label'        => Sanitizer::text( $field['label'] ?? ucwords( str_replace( '_', ' ', $name ) ) ),
			'type'         => $type,
			'required'     => ! empty( $field['required'] ),
			'instructions' => Sanitizer::text( $field['instructions'] ?? '' ),
		);

		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$choices = array();
			foreach ( $field['choices'] as $key => $label ) {
				$choices[ Sanitizer::key( is_int( $key ) ? $label : $key ) ] = Sanitizer::text( $label );
			}
			$out['choices'] = $choices;
		}

		if ( 'aiwp_repeater' === $type ) {
			$subs = array();
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$normalised = self::normalise_field( $sub, true );
				if ( null !== $normalised ) {
					$subs[ $normalised['name'] ] = $normalised;
				}
			}
			$out['sub_fields'] = $subs;
			$out['min']        = isset( $field['min'] ) ? absint( $field['min'] ) : 0;
			$out['max']        = isset( $field['max'] ) ? absint( $field['max'] ) : 0;
		}

		return $out;
	}

	/**
	 * The sections a person added, not the AI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function owner_sections(): array {
		return array_values( array_filter( $this->sections, static fn( array $s ): bool => ! empty( $s['owner'] ) ) );
	}

	/**
	 * The sections the AI owns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ai_sections(): array {
		return array_values( array_filter( $this->sections, static fn( array $s ): bool => empty( $s['owner'] ) ) );
	}

	public function has_owner_sections(): bool {
		return array() !== $this->owner_sections();
	}

	/**
	 * A new schema from someone else's sections, keeping every section this one
	 * says the owner added.
	 *
	 * The AI sends the whole section list on every update, so without this a
	 * redesign silently removes anything a person added since the last one. An
	 * id collision resolves in the owner's favour: it is their site.
	 *
	 * @param array<int,array<string,mixed>> $incoming
	 */
	public function keeping_owner_sections( array $incoming ): self {
		$mine = $this->owner_sections();
		if ( array() === $mine ) {
			return self::from_array( $incoming );
		}

		$reserved = array_flip( array_column( $mine, 'id' ) );

		$kept = array();
		foreach ( $incoming as $section ) {
			$id = is_array( $section ) ? Sanitizer::key( $section['id'] ?? '' ) : '';
			if ( '' !== $id && isset( $reserved[ $id ] ) ) {
				continue;
			}
			$kept[] = $section;
		}

		return self::from_array( array_merge( $kept, $mine ) );
	}

	/**
	 * This schema plus extra sections, for validating a template that also
	 * reads fields the schema does not own — an article's title and body come
	 * from WordPress, not from ACF.
	 *
	 * @param array<int,array<string,mixed>> $extra
	 */
	public function with_sections( array $extra ): self {
		return self::from_array( array_merge( $this->sections, $extra ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function to_array(): array {
		$out = array();
		foreach ( $this->sections as $section ) {
			$out[] = array(
				'id'     => $section['id'],
				'label'  => $section['label'],
				// Kept, or the flag is lost the first time a schema is stored and
				// the next AI write deletes a section somebody added by hand.
				'owner'  => ! empty( $section['owner'] ),
				'fields' => array_values( $section['fields'] ),
			);
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * @return array<string,array<string,mixed>> path => field definition
	 */
	public function fields(): array {
		$out = array();
		foreach ( $this->sections as $section ) {
			foreach ( $section['fields'] as $field ) {
				$out[ $section['id'] . '.' . $field['name'] ] = $field;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function field( string $path ): ?array {
		$fields = $this->fields();
		return $fields[ strtolower( $path ) ] ?? null;
	}

	public function has_field( string $path ): bool {
		return null !== $this->field( $path );
	}

	public function is_repeater( string $path ): bool {
		$field = $this->field( $path );
		return $field && 'aiwp_repeater' === $field['type'];
	}

	/**
	 * One subfield of a repeater, or null when it does not exist.
	 *
	 * @return array<string,mixed>|null
	 */
	public function sub_field( string $path, string $sub ): ?array {
		$field = $this->field( $path );

		if ( null === $field || 'aiwp_repeater' !== $field['type'] ) {
			return null;
		}

		$subs = (array) ( $field['sub_fields'] ?? array() );

		return isset( $subs[ $sub ] ) && is_array( $subs[ $sub ] ) ? $subs[ $sub ] : null;
	}

	public function has_subfield( string $path, string $sub ): bool {
		$field = $this->field( $path );
		if ( ! $field || 'aiwp_repeater' !== $field['type'] ) {
			return false;
		}
		return isset( $field['sub_fields'][ strtolower( $sub ) ] );
	}

	/**
	 * "hero.headline" -> "hero_headline"
	 */
	public static function acf_name( string $path ): string {
		return str_replace( '.', '_', strtolower( $path ) );
	}

	public function is_empty(): bool {
		return array() === $this->fields();
	}

	/**
	 * Deterministic problems the AI should fix before we store the page.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = array();

		if ( array() === $this->sections ) {
			$errors[] = 'Schema has no sections.';
		}

		$seen = array();
		$keys = array();

		foreach ( $this->sections as $section ) {
			if ( isset( $seen[ $section['id'] ] ) ) {
				$errors[] = sprintf( 'Duplicate section id "%s".', $section['id'] );
			}
			$seen[ $section['id'] ] = true;

			foreach ( $section['fields'] as $field ) {
				if ( 'aiwp_repeater' === $field['type'] && array() === ( $field['sub_fields'] ?? array() ) ) {
					$errors[] = sprintf( 'Repeater "%s.%s" has no sub_fields.', $section['id'], $field['name'] );
				}

				$path  = $section['id'] . '.' . $field['name'];
				$print = FieldKeyGenerator::fingerprint( (string) $section['id'], (string) $field['name'] );

				if ( isset( $keys[ $print ] ) ) {
					$errors[] = self::collision( $keys[ $print ], $path );
				} else {
					$keys[ $print ] = $path;
				}

				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					$sub_path  = $path . '.' . $sub['name'];
					$sub_print = FieldKeyGenerator::sub_fingerprint(
						(string) $section['id'],
						(string) $field['name'],
						(string) $sub['name']
					);

					if ( isset( $keys[ $sub_print ] ) ) {
						$errors[] = self::collision( $keys[ $sub_print ], $sub_path );
					} else {
						$keys[ $sub_print ] = $sub_path;
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Two paths that are stored in the same place. Say both, and say what to
	 * do, because the fix is never obvious from the names alone.
	 */
	private static function collision( string $first, string $second ): string {
		return sprintf(
			'"%s" and "%s" would be stored in the same place, so one would overwrite the other. Underscores in an id are dropped when the two halves are joined, which makes these two the same. Rename one of them.',
			$first,
			$second
		);
	}
}
