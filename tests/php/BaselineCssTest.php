<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The plugin baseline must never out-weigh the components the AI writes.
 *
 * A rule like `.aiwp-page a` is one class plus one tag, which beats `.cr-btn`.
 * That turned white button text back to body colour and produced dark text on a
 * dark button. Every baseline rule scoped to a wrapper has to use :where(), so
 * it weighs the same as a bare tag and any class wins.
 */
final class BaselineCssTest extends TestCase {

	private const WRAPPERS = array( 'aiwp-page', 'aiwp-chrome', 'aiwp-article' );

	private function css(): string {
		$path = dirname( __DIR__, 2 ) . '/assets/dist/base.css';
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * @return string[]
	 */
	private function selectors(): array {
		return array_keys( $this->rules() );
	}

	/**
	 * Selector => declarations.
	 *
	 * @return array<string,string>
	 */
	private function rules(): array {
		preg_match_all( '/(?:^|\})\s*([^{}@]+)\{([^}]*)\}/m', $this->css(), $m, PREG_SET_ORDER );

		$out = array();
		foreach ( $m as $match ) {
			$out[ trim( $match[1] ) ] = $match[2];
		}

		return $out;
	}

	public function test_a_wrapper_scoped_rule_never_beats_a_single_class(): void {
		$offenders = array();

		foreach ( $this->rules() as $selector => $declarations ) {
			// A rule that says !important is overriding on purpose. The
			// reduced-motion block has to beat everything, and does.
			if ( false !== strpos( $declarations, '!important' ) ) {
				continue;
			}

			foreach ( explode( ',', $selector ) as $part ) {
				$part = trim( $part );

				foreach ( self::WRAPPERS as $wrapper ) {
					// ".aiwp-page h1" is the shape that causes the problem:
					// the wrapper class used bare, with a descendant after it.
					if ( ! preg_match( '/(^|\s)\.' . preg_quote( $wrapper, '/' ) . '\s+\S/', $part ) ) {
						continue;
					}

					// A pseudo-class like :focus-visible is allowed to be strong.
					if ( false !== strpos( $part, ':focus' ) ) {
						continue;
					}

					$offenders[] = $part;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These baseline rules out-weigh a component class. Wrap the scope in :where():\n  " . implode( "\n  ", $offenders )
		);
	}

	public function test_no_selector_is_defined_twice(): void {
		preg_match_all( '/(?:^|\})\s*([^{}@]+)\{/m', $this->css(), $m );
		$counts = array_count_values( array_map( 'trim', $m[1] ) );
		$dupes  = array_keys( array_filter( $counts, static fn( int $n ): bool => $n > 1 ) );

		$this->assertSame( array(), $dupes, 'Defined more than once: ' . implode( ', ', $dupes ) );
	}

	public function test_form_controls_share_one_box_model(): void {
		$this->assertMatchesRegularExpression(
			'/\.aiwp-form__input\{[^}]*box-sizing:\s*border-box/',
			$this->css(),
			'Without box-sizing a text input is content-box and a select is border-box, so a row of fields never lines up.'
		);
	}

	public function test_the_article_wrapper_gets_the_baseline(): void {
		$this->assertStringContainsString( 'aiwp-article', $this->css() );
	}
}
