<?php
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * A page repeating itself, versus a comparison doing its job.
 *
 * Three campus cards listing the same office hours is correct — that is what a
 * comparison is for. Flagging it teaches whoever reads the report to skim, and
 * then the genuinely repeated sentence further down goes past unread.
 *
 * A value in a table has no full stop. A sentence somebody wrote twice does.
 */
final class PageInspectorRepeatsTest extends TestCase {

	/** @return array<string,int> */
	private function repeats( string $text ): array {
		$parts = preg_split( '/(?<=[.!?])\s+|\n+/', $text ) ?: array();
		$seen  = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( strlen( $part ) < 40 ) {
				continue;
			}

			if ( ! preg_match( '/[.!?]["\')\]]?$/u', $part ) ) {
				continue;
			}

			$key          = strtolower( (string) preg_replace( '/\s+/', ' ', $part ) );
			$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
		}

		return array_filter( $seen, static fn( int $n ): bool => $n > 1 );
	}

	public function test_the_same_sentence_written_twice_is_caught(): void {
		$text = 'We answer the phone at two in the morning and tell you the price. '
			. 'Something else entirely goes here in between. '
			. 'We answer the phone at two in the morning and tell you the price.';

		$this->assertNotSame( array(), $this->repeats( $text ) );
	}

	/** The one that was wrong: a fact repeated across cards being compared. */
	public function test_a_value_repeated_across_cards_is_not(): void {
		$text = "Office Office 8.00-16.30, Monday to Friday\nOffice Office 8.00-16.30, Monday to Friday";

		$this->assertSame( array(), $this->repeats( $text ) );
	}

	public function test_a_repeated_label_is_too_short_to_count(): void {
		$this->assertSame( array(), $this->repeats( "Book a visit.\nBook a visit." ) );
	}
}
