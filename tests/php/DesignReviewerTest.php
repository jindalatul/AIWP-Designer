<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\ComponentLibrary;
use AIWP\Designer\Design\DesignReviewer;
use AIWP\Designer\Design\DesignSystem;
use PHPUnit\Framework\TestCase;

final class DesignReviewerTest extends TestCase {

	private function system(): DesignSystem {
		return new DesignSystem(
			array(
				'colors'     => array(
					'primary' => '#4d2559',
					'text'    => '#16101b',
					'surface' => '#f8fafc',
					'muted'   => '#6b5f72',
				),
				'typography' => array( 'base_size' => '16px', 'scale' => '1.25' ),
				'spacing'    => array( 'xs' => '8px', 'sm' => '16px', 'md' => '32px', 'lg' => '64px' ),
				'radius'     => array( 'small' => '4px', 'large' => '8px' ),
			)
		);
	}

	/** A page that follows its own design system. */
	private function good_css(): string {
		return '
		.a { color: #16101b; background-color: #f8fafc; font-size: 16px; padding: 16px; border-radius: 4px; }
		.b { font-size: 20px; margin: 32px; }
		.c { font-size: 39px; line-height: 1.1; }
		.d:hover { color: #4d2559; }
		.d:focus-visible { outline: 2px solid #4d2559; }
		.e { transition: color var(--aiwp-motion-fast) var(--aiwp-motion-ease); max-width: 68ch; }
		@media (max-width: 700px) { .a { padding: 8px; } }
		';
	}

	private function library(): ComponentLibrary {
		return ComponentLibrary::from_array(
			array(
				array( 'id' => 'btn', 'name' => 'Button', 'use_when' => 'Any action.', 'css' => '.btn { background: #4d2559; padding: 16px; }' ),
				array( 'id' => 'panel', 'name' => 'Panel', 'use_when' => 'Grouped facts.', 'css' => '.panel { border: 1px solid #e2dde6; padding: 32px; }' ),
			)
		);
	}

	private function review( string $css, string $html = '<main><h1>A</h1><p>b</p></main>', string $inherited = '', ?ComponentLibrary $library = null ): array {
		return ( new DesignReviewer( $css, $html, $this->system(), $inherited, $library ?? $this->library() ) )->review();
	}

	private function ids( array $review ): array {
		return array_column( $review['findings'], 'id' );
	}

	public function test_a_page_that_follows_the_system_scores_full_marks(): void {
		$review = $this->review( $this->good_css() );
		$this->assertSame( 100, $review['score'], implode( ' | ', $this->ids( $review ) ) );
		$this->assertSame( array(), $review['findings'] );
	}

	public function test_the_review_always_says_what_it_did_not_check(): void {
		$this->assertNotEmpty( $this->review( $this->good_css() )['not_checked'] );
	}

	public function test_a_colour_outside_the_palette_is_found(): void {
		$review = $this->review( $this->good_css() . '.z { color: #ff6600; }' );
		$this->assertContains( 'off_palette_colors', $this->ids( $review ) );
		$this->assertStringContainsString( '#ff6600', $review['findings'][0]['detail'] );
	}

	public function test_a_colour_a_shade_off_a_token_is_not_reported(): void {
		$review = $this->review( $this->good_css() . '.z { color: #4d255a; }' );
		$this->assertNotContains( 'off_palette_colors', $this->ids( $review ) );
	}

	public function test_white_and_black_are_never_off_palette(): void {
		$review = $this->review( $this->good_css() . '.z { color: #fff; background: #000; }' );
		$this->assertNotContains( 'off_palette_colors', $this->ids( $review ) );
	}

	public function test_unreadable_text_on_its_own_background_is_found(): void {
		$review = $this->review( $this->good_css() . '.z { color: #bbbbbb; background-color: #ffffff; font-size: 16px; }' );
		$this->assertContains( 'contrast', $this->ids( $review ) );
	}

	public function test_large_text_is_judged_against_the_lower_bar(): void {
		// 4.2:1 fails for body text but passes for 32px text.
		$css = $this->good_css() . '.z { color: #767676; background-color: #ffffff; font-size: 32px; }';
		$this->assertNotContains( 'contrast', $this->ids( $this->review( $css ) ) );
	}

	public function test_a_see_through_background_is_not_judged(): void {
		$review = $this->review( $this->good_css() . '.z { color: #bbbbbb; background-color: rgba(255,255,255,0.4); }' );
		$this->assertNotContains( 'contrast', $this->ids( $review ) );
	}

	public function test_a_font_size_off_the_scale_is_found(): void {
		$review = $this->review( $this->good_css() . '.z { font-size: 17px; }' );
		$this->assertContains( 'off_scale_type', $this->ids( $review ) );
	}

	public function test_the_top_of_a_clamp_is_the_size_that_is_judged(): void {
		$review = $this->review( $this->good_css() . '.z { font-size: clamp(1rem, 4vw, 2.44rem); }' );
		$this->assertNotContains( 'off_scale_type', $this->ids( $review ) );
	}

	public function test_a_page_with_no_big_text_is_called_timid(): void {
		$css = '.a { font-size: 16px; } .b { font-size: 20px; } .c { font-size: 25px; }';
		$this->assertContains( 'timid_type', $this->ids( $this->review( $css ) ) );
	}

	public function test_big_text_in_the_site_stylesheet_counts_as_the_pages_own(): void {
		$css       = '.a { font-size: 16px; } .b { font-size: 20px; } .c { font-size: 25px; }';
		$inherited = 'h1 { font-size: 64px; }';
		$this->assertNotContains( 'timid_type', $this->ids( $this->review( $css, '<main><h1>A</h1></main>', $inherited ) ) );
	}

	public function test_spacing_off_the_scale_is_found(): void {
		$review = $this->review( $this->good_css() . '.z { padding: 13px; margin: 27px; }' );
		$this->assertContains( 'off_scale_spacing', $this->ids( $review ) );
	}

	public function test_responsive_spacing_is_left_alone(): void {
		$review = $this->review( $this->good_css() . '.z { padding: clamp(13px, 3vw, 27px); }' );
		$this->assertNotContains( 'off_scale_spacing', $this->ids( $review ) );
	}

	public function test_a_sparse_scale_is_blamed_rather_than_the_page(): void {
		$css    = $this->good_css() . '.z { padding: 20px; margin: 24px; gap: 28px; row-gap: 40px; }';
		$review = $this->review( $css );
		$finding = array_values( array_filter( $review['findings'], static fn( $f ) => 'off_scale_spacing' === $f['id'] ) )[0];
		$this->assertStringContainsString( 'multiples of four', $finding['title'] );
		$this->assertSame( 'low', $finding['severity'] );
	}

	public function test_a_missing_focus_state_is_serious(): void {
		$css    = str_replace( '.d:focus-visible { outline: 2px solid #4d2559; }', '', $this->good_css() );
		$html   = '<main><a href="/a">a</a><a href="/b">b</a><button>c</button></main>';
		$review = $this->review( $css, $html );
		$finding = array_values( array_filter( $review['findings'], static fn( $f ) => 'missing_states' === $f['id'] ) )[0];
		$this->assertSame( 'high', $finding['severity'] );
	}

	public function test_a_focus_state_in_the_site_stylesheet_is_enough(): void {
		$css  = str_replace( '.d:focus-visible { outline: 2px solid #4d2559; }', '', $this->good_css() );
		$html = '<main><a href="/a">a</a><a href="/b">b</a><button>c</button></main>';
		$this->assertNotContains( 'missing_states', $this->ids( $this->review( $css, $html, 'a:focus-visible { outline: 2px; }' ) ) );
	}

	public function test_a_page_with_no_media_queries_and_nothing_fluid_is_flagged(): void {
		$css = '.a { color: #16101b; background-color: #f8fafc; font-size: 16px; padding: 16px; width: 1200px; }
			.a:hover { color: #4d2559; } .a:focus { outline: 1px; } .a { transition: color 1ms; }';
		$this->assertContains( 'not_responsive', $this->ids( $this->review( $css ) ) );
	}

	public function test_skipped_heading_levels_are_found(): void {
		$html   = '<main><h1>A</h1><h4>B</h4></main>';
		$review = $this->review( $this->good_css(), $html );
		$this->assertContains( 'heading_levels_skip', $this->ids( $review ) );
	}

	public function test_heading_levels_that_step_down_one_at_a_time_are_fine(): void {
		$html = '<main><h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2></main>';
		$this->assertNotContains( 'heading_levels_skip', $this->ids( $this->review( $this->good_css(), $html ) ) );
	}

	public function test_too_many_font_families_is_found(): void {
		$css = $this->good_css() . '.p{font-family:Archivo} .q{font-family:Newsreader} .r{font-family:Inter} .s{font-family:Lora}';
		$this->assertContains( 'too_many_fonts', $this->ids( $this->review( $css ) ) );
	}

	public function test_findings_are_ordered_worst_first(): void {
		$css  = '.z { color: #bbbbbb; background-color: #ffffff; padding: 13px; }';
		$html = '<main><h1>A</h1><p>b</p></main>';
		$review = $this->review( $css, $html );
		$this->assertNotEmpty( $review['findings'] );
		$this->assertSame( 'high', $review['findings'][0]['severity'] );
	}

	public function test_the_score_never_goes_below_zero(): void {
		$css = '.z{color:#f0f;background:#ff0;font-size:17px;padding:13px;border-radius:7px;width:999px;
			font-family:A} .y{font-family:B} .x{font-family:C} .w{font-family:D}';
		$this->assertGreaterThanOrEqual( 0, $this->review( $css, '<main><h1>A</h1><h5>b</h5></main>' )['score'] );
	}

	public function test_every_finding_says_how_to_fix_it(): void {
		$css = '.z { color: #ff6600; background-color: #ffffff; padding: 13px; font-size: 17px; }';
		foreach ( $this->review( $css )['findings'] as $finding ) {
			$this->assertNotSame( '', trim( $finding['fix'] ), $finding['id'] . ' has no fix' );
			$this->assertNotSame( '', trim( $finding['title'] ), $finding['id'] . ' has no title' );
		}
	}

	public function test_a_site_with_no_component_library_is_told_to_build_one(): void {
		$review = $this->review( $this->good_css(), '<main><h1>A</h1></main>', '', ComponentLibrary::empty() );
		$this->assertContains( 'no_component_library', $this->ids( $review ) );
	}

	public function test_a_page_that_builds_its_own_components_is_found(): void {
		$css = $this->good_css() . '
			.mycard { background: #f8fafc; border: 1px solid #e2dde6; padding: 32px; }
			.mypanel { background: #ffffff; padding: 16px; border-radius: 4px; }
			.mybox { border: 1px solid #e2dde6; padding: 32px; }
			.mytile { background: #f8fafc; padding: 16px; }';
		$this->assertContains( 'page_invents_components', $this->ids( $this->review( $css ) ) );
	}

	public function test_using_the_sites_own_components_is_not_invention(): void {
		$css = $this->good_css() . '
			.btn { background: #4d2559; padding: 16px; }
			.panel { border: 1px solid #e2dde6; padding: 32px; }';
		$this->assertNotContains( 'page_invents_components', $this->ids( $this->review( $css ) ) );
	}

	public function test_laying_a_page_out_is_not_inventing_components(): void {
		$css = $this->good_css() . '
			.hero .panel { padding: 32px; background: #f8fafc; }
			.grid > .panel { padding: 16px; border: 1px solid #e2dde6; }
			.wrap .btn { padding: 8px; background: #4d2559; }';
		$this->assertNotContains( 'page_invents_components', $this->ids( $this->review( $css ) ) );
	}

	public function test_a_page_that_contradicts_the_surface_decision_is_found(): void {
		$system = new DesignSystem(
			array(
				'colors'     => array( 'primary' => '#4d2559', 'text' => '#16101b', 'surface' => '#f8fafc' ),
				'typography' => array( 'base_size' => '16px', 'scale' => '1.25' ),
				'spacing'    => array( 'sm' => '16px', 'md' => '32px' ),
				'radius'     => array( 'small' => '4px' ),
				'style'      => array( 'surface' => 'Hairlines only. Nothing floats.' ),
			)
		);

		$css    = $this->good_css() . '.p { box-shadow: 0 2px 4px #000; } .q { box-shadow: 0 8px 20px #000; }';
		$review = ( new DesignReviewer( $css, '<main><h1>A</h1></main>', $system, '', $this->library() ) )->review();

		$this->assertContains( 'contradicts_style', array_column( $review['findings'], 'id' ) );
	}

	public function test_a_site_that_chose_stillness_is_not_told_to_add_motion(): void {
		$system = new DesignSystem(
			array(
				'colors'     => array( 'primary' => '#4d2559', 'text' => '#16101b' ),
				'typography' => array( 'base_size' => '16px', 'scale' => '1.25' ),
				'spacing'    => array( 'sm' => '16px', 'md' => '32px' ),
				'style'      => array( 'motion' => 'None. The site does not move.' ),
			)
		);

		$css    = '.a { color: #16101b; font-size: 16px; padding: 16px; max-width: 68ch; }
			.a:hover { color: #4d2559; } .a:focus-visible { outline: 1px; }
			@media (max-width: 700px) { .a { padding: 16px; } }';
		$review = ( new DesignReviewer( $css, '<main><h1>A</h1></main>', $system, '', $this->library() ) )->review();

		$this->assertNotContains( 'no_motion', array_column( $review['findings'], 'id' ) );
	}

	public function test_a_clamp_holding_variables_is_read_to_its_maximum(): void {
		// clamp(33px, 5vw, 52px) written with the scale variables.
		$css = '.a { color: #16101b; background-color: #f8fafc; font-size: 16px; padding: 16px; max-width: 68ch; }
			.b { font-size: 20px; } .c { font-size: 25px; }
			.h { font-size: clamp(var(--aiwp-text-2xl), 5vw, var(--aiwp-text-5xl)); }
			.a:hover { color: #4d2559; } .a:focus-visible { outline: 1px; }
			.a { transition: color 1ms; } @media (max-width: 700px) { .a { padding: 8px; } }';

		$this->assertNotContains( 'timid_type', $this->ids( $this->review( $css ) ) );
	}

	public function test_a_type_scale_variable_resolves_to_a_size(): void {
		$css = '.a { font-size: 16px; } .b { font-size: 20px; } .c { font-size: var(--aiwp-text-4xl); }';
		$this->assertNotContains( 'timid_type', $this->ids( $this->review( $css ) ) );
	}

	/**
	 * Motion the whole site shares, versus motion one page made up.
	 */
	public function test_a_page_that_types_its_own_timing_is_noticed(): void {
		$review = $this->review( $this->good_css() . ' .f { transition: opacity 180ms ease; }' );

		$this->assertContains( 'motion_not_shared', $this->ids( $review ) );
	}

	public function test_using_the_site_timing_is_not_noticed(): void {
		$review = $this->review( $this->good_css() . ' .f { transition: opacity var(--aiwp-motion-base) var(--aiwp-motion-ease); }' );

		$this->assertNotContains( 'motion_not_shared', $this->ids( $review ) );
	}

	public function test_a_transition_longer_than_a_second_is_noticed(): void {
		$review = $this->review( $this->good_css() . ' .f { transition: transform 1.4s ease; }' );

		$this->assertContains( 'motion_too_slow', $this->ids( $review ) );
	}

	/** Zero is "off", not a speed, so it disagrees with nothing. */
	public function test_switching_motion_off_is_not_a_disagreement(): void {
		$review = $this->review( $this->good_css() . ' .f { transition: none 0s; }' );

		$this->assertNotContains( 'motion_not_shared', $this->ids( $review ) );
	}

	/**
	 * Type gets tighter as it gets bigger. Left alone, a headline inherits the
	 * body's leading and falls into loose separate lines — the commonest
	 * reason a competent page still looks amateur.
	 */
	public function test_large_text_with_no_leading_is_noticed(): void {
		$review = $this->review( $this->good_css() . ' .big { font-size: 48px; }' );

		$this->assertContains( 'leading_not_decided', $this->ids( $review ) );
	}

	public function test_large_text_with_body_leading_is_noticed(): void {
		$review = $this->review( $this->good_css() . ' .big { font-size: 48px; line-height: 1.6; }' );

		$this->assertContains( 'leading_not_decided', $this->ids( $review ) );
	}

	public function test_large_text_with_tight_leading_is_fine(): void {
		$review = $this->review( $this->good_css() . ' .big { font-size: 48px; line-height: 1.05; }' );

		$this->assertNotContains( 'leading_not_decided', $this->ids( $review ) );
	}

	public function test_body_text_is_left_alone(): void {
		$review = $this->review( $this->good_css() . ' .small { font-size: 17px; }' );

		$this->assertNotContains( 'leading_not_decided', $this->ids( $review ) );
	}

	/** 22px above the last line and 88px below it is what nobody chose. */
	public function test_a_block_with_far_more_space_below_than_above_is_noticed(): void {
		$review = $this->review( $this->good_css() . ' .foot { padding-top: 22px; padding-bottom: 88px; }' );

		$this->assertContains( 'padding_lopsided', $this->ids( $review ) );
	}

	public function test_even_padding_is_fine(): void {
		$review = $this->review( $this->good_css() . ' .foot { padding: 88px 34px; }' );

		$this->assertNotContains( 'padding_lopsided', $this->ids( $review ) );
	}

	/** Small differences cannot be seen and are not worth a finding. */
	public function test_a_small_difference_is_not_worth_saying(): void {
		$review = $this->review( $this->good_css() . ' .chip { padding-top: 4px; padding-bottom: 14px; }' );

		$this->assertNotContains( 'padding_lopsided', $this->ids( $review ) );
	}

	/**
	 * The classic near miss: a grid with a fixed first column starts its
	 * second column at that width plus the gap, and a sibling indented by the
	 * column width alone lands one gap short. Fourteen pixels out — too small
	 * to look deliberate, too large to look right, invisible without measuring.
	 */
	public function test_a_sibling_that_lands_one_gap_short_is_noticed(): void {
		$css = $this->good_css()
			. ' .head { display: grid; grid-template-columns: 56px 1fr; gap: 14px; }'
			. ' .body { margin-left: 56px; }';

		$this->assertContains( 'almost_aligned', $this->ids( $this->review( $css ) ) );
	}

	public function test_a_sibling_that_meets_the_column_is_fine(): void {
		$css = $this->good_css()
			. ' .head { display: grid; grid-template-columns: 56px 1fr; gap: 14px; }'
			. ' .body { margin-left: 70px; }';

		$this->assertNotContains( 'almost_aligned', $this->ids( $this->review( $css ) ) );
	}

	/** Far apart is a hierarchy somebody chose, not an accident. */
	public function test_a_deliberate_indent_is_left_alone(): void {
		$css = $this->good_css()
			. ' .head { display: grid; grid-template-columns: 56px 1fr; gap: 14px; }'
			. ' .body { margin-left: 160px; }';

		$this->assertNotContains( 'almost_aligned', $this->ids( $this->review( $css ) ) );
	}

	/**
	 * A container's gutter is not an attempt to meet anything. Comparing it
	 * against a grid column reports that 34 and 36 disagree, which is true and
	 * means nothing.
	 */
	public function test_a_page_gutter_is_not_compared(): void {
		$css = $this->good_css()
			. ' .wrap { padding: 0 34px; }'
			. ' .head { display: grid; grid-template-columns: 36px 1fr; gap: 0px; }';

		$this->assertNotContains( 'almost_aligned', $this->ids( $this->review( $css ) ) );
	}

	/** A fluid column has no fixed edge, so there is nothing to say. */
	public function test_a_minmax_column_says_nothing(): void {
		$css = $this->good_css()
			. ' .head { display: grid; grid-template-columns: minmax(180px,22ch) 1fr; gap: 14px; }'
			. ' .body { margin-left: 190px; }';

		$this->assertNotContains( 'almost_aligned', $this->ids( $this->review( $css ) ) );
	}

	/**
	 * check_motion already stands down when a site chose stillness on purpose.
	 * Loudness is the same thing one property over: a survey-report site whose
	 * personality says nothing shouts does not want a 90px headline, and
	 * telling it to find one is taste overruling a decision the site made.
	 */
	public function test_a_site_that_said_it_is_quiet_is_not_told_to_shout(): void {
		$loud = new DesignSystem(
			array_merge(
				DesignSystem::defaults()->tokens(),
				array(
					'style' => array(
						'personality' => 'Technical and specific, like a survey report. Nothing shouts.',
						'layout'      => 'A dense data column beside an annotation margin.',
					),
				)
			)
		);

		$markup = '<h1>A</h1><figure><img src="/x.png" alt="A diagram"></figure>';
		$review = ( new DesignReviewer( '.a{font-size:16px} .b{font-size:20px} .c{font-size:30px}', $markup, $loud, '', null ) )->review();

		$this->assertNotContains( 'timid_type', array_column( $review['findings'], 'id' ) );
	}

	/**
	 * Quiet type and nothing to look at is not restraint, it is a page nobody
	 * designed. Letting the written decision excuse both silences the one
	 * check that would have said so.
	 */
	public function test_quiet_plus_nothing_to_look_at_is_still_told(): void {
		$quiet = new DesignSystem(
			array_merge(
				DesignSystem::defaults()->tokens(),
				array( 'style' => array( 'personality' => 'Technical. Nothing shouts.' ) )
			)
		);

		$review = ( new DesignReviewer( '.a{font-size:16px} .b{font-size:20px} .c{font-size:30px}', '<h1>A</h1><p>b</p>', $quiet, '', null ) )->review();

		$this->assertContains( 'timid_type', array_column( $review['findings'], 'id' ) );
	}

	/** The imagery decision is a decision like any other. */
	public function test_a_page_with_no_image_on_a_site_built_on_images(): void {
		$visual = new DesignSystem(
			array_merge(
				DesignSystem::defaults()->tokens(),
				array( 'style' => array( 'imagery' => 'Annotated photographs of the work, and diagrams of systems.' ) )
			)
		);

		$review = ( new DesignReviewer( '.a{color:#16101b}', '<h1>A</h1><p>Words only.</p>', $visual, '', null ) )->review();

		$this->assertContains( 'nothing_to_look_at', array_column( $review['findings'], 'id' ) );
	}

	public function test_a_site_that_said_it_uses_no_imagery_is_left_alone(): void {
		$none = new DesignSystem(
			array_merge(
				DesignSystem::defaults()->tokens(),
				array( 'style' => array( 'imagery' => 'None. Everything that would be a picture is set as type instead.' ) )
			)
		);

		$review = ( new DesignReviewer( '.a{color:#16101b}', '<h1>A</h1><p>Words only.</p>', $none, '', null ) )->review();

		$this->assertNotContains( 'nothing_to_look_at', array_column( $review['findings'], 'id' ) );
	}

	public function test_a_site_that_said_nothing_still_gets_told(): void {
		$review = ( new DesignReviewer( '.a{font-size:16px} .b{font-size:20px} .c{font-size:30px}', '<h1>A</h1>', $this->system(), '', null ) )->review();

		$this->assertContains( 'timid_type', array_column( $review['findings'], 'id' ) );
	}

	/**
	 * The site stylesheet usually sets leading for h1, h2 and h3 once, which
	 * is the right place for it. Reading only the page's own rules reported
	 * that a heading had decided nothing when the site decided it for every
	 * heading it has.
	 */
	public function test_leading_set_by_the_site_stylesheet_counts(): void {
		$review = $this->review(
			$this->good_css() . ' .hero h1 { font-size: 90px; }',
			'<h1>A</h1>',
			'.aiwp-page h1,.aiwp-page h2{ line-height:.96; }'
		);

		$this->assertNotContains( 'leading_not_decided', $this->ids( $review ) );
	}

	/** A 30px standfirst at 1.4 is correctly set; flagging it teaches people to ignore the finding. */
	public function test_a_large_standfirst_is_not_display_type(): void {
		$review = $this->review( $this->good_css() . ' .stand { font-size: 30px; line-height: 1.4; }' );

		$this->assertNotContains( 'leading_not_decided', $this->ids( $review ) );
	}

	/**
	 * White is white however it is written.
	 *
	 * A sticky header writes rgba(255,255,255,.94); a soft shadow writes
	 * rgba(16,42,67,.07) — the first is not a palette decision and the second
	 * is. Matching the literal strings "#fff" and "#ffffff" missed every
	 * translucent one and told the author to replace white with a brand colour.
	 */
	public function test_translucent_white_is_not_an_off_palette_colour(): void {
		$css = '.bar { background: rgba(255, 255, 255, .94); }
		        .lift { box-shadow: 0 1px 0 rgba(0, 0, 0, .06); }';

		$this->assertNotContains( 'off_palette_colors', $this->ids( $this->review( $css ) ) );
	}

	public function test_a_colour_that_is_not_white_or_black_is_still_reported(): void {
		$css = '.bar { background: rgba(122, 85, 16, .9); }';

		$this->assertContains( 'off_palette_colors', $this->ids( $this->review( $css ) ) );
	}

	/**
	 * A header's logo mark is not a page component.
	 *
	 * page_invents_components asks a page to build from the library. The
	 * header and footer ARE the shared thing, and their own pieces — a brand
	 * mark, a menu button, a close link — belong nowhere else. Reporting them
	 * meant every site's header carried a finding whose fix was wrong.
	 */
	public function test_the_header_and_footer_are_not_told_to_reuse_page_components(): void {
		$css = '.mark { background: #4d2559; padding: 8px; }
		        .burger { border: 1px solid #e2dde6; padding: 8px; }
		        .close { background: #f8fafc; padding: 8px; }';

		$page   = new DesignReviewer( $css, '<main><h1>A</h1></main>', $this->system(), '', $this->library() );
		$chrome = $page->reviewing_chrome();

		$this->assertContains( 'page_invents_components', array_column( $page->review()['findings'], 'id' ) );
		$this->assertNotContains( 'page_invents_components', array_column( $chrome->review()['findings'], 'id' ) );
	}
}
