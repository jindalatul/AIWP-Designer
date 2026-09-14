<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Prompts;

/**
 * Which prompt files make up which workflow. Deterministic and versioned with
 * the plugin. No external prompt service exists.
 */
final class PromptRegistry {

	public const WORKFLOWS = array(
		'build_page'           => array(
			'title'    => 'Build a page',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/content-model',
				'core/css-rules',
				'design/page-planner',
				'design/page-designer',
				'design/worked-examples',
				'design/component-library',
				'design/composition',
				'design/imagery',
				'design/seo',
				'design/responsive-design',
				'core/forms',
				'review/design-critic',
				'workflows/build-page',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'redesign_page'        => array(
			'title'    => 'Redesign an existing page',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/content-model',
				'core/css-rules',
				'design/page-designer',
				'design/worked-examples',
				'design/component-library',
				'design/composition',
				'design/imagery',
				'design/seo',
				'design/visual-polish',
				'design/responsive-design',
				'core/forms',
				'review/design-critic',
				'workflows/redesign-page',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'redesign_section'     => array(
			'title'    => 'Redesign one section',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/css-rules',
				'design/visual-polish',
				'design/composition',
				'design/imagery',
				'design/seo',
				'workflows/redesign-section',
			),
			'context'  => array( 'capabilities', 'design_system' ),
		),
		'create_design_system' => array(
			'title'    => 'Create the site design system',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/css-rules',
				'design/brand-analysis',
				'design/design-system',
				'design/component-library',
				'workflows/create-design-system',
			),
			// The current system too: this workflow also adjusts one that exists
			// and writes the component library, so it has to see what is there.
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'improve_page'         => array(
			'title'    => 'Critique and improve a page',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/css-rules',
				'design/component-library',
				'review/design-critic',
				'review/accessibility',
				'review/performance',
				'workflows/improve-page',
			),
			'context'  => array( 'capabilities', 'design_system' ),
		),
		'build_article_template' => array(
			'title'    => 'Design the article template',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/content-model',
				'core/css-rules',
				'design/page-designer',
				'design/component-library',
				'design/imagery',
				'design/seo',
				'design/responsive-design',
				'review/design-critic',
				'workflows/build-article-template',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'build_archive'        => array(
			'title'    => 'Design the list of articles',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/css-rules',
				'design/page-designer',
				'design/component-library',
				'design/responsive-design',
				'workflows/build-archive',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'build_chrome'         => array(
			'title'    => 'Build the shared header and footer',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/content-model',
				'core/css-rules',
				'design/chrome',
				'workflows/build-chrome',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		'update_content'       => array(
			'title'    => 'Change page copy only',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/content-model',
			),
			'context'  => array( 'capabilities' ),
		),
	);

	public static function exists( string $workflow ): bool {
		return isset( self::WORKFLOWS[ $workflow ] );
	}

	/**
	 * @return string[]
	 */
	public static function types(): array {
		return array_keys( self::WORKFLOWS );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $workflow ): ?array {
		return self::WORKFLOWS[ $workflow ] ?? null;
	}
}
