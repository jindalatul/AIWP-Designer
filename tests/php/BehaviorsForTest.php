<?php
declare( strict_types = 1 );

use AIWP\Designer\Rendering\AssetManager;
use PHPUnit\Framework\TestCase;

/**
 * A template that uses a behavior must get the script that runs it.
 *
 * The markup and the manifest used to be two separate statements that could
 * disagree, and when they disagreed the page did not error — it rendered a
 * band of nothing. The baseline CSS hides a reveal until the script adds
 * is-revealed, and the script is only enqueued when the manifest lists the
 * behavior. Three pages of a real site shipped with four invisible steps.
 */
final class BehaviorsForTest extends TestCase {

	public function test_a_behavior_in_the_markup_is_enough(): void {
		$template = '<ul><li data-aiwp-behavior="reveal">a</li></ul>';

		$this->assertSame( array( 'reveal' ), AssetManager::behaviors_for( $template ) );
	}

	public function test_two_behaviors_on_one_element(): void {
		$template = '<div data-aiwp-behavior="reveal sticky">x</div>';

		$this->assertSame( array( 'reveal', 'sticky' ), AssetManager::behaviors_for( $template ) );
	}

	public function test_the_same_behavior_many_times_is_listed_once(): void {
		$template = '<i data-aiwp-behavior="reveal"></i><i data-aiwp-behavior="reveal"></i>';

		$this->assertSame( array( 'reveal' ), AssetManager::behaviors_for( $template ) );
	}

	public function test_what_was_declared_is_kept_even_if_the_markup_moved_on(): void {
		$found = AssetManager::behaviors_for( '<div data-aiwp-behavior="reveal"></div>', array( 'accordion' ) );

		$this->assertSame( array( 'accordion', 'reveal' ), $found );
	}

	public function test_single_quotes_count_too(): void {
		$this->assertSame( array( 'tabs' ), AssetManager::behaviors_for( "<div data-aiwp-behavior='tabs'></div>" ) );
	}

	/** Nothing that is not a real behavior gets through this route. */
	public function test_a_name_we_do_not_have_is_ignored(): void {
		$this->assertSame( array(), AssetManager::behaviors_for( '<div data-aiwp-behavior="mine"></div>' ) );
	}

	public function test_a_page_with_no_behaviors_asks_for_nothing(): void {
		$this->assertSame( array(), AssetManager::behaviors_for( '<main><h1>Hello</h1></main>' ) );
	}
}
