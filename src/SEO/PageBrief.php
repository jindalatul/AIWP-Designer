<?php
declare( strict_types = 1 );

namespace AIWP\Designer\SEO;

use AIWP\Designer\Security\Sanitizer;

/**
 * What a page is supposed to do, recorded so something can check whether it did.
 *
 * The plugin does not care where this came from. ConvertRank's brief maps onto
 * it almost one to one, a person can type it in, and another tool can post it.
 * That is the point: the plugin owns the checking, not the strategy.
 *
 * Everything here is optional. A page with no brief still gets every check that
 * needs no opinion — title length, one h1, alt text, canonical, contrast.
 */
final class PageBrief {

	public const META = '_aiwp_brief';

	/** @var array<string,mixed> */
	private array $data;

	/**
	 * @param array<string,mixed> $data
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	public static function empty(): self {
		return new self( self::defaults() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return array(
			'primary_keyword'   => '',
			'secondary_keywords' => array(),
			'search_intent'     => '',
			'funnel_stage'      => '',
			'meta_title'        => '',
			'meta_description'  => '',
			'questions'         => array(),
			'entities'          => array(),
			'schema_types'      => array(),
			'internal_links'    => array(),
			'source'            => '',
			'source_id'         => '',
			'updated_at'        => '',
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	public static function from_array( array $raw ): self {
		$out = self::defaults();

		foreach ( array( 'primary_keyword', 'search_intent', 'funnel_stage', 'source', 'source_id' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = Sanitizer::text( $raw[ $key ] );
			}
		}

		// Meta text is longer and may legitimately contain punctuation.
		foreach ( array( 'meta_title' => 120, 'meta_description' => 320 ) as $key => $limit ) {
			if ( isset( $raw[ $key ] ) ) {
				$out[ $key ] = substr( Sanitizer::text( $raw[ $key ] ), 0, $limit );
			}
		}

		foreach ( array( 'secondary_keywords', 'questions', 'entities', 'schema_types' ) as $key ) {
			$out[ $key ] = self::strings( $raw[ $key ] ?? array() );
		}

		$out['internal_links'] = self::links( $raw['internal_links'] ?? array() );
		$out['updated_at']     = gmdate( 'c' );

		return new self( $out );
	}

	/**
	 * @return string[]
	 */
	private static function strings( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			// An entity or a link may arrive as an object; take its text.
			if ( is_array( $item ) ) {
				$item = $item['text'] ?? ( $item['entity_text'] ?? ( $item['name'] ?? '' ) );
			}

			$clean = Sanitizer::text( $item );
			if ( '' !== $clean ) {
				$out[] = substr( $clean, 0, 300 );
			}

			if ( count( $out ) >= 40 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @return array<int,array{anchor:string,url:string}>
	 */
	private static function links( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}

			$out[] = array(
				'anchor' => Sanitizer::text( $item['anchor'] ?? $item['title'] ?? '' ),
				'url'    => $url,
			);

			if ( count( $out ) >= 30 ) {
				break;
			}
		}

		return $out;
	}

	public static function for_post( int $post_id ): self {
		$stored = get_post_meta( $post_id, self::META, true );

		return is_array( $stored ) ? new self( array_merge( self::defaults(), $stored ) ) : self::empty();
	}

	public function save( int $post_id ): bool {
		return false !== update_post_meta( $post_id, self::META, $this->data );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	public function is_empty(): bool {
		foreach ( array( 'primary_keyword', 'meta_title', 'meta_description' ) as $key ) {
			if ( '' !== (string) $this->data[ $key ] ) {
				return false;
			}
		}

		foreach ( array( 'questions', 'entities', 'secondary_keywords' ) as $key ) {
			if ( array() !== (array) $this->data[ $key ] ) {
				return false;
			}
		}

		return true;
	}
}
