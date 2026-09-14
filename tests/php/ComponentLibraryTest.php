<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\ComponentLibrary;
use PHPUnit\Framework\TestCase;

final class ComponentLibraryTest extends TestCase {

	private function library(): ComponentLibrary {
		return ComponentLibrary::from_array(
			array(
				array( 'id' => 'btn', 'name' => 'Button', 'use_when' => 'Any action.', 'css' => '.btn { padding: 8px; } .btn--quiet { padding: 4px; }' ),
				array( 'id' => 'panel', 'name' => 'Panel', 'use_when' => 'Grouped facts.', 'css' => '.panel { border: 1px solid #eee; }' ),
			)
		);
	}

	public function test_entries_are_read_back(): void {
		$this->assertSame( 2, $this->library()->count() );
	}

	public function test_the_index_leaves_the_css_out(): void {
		$index = $this->library()->index();
		$this->assertArrayNotHasKey( 'css', $index[0] );
		$this->assertSame( 'Any action.', $index[0]['use_when'] );
	}

	public function test_every_class_the_library_defines_is_listed(): void {
		$classes = $this->library()->class_names();
		$this->assertContains( 'btn', $classes );
		$this->assertContains( 'btn--quiet', $classes );
		$this->assertContains( 'panel', $classes );
	}

	public function test_the_compiled_css_carries_every_component(): void {
		$css = $this->library()->css();
		$this->assertStringContainsString( '.btn {', $css );
		$this->assertStringContainsString( '.panel {', $css );
		$this->assertStringContainsString( 'Grouped facts.', $css );
	}

	public function test_merging_replaces_by_id_and_keeps_the_rest(): void {
		$merged = $this->library()->merged_with(
			array( array( 'id' => 'btn', 'name' => 'Button', 'use_when' => 'Changed.', 'css' => '.btn { padding: 99px; }' ) )
		);

		$this->assertSame( 2, $merged->count() );
		$this->assertStringContainsString( '99px', $merged->css() );
		$this->assertStringContainsString( '.panel', $merged->css() );
	}

	public function test_merging_adds_something_new(): void {
		$merged = $this->library()->merged_with(
			array( array( 'id' => 'quote', 'name' => 'Quote', 'use_when' => 'A customer said it.', 'css' => '.quote { margin: 0; }' ) )
		);

		$this->assertSame( 3, $merged->count() );
	}

	public function test_removing_takes_one_out(): void {
		$this->assertSame( 1, $this->library()->without( array( 'btn' ) )->count() );
	}

	public function test_an_entry_with_no_id_is_dropped(): void {
		$this->assertSame( 0, ComponentLibrary::from_array( array( array( 'name' => 'No id', 'css' => '.x{}' ) ) )->count() );
	}

	public function test_the_same_id_twice_is_kept_once(): void {
		$library = ComponentLibrary::from_array(
			array(
				array( 'id' => 'a', 'css' => '.a{ padding: 1px; }' ),
				array( 'id' => 'a', 'css' => '.a{ padding: 2px; }' ),
			)
		);

		$this->assertSame( 1, $library->count() );
	}

	public function test_markup_in_a_use_when_line_is_stripped(): void {
		$library = ComponentLibrary::from_array(
			array( array( 'id' => 'a', 'use_when' => '<script>alert(1)</script>Use it here.', 'css' => '.a{}' ) )
		);

		$this->assertStringNotContainsString( '<script>', $library->to_array()[0]['use_when'] );
	}

	public function test_the_library_has_a_ceiling(): void {
		$many = array();
		for ( $i = 0; $i < ComponentLibrary::MAX_COMPONENTS + 20; $i++ ) {
			$many[] = array( 'id' => 'c' . $i, 'css' => '.c' . $i . '{}' );
		}

		$this->assertSame( ComponentLibrary::MAX_COMPONENTS, ComponentLibrary::from_array( $many )->count() );
	}

	public function test_an_empty_library_says_so(): void {
		$this->assertTrue( ComponentLibrary::empty()->is_empty() );
		$this->assertSame( '', ComponentLibrary::empty()->css() );
	}
}
