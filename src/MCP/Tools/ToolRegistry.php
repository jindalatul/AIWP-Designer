<?php
declare( strict_types = 1 );

namespace AIWP\Designer\MCP\Tools;

use AIWP\Designer\ACF\ACFManager;
use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Chrome\ChromeManager;
use AIWP\Designer\Chrome\MenuRenderer;
use AIWP\Designer\Design\DesignSystem;
use AIWP\Designer\Forms\FormSchema;
use AIWP\Designer\Design\DesignSystemRepository;
use AIWP\Designer\Pages\PageManager;
use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Pages\FrontPage;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Pages\PageValidator;
use AIWP\Designer\Performance\StaticAuditor;
use AIWP\Designer\Plugin;
use AIWP\Designer\Prompts\PromptCompiler;
use AIWP\Designer\Prompts\PromptRegistry;
use AIWP\Designer\Prompts\WorkflowManager;
use AIWP\Designer\Rendering\AssetManager;
use AIWP\Designer\Security\CapabilityManager;
use AIWP\Designer\Security\Sanitizer;
use AIWP\Designer\Versioning\VersionManager;

/**
 * Every MCP tool: its schema, the capability it needs, and its handler.
 *
 * Tool names use underscores rather than dots so they survive every MCP client's
 * tool-name rules. Dotted aliases from the specification are still accepted.
 */
final class ToolRegistry {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function definitions(): array {
		$tools = array();

		foreach ( $this->catalog() as $name => $tool ) {
			$tools[] = array(
				'name'        => $name,
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
			);
		}

		return $tools;
	}

