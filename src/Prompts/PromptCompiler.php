<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Prompts;

/**
 * Assembles the instructions for one workflow: only the prompts that workflow
 * needs, plus the live site context.
 */
final class PromptCompiler {

	private PromptLoader $loader;

	public function __construct( PromptLoader $loader ) {
		$this->loader = $loader;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array{instructions:string,prompts:string[],missing:string[]}
	 */
	public function compile( string $workflow, array $context = array() ): array {
		$definition = PromptRegistry::get( $workflow );
		if ( null === $definition ) {
			return array(
				'instructions' => '',
				'prompts'      => array(),
				'missing'      => array( $workflow ),
			);
		}

		$sections = array();
		$used     = array();
		$missing  = array();

		foreach ( $definition['prompts'] as $name ) {
			$prompt = $this->loader->load( $name );
			if ( null === $prompt ) {
				$missing[] = $name;
				continue;
			}
			$used[]     = $name . '@' . $prompt['version'];
			$sections[] = "## " . $prompt['id'] . "\n\n" . $prompt['body'];
		}

		foreach ( $context as $key => $value ) {
			$sections[] = "## current " . str_replace( '_', ' ', (string) $key ) . "\n\n```json\n"
				. wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				. "\n```";
		}

		return array(
			'instructions' => implode( "\n\n---\n\n", $sections ),
			'prompts'      => $used,
			'missing'      => $missing,
		);
	}
}
