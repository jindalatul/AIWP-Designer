<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\ComponentExtractor;
use AIWP\Designer\Design\ComponentLibrary;
use PHPUnit\Framework\TestCase;

final class ComponentExtractorTest extends TestCase {

	private function propose( string $css, ?ComponentLibrary $library = null ): array {
		return ( new ComponentExtractor( $css, $library ) )->propose();
	}

	private function ids( array $proposed ): array {
		return array_column( $proposed, 'suggested_id' );
	}

	public function test_a_component_with_states_is_proposed(): void {
		$css = '
			.cr-btn { background: #4d2559; color: #fff; padding: 15px 26px; border-radius: 4px; }
			.cr-btn:hover { background: #7b3f8c; }
			.cr-btn:focus-visible { outline: 2px solid #7b3f8c; }';

		$proposed = $this->propose( $css );
		$this->assertSame( array( 'btn' ), $this->ids( $proposed ) );
		$this->assertTrue( $proposed[0]['has_states'] );
	}

	public function test_bem_parts_are_kept_with_their_block(): void {
		$css = '
			.card { background: #fff; border: 1px solid #eee; padding: 24px; }
			.card__title { color: #111; padding: 0; }
			.card--wide { padding: 40px; background: #fafafa; }';

		$proposed = $this->propose( $css );
		$this->assertCount( 1, $proposed );
		$this->assertSame( 'card', $proposed[0]['suggested_id'] );
		$this->assertCount( 3, $proposed[0]['selectors'] );
	}

	public function test_placement_is_not_a_component(): void {
		$css = '
			.hero .btn { padding: 8px; background: #4d2559; }
			.grid > .card { padding: 16px; border: 1px solid #eee; }
			.a + .b { padding: 8px; background: #fff; }';

		$this->assertSame( array(), $this->propose( $css ) );
	}

	public function test_a_single_small_tweak_is_not_a_component(): void {
		$this->assertSame( array(), $this->propose( '.muted { color: #6b5f72; }' ) );
	}

	public function test_layout_only_rules_are_not_components(): void {
		$css = '.wrap { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; max-width: 1240px; }';
		$this->assertSame( array(), $this->propose( $css ) );
	}

	public function test_something_already_in_the_library_is_marked(): void {
		$library = ComponentLibrary::from_array(
			array( array( 'id' => 'btn', 'css' => '.cr-btn { padding: 8px; }' ) )
		);

		$css = '
			.cr-btn { background: #4d2559; color: #fff; padding: 15px; }
			.cr-btn:hover { background: #7b3f8c; }';

		$proposed = $this->propose( $css, $library );
		$this->assertTrue( $proposed[0]['already_in_library'] );
	}

	public function test_the_css_is_given_back_whole(): void {
		$css = '
			.quote { border-left: 3px solid #4d2559; padding: 24px; background: #f8fafc; }
			.quote__name { color: #6b5f72; padding: 0; }';

		$out = $this->propose( $css )[0]['css'];
		$this->assertStringContainsString( '.quote {', $out );
		$this->assertStringContainsString( '.quote__name {', $out );
		$this->assertStringContainsString( 'border-left: 3px solid #4d2559;', $out );
	}

	public function test_media_queries_are_kept_around_their_rules(): void {
		$css = '
			.panel { background: #fff; border: 1px solid #eee; padding: 32px; }
			@media (max-width: 700px) { .panel { padding: 16px; background: #fafafa; } }';

		$out = $this->propose( $css )[0]['css'];
		$this->assertStringContainsString( '@media (max-width: 700px)', $out );
	}

	public function test_a_site_prefix_is_dropped_from_the_suggested_id(): void {
		$css = '.cr-stat { background: #fff; padding: 24px; border: 1px solid #eee; }
			.cr-stat__figure { color: #111; padding: 0; }';
		$this->assertSame( 'stat', $this->propose( $css )[0]['suggested_id'] );
	}

	public function test_the_biggest_component_comes_first(): void {
		$css = '
			.small { background: #fff; padding: 8px; border: 1px solid #eee; }
			.big { background: #fff; padding: 8px; border: 1px solid #eee; }
			.big:hover { background: #eee; }
			.big__head { color: #111; padding: 4px; }
			.big--alt { background: #fafafa; padding: 12px; }';

		$this->assertSame( 'big', $this->propose( $css )[0]['suggested_id'] );
	}
}
