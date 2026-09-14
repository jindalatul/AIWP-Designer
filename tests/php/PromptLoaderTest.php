<?php
declare( strict_types = 1 );

use AIWP\Designer\Prompts\PromptCompiler;
use AIWP\Designer\Prompts\PromptLoader;
use AIWP\Designer\Prompts\PromptRegistry;
use PHPUnit\Framework\TestCase;

final class PromptLoaderTest extends TestCase {

	public function test_every_prompt_a_workflow_names_exists_and_parses(): void {
		$loader = new PromptLoader();

		foreach ( PromptRegistry::WORKFLOWS as $workflow => $definition ) {
			foreach ( $definition['prompts'] as $name ) {
				$prompt = $loader->load( $name );
				$this->assertIsArray( $prompt, sprintf( 'Workflow "%s" names a missing prompt: %s', $workflow, $name ) );
				$this->assertNotSame( '', $prompt['id'] );
				$this->assertNotSame( '', $prompt['body'] );
				$this->assertGreaterThan( 0, $prompt['version'] );
			}
		}
	}

	/**
	 * @dataProvider escape_attempts
	 */
	public function test_prompt_names_cannot_escape_the_prompts_directory( string $name ): void {
		$this->assertNull( ( new PromptLoader() )->load( $name ) );
	}

	public static function escape_attempts(): array {
		return array(
			array( '../../wp-config' ),
			array( 'core/../../../etc/passwd' ),
			array( '/etc/passwd' ),
			array( "core/system\0.md" ),
			array( 'core/system.md' ),
			array( 'nope' ),
		);
	}

	public function test_compilation_is_deterministic(): void {
		$compiler = new PromptCompiler( new PromptLoader() );
		$a        = $compiler->compile( 'build_page' );
		$b        = $compiler->compile( 'build_page' );

		$this->assertSame( $a['instructions'], $b['instructions'] );
		$this->assertSame( array(), $a['missing'] );
	}

	public function test_only_the_workflow_prompts_are_included(): void {
		$compiled = ( new PromptCompiler( new PromptLoader() ) )->compile( 'update_content' );

		$this->assertStringContainsString( 'content-model', $compiled['instructions'] );
		$this->assertStringNotContainsString( 'design-critic', $compiled['instructions'] );
	}

	public function test_context_is_inserted(): void {
		$compiled = ( new PromptCompiler( new PromptLoader() ) )->compile( 'build_page', array( 'design_system' => array( 'version' => 7 ) ) );
		$this->assertStringContainsString( '"version": 7', $compiled['instructions'] );
	}

	public function test_unknown_workflow_compiles_to_nothing(): void {
		$compiled = ( new PromptCompiler( new PromptLoader() ) )->compile( 'not_a_workflow' );
		$this->assertSame( '', $compiled['instructions'] );
		$this->assertNotEmpty( $compiled['missing'] );
	}
}
