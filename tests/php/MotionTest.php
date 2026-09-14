<?php
declare( strict_types = 1 );

use AIWP\Designer\Design\DesignSystem;
use PHPUnit\Framework\TestCase;

/**
 * Motion is a decision the whole site shares, not something each page invents.
 */
final class MotionTest extends TestCase {

	public function test_the_design_system_has_motion_to_decide(): void {
		$tokens = DesignSystem::defaults()->tokens();

		$this->assertArrayHasKey( 'motion', $tokens );
		$this->assertArrayHasKey( 'fast', $tokens['motion'] );
		$this->assertArrayHasKey( 'base', $tokens['motion'] );
		$this->assertArrayHasKey( 'slow', $tokens['motion'] );
		$this->assertArrayHasKey( 'ease', $tokens['motion'] );
		$this->assertArrayHasKey( 'travel', $tokens['motion'] );
	}

	public function test_motion_reaches_the_page_as_variables(): void {
		$css = DesignSystem::defaults()->to_css();

		foreach ( array( 'fast', 'base', 'slow', 'ease', 'travel' ) as $name ) {
			$this->assertStringContainsString( '--aiwp-motion-' . $name . ':', $css );
		}
	}

	public function test_a_site_can_choose_its_own_timing(): void {
		$system = DesignSystem::from_array(
			array(
				'motion' => array( 'base' => '90ms', 'ease' => 'cubic-bezier(.2,0,0,1)' ),
			)
		);

		$css = $system->to_css();

		$this->assertStringContainsString( '--aiwp-motion-base: 90ms;', $css );
		$this->assertStringContainsString( '--aiwp-motion-ease: cubic-bezier(.2,0,0,1);', $css );
		// What it did not say keeps the starting value rather than disappearing.
		$this->assertStringContainsString( '--aiwp-motion-fast:', $css );
	}

	/**
	 * The reveal behaviour ships with the plugin, so it has to follow the site
	 * rather than move at a speed the plugin picked.
	 */
	public function test_reveal_borrows_the_site_timing(): void {
		$base = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/dist/base.css' );

		$this->assertStringContainsString( 'var(--aiwp-motion-slow', $base );
		$this->assertStringContainsString( 'var(--aiwp-motion-travel', $base );
		// And still stops dead for anyone who asked for less movement.
		$this->assertStringContainsString( 'prefers-reduced-motion', $base );
	}

	/**
	 * Brackets are stripped from every other token because that is how CSS
	 * injection gets in. Motion needs them, so the allowed shapes are named.
	 */
	public function test_an_easing_curve_survives_but_an_attack_does_not(): void {
		$cases = array(
			'cubic-bezier(.2, 0, 0, 1)'        => 'cubic-bezier(.2,0,0,1)',
			'steps(4, jump-end)'               => 'steps(4,jump-end)',
			'ease-in-out'                      => 'ease-in-out',
			'url(https://evil.test/x.css)'     => 'ease',
			'ease; } body { display: none'     => 'ease',
			'expression(alert(1))'             => 'ease',
			'cubic-bezier(1,2,3)'              => 'ease',
		);

		foreach ( $cases as $sent => $expected ) {
			$css = DesignSystem::from_array( array( 'motion' => array( 'ease' => $sent ) ) )->to_css();
			$this->assertStringContainsString( '--aiwp-motion-ease: ' . $expected . ';', $css, $sent );
		}
	}

	public function test_a_rejected_time_keeps_the_one_already_there(): void {
		$css = DesignSystem::from_array( array( 'motion' => array( 'base' => 'quickly' ) ) )->to_css();

		$this->assertStringContainsString( '--aiwp-motion-base: 200ms;', $css );
		$this->assertStringNotContainsString( '--aiwp-motion-base: ;', $css );
	}
}
