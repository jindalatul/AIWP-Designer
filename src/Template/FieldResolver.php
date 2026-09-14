<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * Looks a dotted field path up in the page's normalised content array.
 */
final class FieldResolver {

	/** @var array<string,mixed> */
	private array $content;

	/** @var array<string,mixed>|null Current repeater row inside an {{#each}}. */
	private ?array $row = null;

	/**
	 * @param array<string,mixed> $content
	 */
	public function __construct( array $content ) {
		$this->content = $content;
	}

	/**
	 * @param array<string,mixed>|null $row
	 */
	public function with_row( ?array $row ): self {
		$clone      = clone $this;
		$clone->row = $row;
		return $clone;
	}

	public function resolve( string $path ): mixed {
		if ( 0 === strpos( $path, '@item' ) ) {
			if ( null === $this->row ) {
				return null;
			}
			$sub = substr( $path, 6 );
			if ( '' === $sub ) {
				return $this->row;
			}
			return $this->row[ $sub ] ?? null;
		}

		$segments = explode( '.', $path );
		$cursor   = $this->content;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Empty string, empty array, null, false and 0-as-empty all count as "no value".
	 */
	public function is_truthy( string $path ): bool {
		$value = $this->resolve( $path );

		if ( null === $value || false === $value ) {
			return false;
		}
		if ( is_array( $value ) ) {
			return array() !== $value;
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		return (bool) $value;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( string $path ): array {
		$value = $this->resolve( $path );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}
}
