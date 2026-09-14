<?php
declare( strict_types = 1 );

use AIWP\Designer\Template\TemplateAST;
use AIWP\Designer\Template\TemplateParser;
use PHPUnit\Framework\TestCase;

final class TemplateParserTest extends TestCase {

	private function parse( string $source ): array {
		$parser = new TemplateParser();
		$ast    = $parser->parse( $source );
		return array( $ast, $parser->errors() );
	}

	public function test_plain_markup_becomes_one_text_node(): void {
		list( $ast, $errors ) = $this->parse( '<p>hello</p>' );
		$this->assertSame( array(), $errors );
		$this->assertCount( 1, $ast->children );
		$this->assertSame( TemplateAST::TEXT, $ast->children[0]->kind );
	}

	public function test_interpolation_is_parsed(): void {
		list( $ast, $errors ) = $this->parse( '<h1>{{text:hero.headline}}</h1>' );
		$this->assertSame( array(), $errors );
		$interp = $ast->children[1];
		$this->assertSame( TemplateAST::INTERP, $interp->kind );
		$this->assertSame( 'text', $interp->filter );
		$this->assertSame( 'hero.headline', $interp->path );
	}

	public function test_each_block_nests_its_children(): void {
		list( $ast, $errors ) = $this->parse( '{{#each:a.b}}<li>{{text:@item.title}}</li>{{/each}}' );
		$this->assertSame( array(), $errors );
		$each = $ast->children[0];
		$this->assertSame( TemplateAST::EACH, $each->kind );
		$this->assertSame( 'a.b', $each->path );
		$this->assertSame( '@item.title', $each->children[1]->path );
	}

	public function test_unclosed_block_is_an_error(): void {
		list( , $errors ) = $this->parse( '{{#if:a.b}}oops' );
		$this->assertNotEmpty( $errors );
	}

	public function test_stray_closing_tag_is_an_error(): void {
		list( , $errors ) = $this->parse( 'x{{/each}}' );
		$this->assertNotEmpty( $errors );
	}

	public function test_unknown_filter_is_an_error(): void {
		list( , $errors ) = $this->parse( '{{danger:a.b}}' );
		$this->assertNotEmpty( $errors );
	}

	public function test_invalid_field_path_is_an_error(): void {
		list( , $errors ) = $this->parse( '{{text:a.b.c.d.e}}' );
		$this->assertNotEmpty( $errors );
	}

	public function test_unclosed_brace_does_not_hang(): void {
		list( , $errors ) = $this->parse( 'before {{text:a.b' );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * Malformed input must always parse to something, never throw.
	 */
	public function test_fuzz_never_throws(): void {
		$fragments = array( '{{', '}}', '{{#if:', '{{/if}}', '{{each:a}}', '{{text:}}', '{{:}}', '<div', '@item', '{{#each:a.b}}' );

		for ( $i = 0; $i < 200; $i++ ) {
			$source = '';
			for ( $j = 0, $n = random_int( 1, 8 ); $j < $n; $j++ ) {
				$source .= $fragments[ random_int( 0, count( $fragments ) - 1 ) ];
			}
			$parser = new TemplateParser();
			$this->assertInstanceOf( TemplateAST::class, $parser->parse( $source ) );
		}
	}
}
