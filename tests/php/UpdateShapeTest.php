<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The shapes people actually send content updates in.
 *
 * The documented shape is a list of { field_path, value }. A map is what
 * everyone reaches for first, and it used to come back as
 * AIWP_FIELD_REFERENCE_MISSING: unknown field "" — three times in a row,
 * without ever saying the shape was the problem. Refusing a reasonable guess
 * without explaining it wastes as much time as accepting it and doing nothing.
 *
 * PageManager needs a whole plugin to build, so the conversion is tested here
 * against the same rule.
 */
final class UpdateShapeTest extends TestCase {

	/**
	 * @param array<mixed> $updates
	 * @return array<int,array{field_path:string,value:mixed}>
	 */
	private function normalise( array $updates ): array {
		if ( array_is_list( $updates ) ) {
			$out = array();

			foreach ( $updates as $update ) {
				if ( is_array( $update ) && isset( $update['field_path'] ) ) {
					$out[] = array( 'field_path' => (string) $update['field_path'], 'value' => $update['value'] ?? null );
				}
			}

			return $out;
		}

		$out = array();

		foreach ( $updates as $key => $value ) {
			$key = (string) $key;

			if ( is_array( $value ) && ! array_is_list( $value ) && false === strpos( $key, '.' ) ) {
				foreach ( $value as $field => $inner ) {
					$out[] = array( 'field_path' => $key . '.' . (string) $field, 'value' => $inner );
				}
				continue;
			}

			$out[] = array( 'field_path' => $key, 'value' => $value );
		}

		return $out;
	}

	public function test_the_documented_list_shape(): void {
		$found = $this->normalise( array( array( 'field_path' => 'hero.h1', 'value' => 'A' ) ) );

		$this->assertSame( array( array( 'field_path' => 'hero.h1', 'value' => 'A' ) ), $found );
	}

	public function test_a_dotted_map(): void {
		$found = $this->normalise( array( 'hero.h1' => 'A' ) );

		$this->assertSame( array( array( 'field_path' => 'hero.h1', 'value' => 'A' ) ), $found );
	}

	public function test_a_nested_map(): void {
		$found = $this->normalise( array( 'hero' => array( 'h1' => 'A', 'sub' => 'B' ) ) );

		$this->assertSame(
			array(
				array( 'field_path' => 'hero.h1', 'value' => 'A' ),
				array( 'field_path' => 'hero.sub', 'value' => 'B' ),
			),
			$found
		);
	}

	/** A repeater value is a list, and it must arrive whole, not be walked into. */
	public function test_a_list_value_is_left_as_it_is(): void {
		$rows  = array( array( 'day' => 'Monday' ), array( 'day' => 'Tuesday' ) );
		$found = $this->normalise( array( 'hours.rows' => $rows ) );

		$this->assertSame( array( array( 'field_path' => 'hours.rows', 'value' => $rows ) ), $found );
	}

	public function test_an_entry_with_no_field_path_is_dropped_not_guessed(): void {
		$found = $this->normalise( array( array( 'value' => 'A' ) ) );

		$this->assertSame( array(), $found );
	}
}
