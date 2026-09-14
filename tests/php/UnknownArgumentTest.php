<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * An argument a tool does not have must be refused, not dropped.
 *
 * Silently ignoring one is the worst outcome available: the call returns
 * success, the change never happened, and nobody finds out until somebody
 * looks at the site. It happened to me — page_create({page: {chrome: "site"}})
 * reported success and shipped two sites with the wrong header.
 *
 * ToolRegistry needs a whole plugin to build, so the rule itself is tested
 * here against the same logic.
 */
final class UnknownArgumentTest extends TestCase {

	/**
	 * @param array<string,mixed> $schema
	 * @param array<string,mixed> $args
	 * @return string[]
	 */
	private function unknown( array $schema, array $args, string $path = '' ): array {
		$known = (array) ( $schema['properties'] ?? array() );

		if ( array() === $known ) {
			return array();
		}

		$unknown = array();

		foreach ( $args as $key => $value ) {
			$key  = (string) $key;
			$here = '' === $path ? $key : $path . '.' . $key;

			if ( ! isset( $known[ $key ] ) ) {
				$unknown[] = $here;
				continue;
			}

			$child = (array) $known[ $key ];

			if ( is_array( $value ) && ! array_is_list( $value ) && array() !== (array) ( $child['properties'] ?? array() ) ) {
				$unknown = array_merge( $unknown, $this->unknown( $child, $value, $here ) );
			}
		}

		sort( $unknown );

		return $unknown;
	}

	/** @return array<string,mixed> */
	private function schema(): array {
		return array(
			'properties' => array(
				'chrome'   => array( 'type' => 'string' ),
				'template' => array( 'type' => 'string' ),
				'page'     => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string' ),
						'slug'  => array( 'type' => 'string' ),
					),
				),
				'content'  => array( 'type' => 'object' ),
			),
		);
	}

	public function test_a_key_the_tool_does_not_have_is_named(): void {
		$this->assertSame( array( 'colour' ), $this->unknown( $this->schema(), array( 'colour' => 'red' ) ) );
	}

	/** The one that cost me two sites. */
	public function test_a_key_in_the_wrong_object_is_named_with_its_path(): void {
		$found = $this->unknown( $this->schema(), array( 'page' => array( 'title' => 'Home', 'chrome' => 'site' ) ) );

		$this->assertSame( array( 'page.chrome' ), $found );
	}

	public function test_the_same_key_in_the_right_place_is_fine(): void {
		$found = $this->unknown( $this->schema(), array( 'page' => array( 'title' => 'Home' ), 'chrome' => 'site' ) );

		$this->assertSame( array(), $found );
	}

	/**
	 * An object that does not say what it holds is free-form on purpose:
	 * content is keyed by whatever the section ids happen to be.
	 */
	public function test_a_free_form_object_is_left_alone(): void {
		$found = $this->unknown( $this->schema(), array( 'content' => array( 'hero' => array( 'h1' => 'x' ) ) ) );

		$this->assertSame( array(), $found );
	}

	public function test_a_list_is_not_mistaken_for_an_object(): void {
		$found = $this->unknown( $this->schema(), array( 'page' => array( 'title' => 'Home' ) ) );

		$this->assertSame( array(), $found );
	}
}