	public function exists( string $name ): bool {
		return isset( $this->catalog()[ $this->normalise( $name ) ] );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function call( string $name, array $args, int $user_id ): array {
		$name    = $this->normalise( $name );
		$catalog = $this->catalog();

		if ( ! isset( $catalog[ $name ] ) ) {
			return $this->fail( 'AIWP_UNKNOWN_TOOL', sprintf( 'There is no tool called "%s".', $name ) );
		}

		$tool = $catalog[ $name ];

		if ( ! user_can( $user_id, $tool['capability'] ) ) {
			return $this->fail(
				'AIWP_PERMISSION_DENIED',
				sprintf( 'This connection lacks the "%s" capability.', $tool['capability'] )
			);
		}

		$unknown = $this->unknown_arguments( $tool['schema'], $args );
		if ( array() !== $unknown ) {
			// Silently ignoring an argument is worse than refusing it: the call
			// returns success, the change never happened, and nobody finds out
			// until someone looks at the site.
			$known = array_keys( (array) ( $tool['schema']['properties'] ?? array() ) );
			sort( $known );

			return $this->fail(
				'AIWP_UNKNOWN_ARGUMENT',
				sprintf(
					'%s does not take %s. It takes: %s.',
					$name,
					implode( ', ', $unknown ),
					implode( ', ', $known )
				)
			);
		}

		if ( '' !== $tool['workflow'] ) {
			$check = $this->plugin->workflows()->check(
				(string) ( $args['workflow_id'] ?? '' ),
				$tool['workflow'],
				$user_id
			);
			if ( ! $check['valid'] ) {
				return $this->fail( $check['code'], $check['message'] );
			}
		}

		return call_user_func( $tool['handler'], $args, $user_id );
	}

	/**
	 * Argument names this tool does not have.
	 *
	 * @param array<string,mixed> $schema
	 * @param array<string,mixed> $args
	 * @return string[]
	 */
	private function unknown_arguments( array $schema, array $args ): array {
		$known = (array) ( $schema['properties'] ?? array() );
		if ( array() === $known ) {
			return array();
		}

		$unknown = array();
		foreach ( array_keys( $args ) as $key ) {
			$key = (string) $key;
			if ( ! isset( $known[ $key ] ) ) {
				$unknown[] = $key;
			}
		}

		sort( $unknown );
		return $unknown;
	}

	/**
	 * A failed disk write is not a CSS problem, and saying it is sends whoever
	 * reads the error looking in the wrong place.
	 *
	 * @param string[] $errors
	 */
	private function why_rejected( array $errors ): string {
		foreach ( $errors as $error ) {
			if ( 0 === strpos( (string) $error, 'AIWP_FILE_WRITE_FAILED' ) ) {
				return 'AIWP_FILE_WRITE_FAILED';
			}
		}

		return 'AIWP_CSS_INVALID';
	}

	private function normalise( string $name ): string {
		return str_replace( array( '.', '-' ), '_', strtolower( trim( $name ) ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function catalog(): array {
		$obj = static fn( array $props, array $required = array() ): array => array(
			'type'       => 'object',
			'properties' => $props,
			'required'   => $required,
		);

		$str  = static fn( string $desc ): array => array( 'type' => 'string', 'description' => $desc );
		$int  = static fn( string $desc ): array => array( 'type' => 'integer', 'description' => $desc );
		$bool = static fn( string $desc ): array => array( 'type' => 'boolean', 'description' => $desc );

		$workflow_id = $str( 'The workflow_id returned by workflow_prepare.' );

		return array(

			'workflow_prepare' => array(
				'description' => 'ALWAYS CALL THIS FIRST. Returns the plugin\'s current design instructions for a workflow, plus the workflow_id every mutating tool requires. Workflow types: ' . implode( ', ', PromptRegistry::types() ) . '.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'workflow_type' => array(
							'type'        => 'string',
							'enum'        => PromptRegistry::types(),
							'description' => 'Which job you are about to do.',
						),
						'page_id'       => $int( 'Optional. The page this workflow is about, so its current state is included.' ),
					),
					array( 'workflow_type' )
				),
				'handler'     => array( $this, 'workflow_prepare' ),
			),

			'site_get_context' => array(
				'description' => 'The site: name, URL, description, theme, homepage, onboarding answers and the AIWP pages that already exist.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'site_get_context' ),
			),

			'site_get_capabilities' => array(
				'description' => 'Which ACF field types, behaviors and template features this site actually supports right now. Read this instead of guessing.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'site_get_capabilities' ),
			),

			'design_get_system' => array(
				'description' => 'The active design system: tokens, compiled CSS variables and global CSS.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'design_get_system' ),
			),

			'design_create_system' => array(
				'description' => 'Create a new site-wide design system version from tokens plus global CSS. Existing pages keep working and pick up the new variables.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'create_design_system',
				'schema'      => $obj(
					array(
						'workflow_id'   => $workflow_id,
						'design_system' => array(
							'type'        => 'object',
							'description' => 'colors, typography, spacing, radius, container.',
						),
						'global_css'    => $str( 'Site-wide CSS. Foundations only, not page design.' ),
					),
					array( 'workflow_id', 'design_system' )
				),
				'handler'     => array( $this, 'design_create_system' ),
			),

			'design_update_system' => array(
				'description' => 'Change part of the design system. Anything you do not send keeps its current value, and global CSS is only replaced when you send it. Use this rather than design_create_system when you are adjusting an existing system.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'create_design_system',
				'schema'      => $obj(
					array(
						'workflow_id'   => $workflow_id,
						'design_system' => array(
							'type'        => 'object',
							'description' => 'Only the groups and keys you want to change.',
						),
						'global_css'    => $str( 'Optional. Replaces the global CSS when present.' ),
					),
					array( 'workflow_id', 'design_system' )
				),
				'handler'     => array( $this, 'design_update_system' ),
			),

			'design_set_components' => array(
				'description' => 'Write the component library for this site: the set of pieces every page is then built from. '
					. 'You design these yourself for this business — nothing is picked from a catalogue, so two sites never look alike. '
					. 'Each entry needs an id, a name, a use_when line saying when to reach for it, and its CSS. '
					. 'Components are merged by id, so sending one entry adds or replaces just that one. '
					. 'Do this after the design system and before any page.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'create_design_system',
				'schema'      => $obj(
					array(
						'workflow_id' => $workflow_id,
						'components'  => array(
							'type'        => 'array',
							'description' => 'Each: { id, name, use_when, css, markup }. markup is an optional example of how the classes are used.',
							'items'       => array( 'type' => 'object' ),
						),
						'remove'      => array(
							'type'        => 'array',
							'description' => 'Component ids to delete. Anything a page still uses will lose its styling.',
							'items'       => array( 'type' => 'string' ),
						),
						'replace_all' => $bool( 'Throw away the existing library and keep only what you send. Off by default.' ),
					),
					array( 'workflow_id' )
				),
				'handler'     => array( $this, 'design_set_components' ),
			),

			'design_extract_components' => array(
				'description' => 'Read a page you have already designed and propose the components hiding in its CSS. '
					. 'Use this straight after the first page: it is how the language you invented for the homepage becomes '
					. 'the language of the whole site, instead of staying locked in one page\'s stylesheet. '
					. 'It only proposes — it cannot know what a thing is for. Name each one, write its use_when line, '
					. 'drop what is really page layout, then store the rest with design_set_components.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array( 'page_id' => $int( 'The page to read. Leave out to read the header and footer.' ) )
				),
				'handler'     => array( $this, 'design_extract_components' ),
			),

			'design_get_components' => array(
				'description' => 'The component library for this site: what exists, when to use each one, and the CSS. '
					. 'Read this before designing a page, and build the page out of what is here. '
					. 'If the page needs something the site does not have yet, add it with design_set_components rather than writing a one-off in page CSS.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array( 'with_css' => $bool( 'Include each component\'s CSS. Off by default, because the list alone is usually enough to choose.' ) )
				),
				'handler'     => array( $this, 'design_get_components' ),
			),

			'site_set_menu' => array(
				'description' => 'Build a real WordPress navigation menu and put it in a location, or with menu_id alone put an existing menu there without changing it. The menu appears under Appearance > Menus, where the site owner can edit it without you. Place it in a template with <nav data-aiwp-menu="primary"></nav>.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'location' => array(
							'type'        => 'string',
							'enum'        => array_keys( MenuRenderer::LOCATIONS ),
							'description' => 'Where the menu goes.',
						),
						'title'    => $str( 'Name for the menu in Appearance > Menus. Optional.' ),
						'items'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => 'In order. Each item: { label, page_id } to link to a page, or { label, url } for anything else.',
						),
						'menu_id'  => $int( 'Use the menu that already has this id, leaving its items alone. Send this instead of items when the site owner has already built the menu.' ),
					),
					array( 'location' )
				),
				'handler'     => array( $this, 'site_set_menu' ),
			),

			'site_get_menus' => array(
				'description' => 'The navigation menus and what is currently in them.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'site_get_menus' ),
			),

			'site_set_front_page' => array(
				'description' => 'Make a page the site front page, so it is served at the site root. A page named Home claims this on its own when nothing else has, so you usually do not need to call this.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => '',
				'schema'      => $obj(
					array( 'page_id' => $int( 'The WordPress page id. Send 0 to hand the site root back to the blog listing.' ) ),
					array( 'page_id' )
				),
				'handler'     => array( $this, 'site_set_front_page' ),
			),

			'site_get_chrome' => array(
				'description' => 'The shared header and footer used by every AIWP page: schema, content, markup and CSS.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'site_get_chrome' ),
			),

			'site_set_chrome' => array(
				'description' => 'Build or replace the site-wide header and footer. Every page with chrome "site" then uses it, so you write the navigation once. Content is editable in WordPress like any page content.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'build_chrome',
				'schema'      => $obj(
					array(
						'workflow_id' => $workflow_id,
						'sections'    => array(
							'type'        => 'array',
							'description' => 'Field model for the header and footer, same shape as page sections.',
							'items'       => array( 'type' => 'object' ),
						),
						'content'     => array(
							'type'        => 'object',
							'description' => 'Starting values, shaped { section_id: { field_name: value } }.',
						),
						'header'      => $str( 'Header markup in the AIWP template language. Include the navigation.' ),
						'footer'      => $str( 'Footer markup in the AIWP template language.' ),
						'css'         => $str( 'CSS for the header and footer. Scoped to the chrome automatically.' ),
						'note'        => $str( 'Optional one-line note about this revision.' ),
					),
					array( 'workflow_id', 'sections' )
				),
				'handler'     => array( $this, 'site_set_chrome' ),
			),

			'form_entries' => array(
				'description' => 'Recent form submissions across the site, newest first.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'limit' => $int( 'How many to return, up to 100.' ) ) ),
				'handler'     => array( $this, 'form_entries' ),
			),

			'pages_list' => array(
				'description' => 'Every AIWP page with its status, version and URLs.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'pages_list' ),
			),

			'page_get' => array(
				'description' => 'One page in full: metadata, schema, content, template, CSS, behaviors, design metadata and current version.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'page_get' ),
			),

			'page_create' => array(
				'description' => 'Create a new page as a draft: field schema, starting content, template markup, page CSS and behaviors. Returns a preview URL you should then open and review.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => 'build_page',
				'schema'      => $obj(
					array(
						'workflow_id'     => $workflow_id,
						'page'            => array(
							'type'        => 'object',
							'description' => 'title (required), slug, status, and front_page. A page named Home takes the site root on its own when nothing has claimed it; set front_page true or false to decide explicitly.',
						),
						'design_metadata' => array(
							'type'        => 'object',
							'description' => 'page_goal, audience, visual_direction, primary_conversion, story[].',
						),
						'sections'        => array(
							'type'        => 'array',
							'description' => 'Field model. Each section: id, label, fields[]. Each field: id, name, label, type, required; aiwp_repeater adds sub_fields[], min, max.',
							'items'       => array( 'type' => 'object' ),
						),
						'content'         => array(
							'type'        => 'object',
							'description' => 'Starting values, shaped { section_id: { field_name: value } }.',
						),
						'template'        => $str( 'The .aiwp template markup.' ),
						'css'             => $str( 'Page CSS. It is scoped to this page automatically.' ),
						'behaviors'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Behaviors used: ' . implode( ', ', AssetManager::BEHAVIORS ) . '.',
						),
						'forms'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => 'Forms this page collects. Each: id, title, submit_label, success_message, notify_email, fields[] of { id, name, label, type, required, width, choices }. Field types: ' . implode( ', ', FormSchema::FIELD_TYPES ) . '. Place each one in the template with <div data-aiwp-form="ID"></div>; the plugin renders the real form.',
						),
						'chrome'          => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'blank', 'site' ),
							'description' => 'site uses the shared AIWP header and footer (build it with site_set_chrome). theme uses the WordPress theme. blank gives you the whole document. Default theme.',
						),
					),
					array( 'workflow_id', 'page', 'sections', 'template' )
				),
				'handler'     => array( $this, 'page_create' ),
			),

			'page_update' => array(
				'description' => 'Replace a page\'s design: template, CSS, schema, behaviors or design metadata. Creates a new version. Never publishes.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => 'redesign_page',
				'schema'      => $obj(
					array(
						'workflow_id'           => $workflow_id,
						'page_id'               => $int( 'The WordPress page id.' ),
						'expected_version'      => $int( 'The version you read before editing. Protects against overwriting newer work.' ),
						'sections'              => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'content'               => array( 'type' => 'object' ),
						'template'              => $str( 'New template markup.' ),
						'css'                   => $str( 'New page CSS.' ),
						'behaviors'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'forms'                 => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'design_metadata'       => array(
							'type'        => 'object',
							'description' => 'page_type, target_words, page_goal, audience, visual_direction, primary_conversion, story. '
								. 'Set target_words from the brief when there is one, so a page that ships at half its planned length is noticed.',
						),
						'chrome'                => array( 'type' => 'string', 'enum' => array( 'theme', 'blank', 'site' ) ),
						'allow_field_migration' => $bool( 'Set true only when you really mean to rename a field that already holds content.' ),
					),
					array( 'workflow_id', 'page_id' )
				),
				'handler'     => array( $this, 'page_update' ),
			),

			'page_update_content' => array(
				'description' => 'Change field values only. Does not create a template version. Use this when only the words change.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => 'update_content',
				'schema'      => $obj(
					array(
						'workflow_id' => $workflow_id,
						'page_id'     => $int( 'The WordPress page id.' ),
						'updates'     => array(
							'type'        => 'array',
							'description' => 'Each item: { field_path: "hero.headline", value: ... }.',
							'items'       => array( 'type' => 'object' ),
						),
					),
					array( 'workflow_id', 'page_id', 'updates' )
				),
				'handler'     => array( $this, 'page_update_content' ),
			),

			'site_check_variety' => array(
				'description' => 'Whether this site made its own decisions or kept the ones it was given. '
					. 'Reports how many design values are still exactly what the plugin ships, which of the seven style '
					. 'decisions were never written down, pages built to the same shape as each other, and components the '
					. 'library holds that no page uses. It has no opinion about what the site should look like — it only '
					. 'says what was decided and what was left. Run it once the site has a few pages.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'site_check_variety' ),
			),

			'page_look' => array(
				'description' => 'Read the finished page back the way a visitor meets it: what it actually says, in order, '
					. 'and the things that only show up once it has been rendered — a field the template prints that was left '
					. 'empty, a sentence said twice, standing placeholder text, links that go nowhere. '
					. 'Every other check reads the parts; this one reads the page. Call it before publishing.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id' => $int( 'The page to read back.' ),
					),
					array( 'page_id' )
				),
				'handler'     => array( $this, 'page_look' ),
			),

			'page_validate' => array(
				'description' => 'Dry-run a template, CSS and schema against the validators without storing anything. '
					. 'Pass page_id when checking a change to an existing page: anything you leave out is taken from that page, '
					. 'so its forms and behaviors are not read as missing.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id'   => $int( 'The page this change is for. Anything you leave out is taken from it.' ),
						'sections'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'template'  => $str( 'Template markup to check.' ),
						'css'       => $str( 'CSS to check.' ),
						'behaviors' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'forms'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					)
				),
				'handler'     => array( $this, 'page_validate' ),
			),

			'page_get_preview_url' => array(
				'description' => 'A URL you can open in your browser to see the rendered page, including drafts (signed, short-lived).',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'page_get_preview_url' ),
			),

			'page_publish' => array(
				'description' => 'Make the page public. Requires confirm_publish true, and re-validates first.',
				'capability'  => CapabilityManager::PUBLISH_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id'         => $int( 'The WordPress page id.' ),
						'version'         => $int( 'The version you intend to publish. 0 or omitted means the current one.' ),
						'confirm_publish' => $bool( 'Must be true.' ),
					),
					array( 'page_id', 'confirm_publish' )
				),
				'handler'     => array( $this, 'page_publish' ),
			),

			'page_delete' => array(
				'description' => 'Delete an AIWP page. Moves it to the trash by default, where it can be restored. '
					. 'Refuses the site front page and the shared header and footer holder. '
					. 'Only do this when the person asked for it — never to tidy up after yourself.',
				'capability'  => CapabilityManager::PUBLISH_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id'        => $int( 'The WordPress page id.' ),
						'confirm_delete' => $bool( 'Must be true. Deleting is the one thing that cannot be undone by a rollback.' ),
						'permanent'      => $bool( 'Skip the trash and remove it and its version history for good. Off by default.' ),
					),
					array( 'page_id', 'confirm_delete' )
				),
				'handler'     => array( $this, 'page_delete' ),
			),

			'page_rollback' => array(
				'description' => 'Restore an earlier version. The rollback itself becomes a new version; history is never destroyed.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id' => $int( 'The WordPress page id.' ),
						'version' => $int( 'The version to restore.' ),
					),
					array( 'page_id', 'version' )
				),
				'handler'     => array( $this, 'page_rollback' ),
			),

			'page_versions' => array(
				'description' => 'Version history for a page.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'page_versions' ),
			),

			'media_search' => array(
				'description' => 'Search the WordPress media library. Use the returned id in image fields; never invent an id or a file path.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'query'    => $str( 'Search words. Leave empty for the most recent images.' ),
						'per_page' => $int( 'How many results, up to 50.' ),
					)
				),
				'handler'     => array( $this, 'media_search' ),
			),

			'article_get_template' => array(
				'description' => 'The design every article renders through: its extra fields, markup, CSS, and the field paths '
					. 'WordPress itself supplies under post. Read this before writing or changing an article design.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'kind' => $str( 'Which article design. Leave out for the default one.' ) ) ),
				'handler'     => array( $this, 'article_get_template' ),
			),

			'article_set_template' => array(
				'description' => 'Write the design every article renders through. One design, many posts — unlike a page, '
					. 'this is not written per article. The body stays in the WordPress editor and is printed with '
					. '{{post_body:post.body}}; the fields you declare here are the furniture around it. '
					. 'Build it out of the site component library, the same as a page.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'build_article_template',
				'schema'      => $obj(
					array(
						'workflow_id' => $workflow_id,
						'kind'        => $str( 'Which article design. Leave out for the default one.' ),
						'sections'    => array(
							'type'        => 'array',
							'description' => 'Extra fields a writer fills in per article: deck, takeaways, FAQ, and so on. Same shape as page sections. Do not declare a field for the body — it is already there.',
							'items'       => array( 'type' => 'object' ),
						),
						'template'    => $str( 'Article markup. Must print {{post_body:post.body}} somewhere.' ),
						'css'         => $str( 'CSS for articles. Scoped to the article wrapper automatically.' ),
					),
					array( 'workflow_id', 'template' )
				),
				'handler'     => array( $this, 'article_set_template' ),
			),

			'page_set_brief' => array(
				'description' => 'Record what a page is meant to do: its keyword, the questions it must answer, the concepts '
					. 'it must cover, its meta title and description, and which schema types apply. '
					. 'The plugin does not care where this came from — a brief from another tool, or your own judgement. '
					. 'seo_audit then checks the page against it. Everything is optional.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id'            => $int( 'The WordPress page or post id.' ),
						'primary_keyword'    => $str( 'The one phrase this page is for.' ),
						'secondary_keywords' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'search_intent'      => $str( 'informational, commercial, transactional, navigational.' ),
						'funnel_stage'       => $str( 'TOFU, MOFU or BOFU.' ),
						'meta_title'         => $str( 'Under about 60 characters.' ),
						'meta_description'   => $str( 'Around 150 characters.' ),
						'questions'          => array(
							'type'        => 'array',
							'description' => 'Questions this page has to answer. Checked against what the page says.',
							'items'       => array( 'type' => 'string' ),
						),
						'entities'           => array(
							'type'        => 'array',
							'description' => 'Concepts a credible page on this subject must cover.',
							'items'       => array( 'type' => 'string' ),
						),
						'schema_types'       => array(
							'type'        => 'array',
							'description' => 'Article, FAQPage, BreadcrumbList. The plugin builds these from the page itself and ignores a type the page cannot support.',
							'items'       => array( 'type' => 'string' ),
						),
						'internal_links'     => array(
							'type'        => 'array',
							'description' => 'Each: { anchor, url }. Pages this one should link to.',
							'items'       => array( 'type' => 'object' ),
						),
						'source'             => $str( 'Where the brief came from, for your own reference.' ),
						'source_id'          => $str( 'That source\'s id for this page.' ),
					),
					array( 'page_id' )
				),
				'handler'     => array( $this, 'page_set_brief' ),
			),

			'page_get_brief' => array(
				'description' => 'The brief recorded for a page, if there is one.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page or post id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'page_get_brief' ),
			),

			'seo_audit' => array(
				'description' => 'Check a page for the things a search engine and a reader both need: title and description '
					. 'length, one h1, heading order, alt text, internal links, whether anything links to it, the address. '
					. 'Where a brief exists it also checks the keyword, the questions it promised to answer and the concepts '
					. 'it promised to cover. Says what it did not check rather than implying it checked everything.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page or post id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'seo_audit' ),
			),

			'archive_get_template' => array(
				'description' => 'The design used for every list of articles: the blog index, a category, a tag, an author, '
					. 'a search result. One design covers all of them. Also returns the archive. field paths WordPress supplies.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array() ),
				'handler'     => array( $this, 'archive_get_template' ),
			),

			'archive_set_template' => array(
				'description' => 'Write the design for every list of articles. Loop over archive.items for the articles. '
					. 'archive.title, archive.kind and archive.term tell you which list is being shown, so one design '
					. 'serves the blog index, categories, tags, authors and search. Build it from the component library.',
				'capability'  => CapabilityManager::MANAGE_DESIGN,
				'workflow'    => 'build_archive',
				'schema'      => $obj(
					array(
						'workflow_id' => $workflow_id,
						'sections'    => array(
							'type'        => 'array',
							'description' => 'Extra fields for the listing itself, if it needs any. Usually none: everything comes from archive.',
							'items'       => array( 'type' => 'object' ),
						),
						'template'    => $str( 'Markup. Must loop over archive.items.' ),
						'css'         => $str( 'CSS for the listing. Scoped automatically.' ),
					),
					array( 'workflow_id', 'template' )
				),
				'handler'     => array( $this, 'archive_set_template' ),
			),

			'articles_list' => array(
				'description' => 'Posts on this site, and whether an article design exists to render them.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'per_page' => $int( 'How many, up to 50.' ) ) ),
				'handler'     => array( $this, 'articles_list' ),
			),

			'media_import' => array(
				'description' => 'Put an image into the media library. Give exactly one of url, data or svg. '
					. 'url downloads a public image. data is base64 bytes of a file you have read. '
					. 'svg is SVG markup you wrote yourself, which is stripped down to drawing tags before it is saved. '
					. 'Always write real alt text. Use the returned id in image fields.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'url'      => $str( 'A public http(s) URL to download the image from.' ),
						'data'     => $str( 'Base64 of the image file, or a data: URI.' ),
						'svg'      => $str( 'SVG markup. Needs a viewBox. No script, no external references, no embedded raster.' ),
						'alt'      => $str( 'What the image shows, for a reader who cannot see it. Required.' ),
						'title'    => $str( 'A short name for the media library. Optional.' ),
						'filename' => $str( 'Preferred file name. The site still decides the final name and extension.' ),
					),
					array( 'alt' )
				),
				'handler'     => array( $this, 'media_import' ),
			),

			'design_review' => array(
				'description' => 'Review a page, or the header and footer, against the design system: off-palette colours, '
					. 'contrast, type scale, spacing scale, corner radius, font count, hover and focus states, motion, '
					. 'responsiveness, line length and heading order. Deterministic — it judges what was written, not how it looks. '
					. 'Run it before publishing, fix what it finds, then open the preview URL and look at the page yourself.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj(
					array(
						'page_id' => $int( 'The WordPress page id. Leave out to review the shared header and footer.' ),
					)
				),
				'handler'     => array( $this, 'design_review' ),
			),

			'performance_static_audit' => array(
				'description' => 'Deterministic checks on the rendered page: length against the target set in design_metadata, '
					. 'size, DOM weight, alt text, image dimensions, duplicate ids, heading structure.',
				'capability'  => CapabilityManager::EDIT_PAGES,
				'workflow'    => '',
				'schema'      => $obj( array( 'page_id' => $int( 'The WordPress page id.' ) ), array( 'page_id' ) ),
				'handler'     => array( $this, 'performance_static_audit' ),
			),
		);
	}

	// ------------------------------------------------------------- handlers

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function workflow_prepare( array $args, int $user_id ): array {
		$type = Sanitizer::key( $args['workflow_type'] ?? '' );

		if ( ! PromptRegistry::exists( $type ) ) {
			return $this->fail(
				'AIWP_WORKFLOW_UNKNOWN',
				sprintf( 'Unknown workflow_type "%s". Available: %s.', $type, implode( ', ', PromptRegistry::types() ) )
			);
		}

		$definition = PromptRegistry::get( $type );
		$context    = array();

		foreach ( (array) $definition['context'] as $needed ) {
			switch ( $needed ) {
				case 'site_context':
					$context['site_context'] = $this->build_site_context();
					break;
				case 'capabilities':
					$context['capabilities'] = $this->build_capabilities();
					break;
				case 'design_system':
					$context['design_system'] = $this->build_design_system();
					break;
			}
		}

		$page_id = absint( $args['page_id'] ?? 0 );
		if ( $page_id > 0 && $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			$context['page'] = $this->page_payload( $page_id );
		}

		$compiled = ( new PromptCompiler( $this->plugin->prompt_loader() ) )->compile( $type, $context );
		$workflow = $this->plugin->workflows()->start( $type, $user_id );

		return array(
			'workflow_id'      => $workflow['workflow_id'],
			'workflow_type'    => $type,
			'expires_at'       => $workflow['expires_at'],
			'instructions'     => $compiled['instructions'],
			'prompts_included' => $compiled['prompts'],
			'required_context' => $definition['context'],
			'next_step'        => 'Read the instructions, then call the tools they name. Pass this workflow_id with every mutating tool.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function site_get_context(): array {
		return $this->build_site_context();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function site_get_capabilities(): array {
		return $this->build_capabilities();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function design_get_system(): array {
		return $this->build_design_system();
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_create_system( array $args ): array {
		$system = DesignSystem::from_array( (array) ( $args['design_system'] ?? array() ) );
		$result = $this->plugin->design()->store( $system, (string) ( $args['global_css'] ?? '' ) );

		if ( ! $result['success'] ) {
			return $this->fail( $this->why_rejected( $result['errors'] ), 'The design system was rejected.', $result['errors'] );
		}

		return array(
			'success'       => true,
			'version'       => $result['version'],
			'tokens'        => $system->with_version( $result['version'] )->tokens(),
			'affects_pages' => $this->design_reach(),
			'warnings'      => array_merge( $result['warnings'], $this->design_reach_warning() ),
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_update_system( array $args ): array {
		$current = $this->plugin->design()->current();
		$merged  = $current->merged_with( (array) ( $args['design_system'] ?? array() ) );

		// Global CSS is kept unless this call replaces it. The stored file already
		// contains the compiled tokens and baseline, so only the authored part is reused.
		$global_css = array_key_exists( 'global_css', $args )
			? (string) $args['global_css']
			: $this->plugin->design()->authored_global_css();

		$result = $this->plugin->design()->store( $merged, $global_css );

		if ( ! $result['success'] ) {
			return $this->fail( 'AIWP_CSS_INVALID', 'The design system update was rejected.', $result['errors'] );
		}

		return array(
			'success'       => true,
			'version'       => $result['version'],
			'tokens'        => $merged->with_version( $result['version'] )->tokens(),
			'affects_pages' => $this->design_reach(),
			'warnings'      => array_merge( $result['warnings'], $this->design_reach_warning() ),
		);
	}

	private function design_reach(): int {
		return count( $this->plugin->pages()->all_page_ids() );
	}

	/**
	 * The design system is site-wide. Say so plainly rather than letting a second
	 * brand quietly restyle the pages already built.
	 *
	 * @return string[]
	 */
	private function design_reach_warning(): array {
		$count = $this->design_reach();
		if ( $count < 1 ) {
			return array();
		}

		return array(
			sprintf(
				'This site has one design system, and %d existing AIWP page(s) now use these tokens. If one page needs its own look, put it in that page CSS instead of moving the site tokens.',
				$count
			),
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_set_components( array $args ): array {
		$repository = $this->plugin->design();
		$incoming   = (array) ( $args['components'] ?? array() );
		$remove     = (array) ( $args['remove'] ?? array() );

		$library = ! empty( $args['replace_all'] )
			? \AIWP\Designer\Design\ComponentLibrary::from_array( $incoming )
			: $repository->components()->merged_with( $incoming );

		if ( array() !== $remove ) {
			$library = $library->without( array_map( 'strval', $remove ) );
		}

		$result = $repository->store( $repository->current(), $repository->authored_global_css(), $library );

		if ( ! $result['success'] ) {
			return $this->fail( $this->why_rejected( $result['errors'] ), 'The component library was rejected.', $result['errors'] );
		}

		return array(
			'success'    => true,
			'version'    => $result['version'],
			'components' => $library->index(),
			'count'      => $library->count(),
			'warnings'   => $result['warnings'],
			'note'       => 'Every page picks these up. Build pages out of them, and add to this list rather than writing a one-off component in page CSS.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_extract_components( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );
		$library = $this->plugin->design()->components();

		if ( 0 === $page_id ) {
			$css   = $this->plugin->chrome()->authored_css();
			$label = 'the header and footer';
		} else {
			if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
				return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
			}
			$css   = $this->plugin->pages()->authored_css( $page_id );
			$label = (string) get_the_title( $page_id );
		}

		$proposed = ( new \AIWP\Designer\Design\ComponentExtractor( $css, $library ) )->propose();

		$fresh = array_values( array_filter( $proposed, static fn( array $c ): bool => ! $c['already_in_library'] ) );

		return array(
			'source'    => $label,
			'found'     => count( $proposed ),
			'new'       => count( $fresh ),
			'proposed'  => $proposed,
			'next'      => 'These are guesses from the shape of the CSS. Keep the ones that are really components, give each '
				. 'an id and a use_when line, and store them with design_set_components. Leave anything that is only this '
				. 'page\'s layout where it is.',
			'note'      => 'A rule counts as a candidate when it is built on one class and sets at least two of background, '
				. 'border, radius, shadow, padding or colour. Rules with a descendant part are treated as placement, not components.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_get_components( array $args ): array {
		$library = $this->plugin->design()->components();

		return array(
			'count'      => $library->count(),
			'components' => empty( $args['with_css'] ) ? $library->index() : $library->to_array(),
			'classes'    => $library->class_names(),
			'note'       => $library->is_empty()
				? 'This site has no component library yet. Design one with design_set_components before building pages, or every page will invent its own.'
				: 'Compose pages from these. Page CSS is for that page\'s layout, not for redefining a component that already exists.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function site_set_menu( array $args ): array {
		$location = (string) ( $args['location'] ?? '' );
		$menu_id  = absint( $args['menu_id'] ?? 0 );

		// An id on its own means "use the menu that already exists", so a menu
		// the site owner built by hand is not rewritten from a template.
		$result = $menu_id > 0 && ! isset( $args['items'] )
			? $this->plugin->menus()->assign_menu( $location, $menu_id )
			: $this->plugin->menus()->set_menu(
				$location,
				(string) ( $args['title'] ?? '' ),
				(array) ( $args['items'] ?? array() )
			);

		if ( ! $result['success'] ) {
			return $this->fail( 'AIWP_MENU_INVALID', $result['error'] );
		}

		return array(
			'success'   => true,
			'location'  => $location,
			'menu_id'   => $result['menu_id'],
			'items'     => $result['count'],
			'edit_url'  => admin_url( 'nav-menus.php?menu=' . $result['menu_id'] ),
			'place_with' => sprintf( '<nav data-aiwp-menu="%s"></nav>', $location ),
			'note'      => 'This is a normal WordPress menu. The site owner can change it under Appearance > Menus without touching the template.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function site_get_menus(): array {
		return array(
			'locations' => $this->plugin->menus()->describe(),
			'note'      => 'Place a menu with <nav data-aiwp-menu="primary"></nav>. If nothing is assigned, the published pages are listed instead so the header is never empty.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function site_set_front_page( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );

		if ( 0 === $page_id ) {
			FrontPage::clear();

			return array(
				'success'    => true,
				'page_id'    => 0,
				'front_page' => false,
				'note'       => 'The site root shows the blog listing again.',
			);
		}

		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		if ( ! FrontPage::claim( $page_id ) ) {
			return array(
				'success'    => true,
				'page_id'    => $page_id,
				'front_page' => false,
				'note'       => 'Recorded. The page is still a draft, so it becomes the front page the moment you publish it.',
			);
		}

		return array(
			'success'    => true,
			'page_id'    => $page_id,
			'front_page' => true,
			'url'        => home_url( '/' ),
			'note'       => 'The site root now serves this page.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function site_get_chrome(): array {
		$chrome = $this->plugin->chrome();
		$schema = $chrome->schema();

		return array(
			'exists'   => $chrome->exists(),
			'version'  => $chrome->version(),
			'scope'    => ChromeManager::SCOPE,
			'sections' => $schema ? $schema->to_array() : array(),
			'content'  => $chrome->content(),
			'header'   => $chrome->header_template(),
			'footer'   => $chrome->footer_template(),
			'css'      => $chrome->css(),
			// The CSS as it was written, before scoping. Send this back to
			// site_set_chrome to restore the chrome exactly as it is now.
			'authored_css' => $chrome->authored_css(),
			'used_by'  => $this->pages_using_site_chrome(),
			'note'     => 'Pages opt in with chrome: "site". Build the navigation once here rather than in every page template.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function site_set_chrome( array $args ): array {
		$result = $this->plugin->chrome()->store( $args );

		if ( empty( $result['success'] ) ) {
			return array(
				'success'    => false,
				'error'      => $result['error'],
				'validation' => array(
					'errors'   => $result['errors'] ?? array(),
					'warnings' => $result['warnings'] ?? array(),
				),
			);
		}

		return array(
			'success'    => true,
			'version'    => $result['version'],
			'scope'      => $result['scope'],
			'edit_url'   => $result['edit_url'],
			'used_by'    => $this->pages_using_site_chrome(),
			'next_step'  => 'Set chrome: "site" on the pages that should use it, then open one and look at the result.',
			'validation' => array(
				'errors'   => array(),
				'warnings' => $result['warnings'],
			),
		);
	}

	/**
	 * @return int[]
	 */
	private function pages_using_site_chrome(): array {
		$ids = array();
		foreach ( $this->plugin->pages()->content_page_ids() as $page_id ) {
			if ( 'site' === $this->plugin->pages()->manifest( $page_id )->get( 'chrome', 'theme' ) ) {
				$ids[] = $page_id;
			}
		}
		return $ids;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function form_entries( array $args ): array {
		$limit   = min( 100, max( 1, absint( $args['limit'] ?? 20 ) ) );
		$entries = array();

		foreach ( $this->plugin->entries()->recent( $limit ) as $row ) {
			$entries[] = array(
				'id'         => (int) $row['id'],
				'page_id'    => (int) $row['page_id'],
				'page'       => get_the_title( (int) $row['page_id'] ),
				'form_id'    => (string) $row['form_id'],
				'created_at' => (string) $row['created_at'],
				'values'     => json_decode( (string) $row['payload'], true ),
			);
		}

		return array(
			'total'   => $this->plugin->entries()->count(),
			'entries' => $entries,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function pages_list(): array {
		$pages = array();

		foreach ( $this->plugin->pages()->content_page_ids() as $page_id ) {
			$pages[] = array(
				'page_id'        => $page_id,
				'title'          => get_the_title( $page_id ),
				'slug'           => get_post_field( 'post_name', $page_id ),
				'status'         => get_post_status( $page_id ),
				'version'        => $this->plugin->pages()->active_version( $page_id ),
				'design_version' => $this->plugin->pages()->design_version( $page_id ),
				'url'            => get_permalink( $page_id ),
				'preview_url'    => $this->plugin->page_manager()->preview_url( $page_id ),
				'front_page'     => FrontPage::is_front( $page_id ),
				'updated'        => get_post_field( 'post_modified_gmt', $page_id ),
			);
		}

		return array( 'pages' => $pages );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_get( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );
		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}
		return $this->page_payload( $page_id );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_create( array $args ): array {
		return $this->plugin->page_manager()->create( $args, 'build_page' );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_update( array $args ): array {
		return $this->plugin->page_manager()->update( $args, 'redesign_page' );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_update_content( array $args ): array {
		return $this->plugin->page_manager()->update_content(
			absint( $args['page_id'] ?? 0 ),
			(array) ( $args['updates'] ?? array() )
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_delete( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );

		if ( empty( $args['confirm_delete'] ) ) {
			return $this->fail( 'AIWP_CONFIRM_REQUIRED', 'Set confirm_delete to true. Deleting is not something a rollback can undo.' );
		}

		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		if ( $this->plugin->chrome()->is_holder( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_PROTECTED', 'That page holds the shared header and footer. Deleting it would take them off every page.' );
		}

		if ( FrontPage::current() === $page_id ) {
			return $this->fail(
				'AIWP_PAGE_PROTECTED',
				'That page is the site front page. Point the front page somewhere else with site_set_front_page first.'
			);
		}

		$title     = (string) get_the_title( $page_id );
		$permanent = ! empty( $args['permanent'] );

		$done = $permanent ? wp_delete_post( $page_id, true ) : wp_trash_post( $page_id );

		if ( ! $done ) {
			return $this->fail( 'AIWP_PAGE_DELETE_FAILED', 'WordPress refused to delete that page.' );
		}

		return array(
			'success'   => true,
			'page_id'   => $page_id,
			'title'     => $title,
			'permanent' => $permanent,
			'note'      => $permanent
				? 'Gone, along with its version history and its files.'
				: 'In the trash. Restore it from Pages > Trash, or delete it for good with permanent: true.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function site_check_variety(): array {
		return ( new \AIWP\Designer\Design\SiteVariety(
			$this->plugin->design(),
			$this->plugin->pages(),
			$this->plugin->chrome(),
			$this->plugin->articles()
		) )->check();
	}

	public function page_look( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );

		$looked = ( new \AIWP\Designer\Pages\PageInspector(
			$this->plugin->pages(),
			$this->plugin->field_values(),
			$this->plugin->renderer()
		) )->look( $page_id );

		// Remember that this version was read back, so publishing can insist on
		// it. Looking at version 3 says nothing about version 4.
		if ( ! empty( $looked['ok'] ) ) {
			update_post_meta( $page_id, PageRepository::META_LOOKED_AT, (int) $looked['version'] );
		}

		return $looked;
	}

	public function page_validate( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );

		// When checking a change to a page that already exists, anything not
		// sent is taken from the page as it stands. Without this, leaving the
		// forms out reads as "this page has no forms" and the check fails on a
		// form the caller never touched.
		$sections  = $args['sections'] ?? null;
		$forms     = $args['forms'] ?? null;
		$behaviors = $args['behaviors'] ?? null;

		if ( $page_id > 0 && $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			$manifest = $this->plugin->pages()->manifest( $page_id );
			$stored   = $this->plugin->pages()->schema( $page_id );

			if ( null === $sections ) {
				$sections = $stored ? $stored->to_array() : array();
			}
			if ( null === $forms ) {
				$forms = (array) $manifest->get( 'forms', array() );
			}
			if ( null === $behaviors ) {
				$behaviors = (array) $manifest->get( 'behaviors', array() );
			}
		}

		$schema = PageSchema::from_array( (array) ( $sections ?? array() ) );

		$result = ( new PageValidator() )->validate(
			array(
				'schema'    => $schema,
				'template'  => (string) ( $args['template'] ?? '' ),
				'css'       => (string) ( $args['css'] ?? '' ),
				'behaviors' => (array) ( $behaviors ?? array() ),
				'forms'     => (array) ( $forms ?? array() ),
			),
			'00000000-0000-4000-8000-000000000000'
		);

		return array(
			'valid'    => $result['valid'],
			'errors'   => $result['errors'],
			'warnings' => $result['warnings'],
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_get_preview_url( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );
		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		return array(
			'page_id'     => $page_id,
			'preview_url' => $this->plugin->page_manager()->preview_url( $page_id ),
			'status'      => get_post_status( $page_id ),
			'expires_in'  => \AIWP\Designer\Pages\PreviewToken::TTL,
			'note'        => 'Open this in your browser and look at the page before deciding it is finished.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_publish( array $args ): array {
		return $this->plugin->page_manager()->publish(
			absint( $args['page_id'] ?? 0 ),
			absint( $args['version'] ?? 0 ),
			true === ( $args['confirm_publish'] ?? false )
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_rollback( array $args ): array {
		return $this->plugin->page_manager()->rollback(
			absint( $args['page_id'] ?? 0 ),
			absint( $args['version'] ?? 0 )
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_versions( array $args ): array {
		$page_id = absint( $args['page_id'] ?? 0 );
		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		return array(
			'page_id'  => $page_id,
			'current'  => $this->plugin->pages()->active_version( $page_id ),
			'versions' => $this->plugin->versions()->history( $page_id ),
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function media_search( array $args ): array {
		$query    = Sanitizer::text( $args['query'] ?? '' );
		$per_page = min( 50, max( 1, absint( $args['per_page'] ?? 20 ) ) );

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'numberposts'    => $per_page,
				's'              => $query,
			)
		);

		$results = array();
		foreach ( $attachments as $attachment ) {
			$meta      = wp_get_attachment_metadata( $attachment->ID );
			$results[] = array(
				'id'     => $attachment->ID,
				'title'  => $attachment->post_title,
				'alt'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'url'    => wp_get_attachment_url( $attachment->ID ),
				'width'  => is_array( $meta ) ? ( $meta['width'] ?? null ) : null,
				'height' => is_array( $meta ) ? ( $meta['height'] ?? null ) : null,
			);
		}

		return array(
			'count'  => count( $results ),
			'images' => $results,
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function article_get_template( array $args ): array {
		$design = $this->plugin->articles()->template_for( (string) ( $args['kind'] ?? '' ) );
		$schema = $design->schema();

		return array(
			'kind'          => $design->kind(),
			'exists'        => $design->exists(),
			'version'       => $design->version(),
			'scope'         => \AIWP\Designer\Articles\ArticleTemplate::SCOPE,
			'kinds'         => \AIWP\Designer\Articles\ArticleTemplate::kinds(),
			'sections'      => $schema ? $schema->to_array() : array(),
			'template'      => $design->template(),
			'css'           => $design->authored_css(),
			'from_wordpress' => \AIWP\Designer\Articles\ArticleContent::schema_sections(),
			'note'          => 'Fields under post come from WordPress and are not declared by you. The body is printed with '
				. '{{post_body:post.body}}, which is the one filter that does not escape and works on that path alone.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function article_set_template( array $args ): array {
		$design = $this->plugin->articles()->template_for( (string) ( $args['kind'] ?? '' ) );
		$result = $design->store( $args );

		if ( ! $result['success'] ) {
			return $this->fail( 'AIWP_ARTICLE_TEMPLATE_INVALID', 'The article design was rejected.', $result['errors'] );
		}

		$posts = get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'publish' ) );

		return array(
			'success'       => true,
			'kind'          => $result['kind'],
			'version'       => $result['version'],
			'warnings'      => $result['warnings'],
			'applies_to'    => (int) wp_count_posts( 'post' )->publish,
			'preview_url'   => array() !== $posts ? (string) get_permalink( (int) $posts[0] ) : '',
			'note'          => 'Every post now renders through this. Open the preview URL and look at a real article — '
				. 'a design that reads well with two paragraphs often falls apart with twenty.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_set_brief( array $args ): array {
		$post_id = absint( $args['page_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'There is no page or post with that id.' );
		}

		$brief = \AIWP\Designer\SEO\PageBrief::from_array( $args );

		if ( ! $brief->save( $post_id ) ) {
			return $this->fail( 'AIWP_BRIEF_WRITE_FAILED', 'The brief could not be stored.' );
		}

		$owner = \AIWP\Designer\SEO\MetaTags::owner();

		return array(
			'success'    => true,
			'page_id'    => $post_id,
			'brief'      => $brief->to_array(),
			'meta_owner' => $owner,
			'note'       => '' === $owner
				? 'This plugin prints the title and description itself, because no SEO plugin is active.'
				: sprintf( '%s is active, so it prints the title and description. These values are written into its fields and never overwrite anything typed there by hand.', $owner ),
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function page_get_brief( array $args ): array {
		$post_id = absint( $args['page_id'] ?? 0 );

		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'There is no page or post with that id.' );
		}

		$brief = \AIWP\Designer\SEO\PageBrief::for_post( $post_id );

		return array(
			'page_id'  => $post_id,
			'exists'   => ! $brief->is_empty(),
			'brief'    => $brief->to_array(),
			'schema_types_supported' => \AIWP\Designer\SEO\SchemaGraph::SUPPORTED,
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function seo_audit( array $args ): array {
		$auditor = new \AIWP\Designer\SEO\SEOAuditor( $this->plugin->pages(), $this->plugin->renderer() );

		return $auditor->audit( absint( $args['page_id'] ?? 0 ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function archive_get_template(): array {
		$design = $this->plugin->articles()->archive();
		$schema = $design->schema();

		return array(
			'exists'         => $design->exists(),
			'version'        => $design->version(),
			'scope'          => \AIWP\Designer\Articles\ArchiveTemplate::SCOPE,
			'sections'       => $schema ? $schema->to_array() : array(),
			'template'       => $design->template(),
			'css'            => $design->authored_css(),
			'from_wordpress' => \AIWP\Designer\Articles\ArchiveContent::schema_sections(),
			'covers'         => array( 'blog index', 'category', 'tag', 'author', 'search', 'date' ),
			'note'           => 'One design for all of them. archive.kind says which is being shown, so a category page '
				. 'can read differently from a search result without being a second template.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function archive_set_template( array $args ): array {
		$result = $this->plugin->articles()->archive()->store( $args );

		if ( ! $result['success'] ) {
			return $this->fail( $this->why_rejected( $result['errors'] ), 'The archive design was rejected.', $result['errors'] );
		}

		$posts_page = (int) get_option( 'page_for_posts' );

		return array(
			'success'     => true,
			'version'     => $result['version'],
			'warnings'    => $result['warnings'],
			'preview_url' => $posts_page > 0 ? (string) get_permalink( $posts_page ) : (string) get_post_type_archive_link( 'post' ),
			'note'        => 'Every list of articles now renders through this. If the blog has no page of its own yet, '
				. 'create one and set it under Settings > Reading, or the site root will show the list instead.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function articles_list( array $args ): array {
		$per_page = min( 50, max( 1, absint( $args['per_page'] ?? 20 ) ) );
		$design   = $this->plugin->articles()->template_for( '' );

		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => $per_page,
				'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'post_id'   => $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'url'       => (string) get_permalink( $post ),
				'words'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'edit_url'  => (string) get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return array(
			'count'           => count( $out ),
			'articles'        => $out,
			'design_exists'   => $design->exists(),
			'design_version'  => $design->version(),
			'note'            => $design->exists()
				? 'Every one of these renders through the article design.'
				: 'There is no article design yet, so these render through the theme. Build one with article_set_template.',
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function media_import( array $args ): array {
		$url  = trim( (string) ( $args['url'] ?? '' ) );
		$data = trim( (string) ( $args['data'] ?? '' ) );
		$svg  = trim( (string) ( $args['svg'] ?? '' ) );
		$alt  = Sanitizer::text( $args['alt'] ?? '' );

		$given = array_filter( array( 'url' => $url, 'data' => $data, 'svg' => $svg ) );

		if ( 1 !== count( $given ) ) {
			return $this->fail(
				'AIWP_BAD_IMAGE_SOURCE',
				'Give exactly one of url, data or svg. ' . ( array() === $given ? 'None were given.' : 'These were given: ' . implode( ', ', array_keys( $given ) ) . '.' )
			);
		}

		if ( '' === $alt ) {
			return $this->fail( 'AIWP_MISSING_ALT', 'alt is required. Describe what the image shows.' );
		}

		$importer = new \AIWP\Designer\Media\ImageImporter();
		$title    = Sanitizer::text( $args['title'] ?? '' );
		$filename = Sanitizer::text( $args['filename'] ?? '' );

		if ( '' !== $url ) {
			$result = $importer->from_url( $url, $alt, $title, $filename );
		} elseif ( '' !== $data ) {
			$result = $importer->from_base64( $data, $alt, $title, $filename );
		} else {
			$result = $importer->from_svg( $svg, $alt, $title, $filename );
		}

		if ( ! $result['ok'] ) {
			return $this->fail( $result['code'], $result['error'] );
		}

		$payload = array( 'image' => $result['image'] );

		if ( ! empty( $result['removed'] ) ) {
			$payload['removed'] = $result['removed'];
			$payload['note']    = 'These parts of the SVG were not on the allowlist and were dropped.';
		}

		return $payload;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function design_review( array $args ): array {
		$system  = $this->plugin->design()->current();
		$page_id = absint( $args['page_id'] ?? 0 );

		// The site stylesheet and the plugin baseline also apply. They are read
		// for context only: a page is not marked down for a rule it did not write.
		$baseline  = (string) @file_get_contents( AIWP_PLUGIN_DIR . 'assets/dist/base.css' ); // phpcs:ignore
		$inherited = $this->plugin->design()->authored_global_css()
			. "\n" . $this->plugin->design()->components()->css()
			. "\n" . $baseline;

		if ( 0 === $page_id ) {
			$chrome = $this->plugin->chrome();
			$review = ( new \AIWP\Designer\Design\DesignReviewer(
				$chrome->authored_css(),
				$chrome->header_template() . "\n" . $chrome->footer_template(),
				$system,
				$inherited,
				$this->plugin->design()->components()
			) )->review();

			return array_merge( array( 'target' => 'chrome' ), $review );
		}

		if ( ! $this->plugin->pages()->is_aiwp_page( $page_id ) ) {
			return $this->fail( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		$review = ( new \AIWP\Designer\Design\DesignReviewer(
			$this->plugin->pages()->authored_css( $page_id ),
			$this->plugin->renderer()->render( $page_id ),
			$system,
			$inherited,
			$this->plugin->design()->components()
		) )->review();

		return array_merge(
			array(
				'target'      => 'page',
				'page_id'     => $page_id,
				'title'       => (string) get_the_title( $page_id ),
				'preview_url' => $this->plugin->page_manager()->preview_url( $page_id ),
			),
			$review
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function performance_static_audit( array $args ): array {
		$auditor = new StaticAuditor( $this->plugin->pages(), $this->plugin->renderer() );
		return $auditor->audit( absint( $args['page_id'] ?? 0 ) );
	}

	// -------------------------------------------------------------- payloads

	/**
	 * @return array<string,mixed>
	 */
	public function build_site_context(): array {
		$pages = array();
		foreach ( $this->plugin->pages()->content_page_ids() as $page_id ) {
			$manifest = $this->plugin->pages()->manifest( $page_id );
			$pages[]  = array(
				'page_id'          => $page_id,
				'title'            => get_the_title( $page_id ),
				'slug'             => get_post_field( 'post_name', $page_id ),
				'status'           => get_post_status( $page_id ),
				'version'          => $this->plugin->pages()->active_version( $page_id ),
				'page_type'        => $manifest->get( 'page_type', '' ),
				'visual_direction' => $manifest->get( 'visual_direction', '' ),
				'page_goal'        => $manifest->get( 'page_goal', '' ),
				'url'              => get_permalink( $page_id ),
			);
		}

		// Group by kind, so building the fourth service page starts by reading
		// the first one instead of designing a fourth different service page.
		$by_type = array();
		foreach ( $pages as $page ) {
			$type = '' !== $page['page_type'] ? $page['page_type'] : 'untyped';
			$by_type[ $type ][] = array( 'page_id' => $page['page_id'], 'title' => $page['title'] );
		}

		$other_pages = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => 30, 'post_status' => 'publish' ) ) as $page ) {
			$other_pages[] = array(
				'page_id' => $page->ID,
				'title'   => $page->post_title,
				'url'     => get_permalink( $page->ID ),
				'aiwp'    => $this->plugin->pages()->is_aiwp_page( $page->ID ),
			);
		}

		$theme = wp_get_theme();

		return array(
			'site'         => array(
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url(),
				'description' => get_bloginfo( 'description' ),
				'language'    => get_bloginfo( 'language' ),
			),
			'theme'        => array(
				'name'     => $theme->get( 'Name' ),
				'is_block' => wp_is_block_theme(),
			),
			'homepage'     => array(
				'id'        => FrontPage::current(),
				'url'       => home_url( '/' ),
				'title'     => FrontPage::current() ? get_the_title( FrontPage::current() ) : '',
				'is_aiwp'   => FrontPage::current() && $this->plugin->pages()->is_aiwp_page( FrontPage::current() ),
				'is_set'    => ! FrontPage::is_unset(),
				'problem'   => FrontPage::problem(),
				'how'       => 'A page named Home claims the site root on its own while nothing else has. Otherwise use site_set_front_page.',
			),
			'brand_inputs' => get_option( 'aiwp_brand_inputs', array() ),
			'aiwp_pages'   => $pages,
			'page_types'   => $by_type,
			'all_pages'    => $other_pages,
			'how_to_match' => 'Before building a page, check page_types. If a page of the same kind already exists, '
				. 'read it with page_get and build this one the same way. Two pages of the same kind that look different '
				. 'is the most visible way a site falls apart.',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_capabilities(): array {
		return array(
			'acf'                       => array(
				'available' => ACFManager::is_available(),
				'version'   => ACFManager::version(),
				'pro'       => ACFManager::is_pro(),
			),
			'field_types'               => ACFManager::available_field_types(),
			'repeater_sub_field_types'  => PageSchema::SUPPORTED_SUB_TYPES,
			'nested_repeaters'          => false,
			'behaviors'                 => AssetManager::BEHAVIORS,
			'template_language_version' => AIWP_TEMPLATE_LANGUAGE_VERSION,
			'template_filters'          => \AIWP\Designer\Template\TemplateParser::FILTERS,
			'template_blocks'           => array( 'if', 'each' ),
			'allowed_html'              => \AIWP\Designer\Template\TemplateValidator::ALLOWED_TAGS,
			'chrome_modes'              => array( 'theme', 'blank', 'site' ),
			'menus'                     => array(
				'locations' => array_keys( MenuRenderer::LOCATIONS ),
				'how'       => 'Navigation uses WordPress menus. Build one with site_set_menu and place it with <nav data-aiwp-menu="primary"></nav>. Do not build a link list in a repeater — the site owner expects to edit the menu under Appearance > Menus.',
				'styling'   => 'Style .aiwp-menu, .aiwp-menu__item, .aiwp-menu__link and .aiwp-menu__item.is-current in your chrome or page CSS.',
			),
			'site_chrome'               => array(
				'exists'  => $this->plugin->chrome()->exists(),
				'version' => $this->plugin->chrome()->version(),
				'how'     => 'Build one shared header and footer with site_set_chrome, then set chrome: "site" on each page. Without it every page has to repeat its own navigation.',
			),
			'forms'                     => array(
				'field_types' => FormSchema::FIELD_TYPES,
				'max_forms'   => FormSchema::MAX_FORMS,
				'max_fields'  => FormSchema::MAX_FIELDS,
				'how'         => 'Declare forms in the page package and place each with <div data-aiwp-form="ID"></div>. The plugin renders the markup, validates, stores the entry, emails a notification and handles spam. You never write <form>.',
				'styling'     => 'Style .aiwp-form, .aiwp-form__field, .aiwp-form__label, .aiwp-form__input, .aiwp-form__submit in your page CSS.',
			),
			'fonts'                     => array(
				'web_fonts_allowed' => true,
				'hosts'             => \AIWP\Designer\Design\DesignSystem::FONT_HOSTS,
				'how'               => 'Set typography.font_url in the design system to a stylesheet on one of those hosts, and put the family name in typography.heading_font / body_font with fallbacks. Page CSS still cannot load anything remote.',
			),
			'images'                    => array(
				'source' => 'WordPress attachment ids from media_search only. Never a path or an external URL.',
				'how'    => 'src={{image_url:…}} srcset={{image_srcset:…}} width={{image_width:…}} height={{image_height:…}} alt={{image_alt:…}}, plus your own sizes attribute.',
			),
			'max_artifact_bytes'        => Sanitizer::MAX_ARTIFACT_BYTES,
			'design_system_version'     => $this->plugin->design()->current_version(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_design_system(): array {
		$system = $this->plugin->design()->current();

		return array(
			'version'       => $this->plugin->design()->current_version(),
			'exists'        => $this->plugin->design()->current_version() > 0,
			'tokens'        => $system->tokens(),
			'css_variables' => $system->to_css(),
			'global_css'    => $this->plugin->design()->global_css(),
			// The global CSS as it was written, before scoping. Send this back
			// to restore the design system exactly as it is now.
			'authored_global_css' => $this->plugin->design()->authored_global_css(),
			'components'    => $this->plugin->design()->components()->index(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function page_payload( int $page_id ): array {
		$pages    = $this->plugin->pages();
		$schema   = $pages->schema( $page_id );
		$manifest = $pages->manifest( $page_id );

		return array(
			'page_id'         => $page_id,
			'page_uuid'       => $pages->uuid( $page_id ),
			'title'           => get_the_title( $page_id ),
			'slug'            => get_post_field( 'post_name', $page_id ),
			'status'          => get_post_status( $page_id ),
			'version'         => $pages->active_version( $page_id ),
			'design_version'  => $pages->design_version( $page_id ),
			'sections'        => $schema ? $schema->to_array() : array(),
			// Sections the site owner added for themselves. They are in sections
			// above as well, flagged owner. Sending sections without them does
			// not delete them, so there is no way to lose somebody's work by
			// accident — but a page is better when the design actually places
			// them rather than leaving them to the plain fallback at the bottom.
			'owner_sections'  => $schema ? $schema->owner_sections() : array(),
			'owner_note'      => $schema && $schema->has_owner_sections()
				? 'This page has fields the owner added themselves. Until the template prints them they render '
					. 'in a plain block after your design. Reference them like any other field to place them properly, '
					. 'and keep them in the sections you send back.'
				: '',
			'content'         => $schema ? ( new FieldValueManager() )->read( $page_id, $schema ) : array(),
			'template'        => $pages->template( $page_id ),
			'css'             => $pages->css( $page_id ),
			'behaviors'       => $manifest->get( 'behaviors', array() ),
			'forms'           => $manifest->get( 'forms', array() ),
			'design_metadata' => array(
				'page_type'          => $manifest->get( 'page_type', '' ),
				'target_words'       => $manifest->get( 'target_words', 0 ),
				'page_goal'          => $manifest->get( 'page_goal', '' ),
				'audience'           => $manifest->get( 'audience', '' ),
				'visual_direction'   => $manifest->get( 'visual_direction', '' ),
				'primary_conversion' => $manifest->get( 'primary_conversion', '' ),
				'story'              => $manifest->get( 'story', array() ),
			),
			'chrome'          => $manifest->get( 'chrome', 'theme' ),
			'preview_url'     => $this->plugin->page_manager()->preview_url( $page_id ),
			'url'             => get_permalink( $page_id ),
		);
	}

	/**
	 * @param string[] $details
	 * @return array<string,mixed>
	 */
	private function fail( string $code, string $message, array $details = array() ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
				'details' => $details,
			),
		);
	}
}
