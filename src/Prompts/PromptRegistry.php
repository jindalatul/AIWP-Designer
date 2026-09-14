<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Prompts;

/**
 * Which prompt files make up which workflow. Deterministic and versioned with
 * the plugin. No external prompt service exists.
 */
final class PromptRegistry {

	public const WORKFLOWS = array(
		'build_site'           => array(
			'title'    => 'Build a whole site, in order',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'design/brand-analysis',
				'design/design-system',
				'design/component-library',
				'design/header-footer',
				'workflows/build-site',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
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
				'design/visual-polish',
				'review/accessibility',
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
				'review/accessibility',
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
				'design/visual-polish',
				'design/composition',
				'review/accessibility',
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
				'design/visual-polish',
				'design/composition',
				'review/design-critic',
				'workflows/build-archive',
			),
			'context'  => array( 'site_context', 'capabilities', 'design_system' ),
		),
		/*
		 * The header and footer are on every page of the site, and they used to
		 * be built with six of these documents while a page got fourteen. No
		 * composition, no critic, no responsive guidance — and the chrome's own
		 * notes say the header is the part most likely to break on a phone.
		 * That is why chrome came out weaker than the pages it wraps.
		 */
		'build_header_footer'  => array(
			'title'    => 'Build the shared header and footer',
			'prompts'  => array(
				'core/system',
				'core/safety',
				'core/template-language',
				'core/content-model',
				'core/css-rules',
				'design/header-footer',
				'design/component-library',
				'design/composition',
				'design/responsive-design',
				'design/visual-polish',
				'review/design-critic',
				'review/accessibility',
				'workflows/build-header-footer',
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

	/** What these workflows used to be called. */
	private const RENAMED = array( 'build_chrome' => 'build_header_footer' );

	public static function canonical( string $workflow ): string {
		return self::RENAMED[ $workflow ] ?? $workflow;
	}

	public static function exists( string $workflow ): bool {
		return isset( self::WORKFLOWS[ self::canonical( $workflow ) ] );
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
		return self::WORKFLOWS[ self::canonical( $workflow ) ] ?? null;
	}
}
