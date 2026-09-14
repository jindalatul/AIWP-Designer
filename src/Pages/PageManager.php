<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\ACF\ACFManager;
use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Design\DesignSystemRepository;
use AIWP\Designer\Forms\FormSchema;
use AIWP\Designer\Pages\FrontPage;
use AIWP\Designer\Security\AuditLogger;
use AIWP\Designer\Security\Sanitizer;
use AIWP\Designer\Storage\FileStore;
use AIWP\Designer\Versioning\VersionManager;

/**
 * Creates and updates AIWP pages.
 *
 * Nothing here reports success unless the WordPress page, the schema, the files
 * and the version index all landed. On any failure the whole attempt is undone.
 */
final class PageManager {

	private FileStore $files;
	private PageRepository $pages;
	private VersionManager $versions;
	private FieldValueManager $values;
	private DesignSystemRepository $design;

	public function __construct(
		FileStore $files,
		PageRepository $pages,
		VersionManager $versions,
		FieldValueManager $values,
		DesignSystemRepository $design
	) {
		$this->files    = $files;
		$this->pages    = $pages;
		$this->versions = $versions;
		$this->values   = $values;
		$this->design   = $design;
	}

	/**
	 * Clean up after a page that is being deleted for good.
	 *
	 * Hooked to before_delete_post rather than deleted_post because the uuid
	 * lives in post meta, and by the time the post is gone there is no way left
	 * to find out which directory on disk belonged to it. Without this every
	 * deleted page leaves its whole version history behind: one test site had
	 * 199 page directories and 18MB of files for 9 real pages.
	 */
	public function forget_deleted_page( int $post_id ): void {
		if ( ! $this->pages->is_aiwp_page( $post_id ) ) {
			return;
		}

		$uuid = $this->pages->uuid( $post_id );
		if ( '' === $uuid ) {
			return;
		}

		$this->versions->delete_page_versions( $post_id, $uuid );
		$this->pages->flush_page_list_cache();
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function create( array $input, string $workflow_type = 'build_page' ): array {
		if ( ! ACFManager::is_available() ) {
			return $this->error( 'AIWP_ACF_MISSING', 'Advanced Custom Fields is not active, so pages cannot be generated.' );
		}

		$page_input = (array) ( $input['page'] ?? array() );
		$title      = Sanitizer::text( $page_input['title'] ?? '' );
		if ( '' === $title ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'page.title is required.' );
		}

		$schema  = PageSchema::from_array( (array) ( $input['sections'] ?? array() ) );
		$uuid    = Sanitizer::uuid();
		$package = array(
			'schema'    => $schema,
			'template'  => (string) ( $input['template'] ?? '' ),
			'css'       => (string) ( $input['css'] ?? '' ),
			'behaviors' => (array) ( $input['behaviors'] ?? array() ),
			'forms'     => (array) ( $input['forms'] ?? array() ),
		);

		$validation = ( new PageValidator() )->validate( $package, $uuid );
		if ( ! $validation['valid'] ) {
			AuditLogger::log( 'page_validation_rejected', array( 'title' => $title ) );
			return $this->error( 'AIWP_TEMPLATE_INVALID', 'The page package did not pass validation.', $validation );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_name'    => sanitize_title( (string) ( $page_input['slug'] ?? $title ) ),
				'post_status'  => 'publish' === ( $page_input['status'] ?? 'draft' ) ? 'draft' : 'draft',
				'post_content' => '',
				'post_author'  => get_current_user_id() ?: 1,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'AIWP_PAGE_INVALID', $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;
		$this->pages->mark_enabled( $post_id, $uuid );
		$this->pages->save_schema( $post_id, $schema );
		$this->pages->flush_page_list_cache();

		// Field groups must exist before content can be written.
		$this->refresh_acf_registration();

		$result = $this->store_version(
			$post_id,
			$uuid,
			$schema,
			$validation,
			$package,
			(array) ( $input['content'] ?? array() ),
			(array) ( $input['design_metadata'] ?? array() ),
			(string) ( $input['chrome'] ?? 'theme' ),
			$workflow_type,
			$validation['forms']
		);

		if ( ! $result['success'] ) {
			// Nothing half-created is left behind.
			wp_delete_post( $post_id, true );
			$this->versions->delete_page_versions( $post_id, $uuid );
			$this->pages->flush_page_list_cache();
			return $this->error( 'AIWP_FILE_WRITE_FAILED', $result['error'], $validation );
		}

		// A page that is plainly the homepage claims the site root, unless one is
		// already claimed or the caller said otherwise.
		$is_front = false;
		if ( FrontPage::wants_front_page( $page_input ) ) {
			$is_front = FrontPage::claim( $post_id );
		}

		AuditLogger::log( 'page_created', array( 'page_id' => $post_id, 'version' => $result['version'] ) );

		return array(
			'success'     => true,
			'page_id'     => $post_id,
			'front_page'  => $is_front,
			'front_page_pending' => ! $is_front && FrontPage::has_intent( $post_id ),
			'page_uuid'   => $uuid,
			'version'     => $result['version'],
			'status'      => 'draft',
			'preview_url' => $this->preview_url( $post_id ),
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'validation'  => array(
				'errors'   => array(),
				'warnings' => array_merge(
					$validation['warnings'],
					$this->content_warnings( $schema, (array) ( $input['content'] ?? array() ) )
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function update( array $input, string $workflow_type = 'redesign_page' ): array {
		$page_id = absint( $input['page_id'] ?? 0 );
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		$uuid    = $this->pages->uuid( $page_id );
		$current = $this->pages->active_version( $page_id );

		if ( isset( $input['expected_version'] ) && absint( $input['expected_version'] ) !== $current ) {
			return $this->error(
				'AIWP_VERSION_CONFLICT',
				sprintf( 'The page is at version %d, but the update expected version %d.', $current, absint( $input['expected_version'] ) )
			);
		}

		$existing_schema = $this->pages->schema( $page_id );
		// Sections a person added survive whatever the AI sends. It sends the
		// whole list every time, so without this a redesign deletes their work
		// and nobody finds out until the field they filled in is gone.
		$schema          = isset( $input['sections'] )
			? ( $existing_schema instanceof PageSchema
				? $existing_schema->keeping_owner_sections( (array) $input['sections'] )
				: PageSchema::from_array( (array) $input['sections'] ) )
			: ( $existing_schema ?? PageSchema::from_array( array() ) );

		$guard = $this->guard_field_renames( $existing_schema, $schema, ! empty( $input['allow_field_migration'] ) );
		if ( array() !== $guard ) {
			return $this->error( 'AIWP_FIELD_REFERENCE_MISSING', 'Fields that already hold content cannot be renamed silently.', array( 'errors' => $guard, 'warnings' => array() ) );
		}

		$manifest = $this->pages->manifest( $page_id );

		$package = array(
			'schema'    => $schema,
			'template'  => isset( $input['template'] ) ? (string) $input['template'] : $this->pages->template( $page_id ),
			'css'       => isset( $input['css'] ) ? (string) $input['css'] : $this->raw_css_for_update( $page_id, $input ),
			'behaviors' => isset( $input['behaviors'] ) ? (array) $input['behaviors'] : (array) $manifest->get( 'behaviors', array() ),
			'forms'     => isset( $input['forms'] ) ? (array) $input['forms'] : (array) $manifest->get( 'forms', array() ),
		);

		$validation = ( new PageValidator() )->validate( $package, $uuid );
		if ( ! $validation['valid'] ) {
			return $this->error( 'AIWP_TEMPLATE_INVALID', 'The update did not pass validation. Nothing was changed.', $validation );
		}

		$this->pages->save_schema( $page_id, $schema );
		$this->refresh_acf_registration();

		$content = isset( $input['content'] )
			? (array) $input['content']
			: $this->values->read( $page_id, $schema );

		// Merged, not replaced. Sending design_metadata to change one field used
		// to wipe the others, which is how a page quietly lost its page_type on
		// a CSS-only update.
		$design_metadata = array_merge(
			array(
				'page_type'          => $manifest->get( 'page_type', '' ),
				'target_words'       => $manifest->get( 'target_words', 0 ),
				'page_goal'          => $manifest->get( 'page_goal', '' ),
				'audience'           => $manifest->get( 'audience', '' ),
				'visual_direction'   => $manifest->get( 'visual_direction', '' ),
				'primary_conversion' => $manifest->get( 'primary_conversion', '' ),
				'story'              => $manifest->get( 'story', array() ),
			),
			isset( $input['design_metadata'] ) ? (array) $input['design_metadata'] : array()
		);

		$result = $this->store_version(
			$page_id,
			$uuid,
			$schema,
			$validation,
			$package,
			$content,
			$design_metadata,
			(string) ( $input['chrome'] ?? $manifest->get( 'chrome', 'theme' ) ),
			$workflow_type,
			$validation['forms']
		);

		if ( ! $result['success'] ) {
			// Put the old schema back so the live page keeps working.
			if ( $existing_schema instanceof PageSchema ) {
				$this->pages->save_schema( $page_id, $existing_schema );
				$this->refresh_acf_registration();
			}
			return $this->error( 'AIWP_FILE_WRITE_FAILED', $result['error'], $validation );
		}

		$page_input = (array) ( $input['page'] ?? array() );
		if ( array_key_exists( 'front_page', $page_input ) ) {
			if ( $page_input['front_page'] ) {
				FrontPage::claim( $page_id );
			} else {
				FrontPage::release( $page_id );
			}
		}

		AuditLogger::log( 'page_updated', array( 'page_id' => $page_id, 'version' => $result['version'] ) );

		return array(
			'success'     => true,
			'page_id'     => $page_id,
			'page_uuid'   => $uuid,
			'version'     => $result['version'],
			'status'      => get_post_status( $page_id ),
			'preview_url' => $this->preview_url( $page_id ),
			'validation'  => array(
				'errors'   => array(),
				'warnings' => array_merge( $validation['warnings'], $this->content_warnings( $schema, $content ) ),
			),
		);
	}

	/**
	 * Field values only. No new template files.
	 *
	 * @param array<int,array<string,mixed>> $updates
	 * @return array<string,mixed>
	 */
	public function update_content( int $page_id, array $updates ): array {
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		$schema = $this->pages->schema( $page_id );
		if ( ! $schema instanceof PageSchema ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'That page has no schema.' );
		}

		$applied = array();
		$errors  = array();

		foreach ( $updates as $update ) {
			$path = Sanitizer::field_path( $update['field_path'] ?? '' );
			if ( '' === $path || ! $schema->has_field( $path ) ) {
				$errors[] = sprintf( 'AIWP_FIELD_REFERENCE_MISSING: unknown field "%s".', (string) ( $update['field_path'] ?? '' ) );
				continue;
			}

			if ( $this->values->write_path( $page_id, $schema, $path, $update['value'] ?? null ) ) {
				$applied[] = $path;
			} else {
				$errors[] = sprintf( 'Could not store "%s".', $path );
			}
		}

		if ( array() !== $errors && array() === $applied ) {
			return $this->error( 'AIWP_FIELD_REFERENCE_MISSING', 'No content was updated.', array( 'errors' => $errors, 'warnings' => array() ) );
		}

		AuditLogger::log( 'page_content_updated', array( 'page_id' => $page_id, 'fields' => count( $applied ) ) );

		return array(
			'success'     => true,
			'page_id'     => $page_id,
			'updated'     => $applied,
			'errors'      => $errors,
			'preview_url' => $this->preview_url( $page_id ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function publish( int $page_id, int $version, bool $confirm ): array {
		if ( ! $confirm ) {
			return $this->error( 'AIWP_PERMISSION_DENIED', 'confirm_publish must be true.' );
		}
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		$active = $this->pages->active_version( $page_id );
		if ( $version > 0 && $version !== $active ) {
			return $this->error(
				'AIWP_VERSION_CONFLICT',
				sprintf( 'Version %d is not the current version (%d). Roll back first if that is what you want.', $version, $active )
			);
		}

		/*
		 * Somebody has to have read the page back before it goes in front of
		 * anyone. Every other check reads the parts — the template parses, the
		 * CSS is scoped, the schema is sound — and all of that passes happily
		 * on a page with a band of empty space in the middle of it, because no
		 * check looks at the page. page_look does, and this is what makes it
		 * happen rather than hoping.
		 *
		 * Tied to the version: reading version 3 says nothing about version 4.
		 */
		$looked = (int) get_post_meta( $page_id, PageRepository::META_LOOKED_AT, true );

		if ( $looked !== $active ) {
			return $this->error(
				'AIWP_NOT_LOOKED_AT',
				$looked > 0
					? sprintf(
						'This is version %d and version %d is the one that was read back. Call page_look on %d, fix anything it finds, then publish.',
						$active,
						$looked,
						$page_id
					)
					: sprintf( 'Nobody has read this page back yet. Call page_look on %d first, then publish.', $page_id )
			);
		}

		// Re-validate what is actually on disk before it goes public.
		$schema     = $this->pages->schema( $page_id );
		$manifest   = $this->pages->manifest( $page_id );
		$validation = ( new PageValidator() )->validate(
			array(
				'schema'    => $schema ?? PageSchema::from_array( array() ),
				'template'  => $this->pages->template( $page_id ),
				'css'       => '',
				'behaviors' => (array) $manifest->get( 'behaviors', array() ),
				'forms'     => (array) $manifest->get( 'forms', array() ),
			),
			$this->pages->uuid( $page_id )
		);

		if ( ! $validation['valid'] ) {
			return $this->error( 'AIWP_TEMPLATE_INVALID', 'The stored page no longer validates, so it was not published.', $validation );
		}

		wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => 'publish',
			)
		);

		$became_front = FrontPage::apply_on_publish( $page_id );

		AuditLogger::log( 'page_published', array( 'page_id' => $page_id, 'version' => $active ) );

		return array(
			'success'    => true,
			'page_id'    => $page_id,
			'status'     => 'publish',
			'url'        => get_permalink( $page_id ),
			'version'    => $active,
			'front_page' => FrontPage::is_front( $page_id ),
			'note'       => $became_front
				? 'This page is now the site front page, so it is also served at the site root.'
				: '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function rollback( int $page_id, int $version ): array {
		if ( ! $this->pages->is_aiwp_page( $page_id ) ) {
			return $this->error( 'AIWP_PAGE_INVALID', 'That page is not an AIWP page.' );
		}

		$uuid = $this->pages->uuid( $page_id );
		if ( ! $this->versions->version_exists( $page_id, $version ) ) {
			return $this->error( 'AIWP_VERSION_CONFLICT', sprintf( 'Version %d does not exist for this page.', $version ) );
		}

		$snapshot = $this->versions->read_version( $uuid, $version );
		if ( null === $snapshot ) {
			return $this->error( 'AIWP_FILE_WRITE_FAILED', 'That version could not be read from disk.' );
		}

		$schema = PageSchema::from_array( $snapshot['schema'] );
		$this->pages->save_schema( $page_id, $schema );
		$this->refresh_acf_registration();

		// The rollback itself becomes a new version; history is never destroyed.
		$next   = $this->versions->next_version( $page_id );
		$stored = $this->versions->snapshot(
			$page_id,
			$uuid,
			$next,
			array(
				'template' => $snapshot['template'],
				'css'      => $snapshot['css'],
				'schema'   => $snapshot['schema'],
				'content'  => $snapshot['content'],
				'manifest' => array_merge(
					$snapshot['manifest'],
					array(
						'template_version' => $next,
						'rolled_back_from' => $version,
						'updated_at'       => gmdate( 'c' ),
					)
				),
			),
			'rollback'
		);

		if ( ! $stored['success'] ) {
			return $this->error( 'AIWP_FILE_WRITE_FAILED', $stored['error'] );
		}

		if ( ! $this->versions->promote( $uuid, $next ) ) {
			return $this->error( 'AIWP_FILE_WRITE_FAILED', 'The rolled-back version could not be made current.' );
		}

		$this->values->write( $page_id, $schema, $snapshot['content'] );
		$this->pages->save_manifest( $page_id, new PageManifest( $snapshot['manifest'] ) );
		$this->pages->set_active_version( $page_id, $next );

		AuditLogger::log( 'page_rolled_back', array( 'page_id' => $page_id, 'to' => $version, 'new_version' => $next ) );

		return array(
			'success'          => true,
			'page_id'          => $page_id,
			'restored_version' => $version,
			'version'          => $next,
			'preview_url'      => $this->preview_url( $page_id ),
		);
	}

	public function preview_url( int $page_id ): string {
		$uuid    = $this->pages->uuid( $page_id );
		$version = $this->pages->active_version( $page_id );

		if ( 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		return add_query_arg(
			array(
				'page_id'      => $page_id,
				'aiwp_preview' => PreviewToken::create( $uuid, $version ),
			),
			home_url( '/' )
		);
	}

	// ------------------------------------------------------------- internals

	/**
	 * @param array<string,mixed> $validation
	 * @param array<string,mixed> $package
	 * @param array<string,mixed> $content
	 * @param array<string,mixed> $design_metadata
	 * @return array{success:bool,version:int,error:string}
	 */
	private function store_version(
		int $page_id,
		string $uuid,
		PageSchema $schema,
		array $validation,
		array $package,
		array $content,
		array $design_metadata,
		string $chrome,
		string $workflow_type,
		?FormSchema $forms = null
	): array {
		$version = $this->versions->next_version( $page_id );

		$manifest = PageManifest::build(
			array_merge(
				$design_metadata,
				array(
					'page_uuid'             => $uuid,
					'wordpress_page_id'     => $page_id,
					'template_version'      => $version,
					'design_system_version' => $this->design->current_version(),
					'created_by'            => 'mcp',
					'behaviors'             => $package['behaviors'],
					'forms'                 => $forms instanceof FormSchema ? $forms->to_array() : array(),
					'chrome'                => $chrome,
				)
			)
		);

		$snapshot = $this->versions->snapshot(
			$page_id,
			$uuid,
			$version,
			array(
				'template'     => $package['template'],
				'css'          => $validation['css'],
				'authored_css' => $package['css'],
				'schema'   => $schema->to_array(),
				'content'  => $content,
				'manifest' => $manifest->to_array(),
			),
			$workflow_type
		);

		if ( ! $snapshot['success'] ) {
			return array(
				'success' => false,
				'version' => 0,
				'error'   => $snapshot['error'],
			);
		}

		if ( ! $this->versions->promote( $uuid, $version ) ) {
			return array(
				'success' => false,
				'version' => 0,
				'error'   => 'AIWP_FILE_WRITE_FAILED: the new version could not be made current.',
			);
		}

		$problems = $this->values->write( $page_id, $schema, $content );
		if ( array() !== $problems ) {
			return array(
				'success' => false,
				'version' => 0,
				'error'   => implode( ' ', $problems ),
			);
		}

		$this->pages->save_manifest( $page_id, $manifest );
		$this->pages->set_active_version( $page_id, $version );
		$this->pages->set_design_version( $page_id, $this->design->current_version() );

		return array(
			'success' => true,
			'version' => $version,
			'error'   => '',
		);
	}

	/**
	 * Problems the validators cannot see, because they live in the content rather
	 * than in the template: values that WordPress will later refuse to save.
	 *
	 * @param array<string,mixed> $content
	 * @return string[]
	 */
	private function content_warnings( PageSchema $schema, array $content ): array {
		$warnings = array();

		foreach ( $schema->fields() as $path => $field ) {
			list( $section, $name ) = array_pad( explode( '.', $path ), 2, '' );
			$value                  = $content[ $section ][ $name ] ?? null;

			if ( 'url' === $field['type'] && is_string( $value ) && '' !== $value && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
				$warnings[] = sprintf(
					'Field "%s" is a url field but holds "%s". ACF only accepts absolute URLs there, so editing the page in WordPress will fail until it is fixed. Use an absolute URL, or change the field to type "link" or "text".',
					$path,
					$value
				);
			}

			if ( 'aiwp_repeater' === $field['type'] && is_array( $value ) ) {
				foreach ( $value as $index => $row ) {
					foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
						$sub_value = is_array( $row ) ? ( $row[ $sub['name'] ] ?? null ) : null;
						if ( 'url' === $sub['type'] && is_string( $sub_value ) && '' !== $sub_value && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $sub_value ) ) {
							$warnings[] = sprintf(
								'Repeater "%s" row %d subfield "%s" holds a relative URL. ACF url fields need an absolute URL; use type "link" or "text" instead.',
								$path,
								(int) $index + 1,
								$sub['name']
							);
						}
					}
				}
			}
		}

		return $warnings;
	}

	/**
	 * Page CSS on disk is already scoped, so re-validating it would double-scope.
	 * When an update does not send new CSS we keep exactly what is stored.
	 *
	 * @param array<string,mixed> $input
	 */
	private function raw_css_for_update( int $page_id, array $input ): string {
		unset( $input );
		$stored = $this->pages->css( $page_id );
		$scope  = '[data-aiwp-page="' . $this->pages->uuid( $page_id ) . '"]';
		// Already-scoped CSS passes back through the scoper unchanged.
		return str_replace( $scope . ' ' . $scope, $scope, $stored );
	}

	/**
	 * A field that already holds content keeps its id. Renaming needs an explicit ask.
	 *
	 * @return string[]
	 */
	private function guard_field_renames( ?PageSchema $old, PageSchema $new, bool $allow_migration ): array {
		if ( ! $old instanceof PageSchema || $allow_migration ) {
			return array();
		}

		$errors    = array();
		$new_by_id = array();

		// A field id is unique inside its section, not across the page.
		foreach ( $new->fields() as $path => $field ) {
			$section = explode( '.', $path )[0];
			$new_by_id[ $section . '.' . $field['id'] ] = $path;
		}

		foreach ( $old->fields() as $path => $field ) {
			$section = explode( '.', $path )[0];
			$key     = $section . '.' . $field['id'];

			if ( ! isset( $new_by_id[ $key ] ) ) {
				continue;
			}
			if ( $new_by_id[ $key ] !== $path ) {
				$errors[] = sprintf(
					'Field id "%s" moved from "%s" to "%s". Pass allow_field_migration:true if that rename is intended.',
					$field['id'],
					$path,
					$new_by_id[ $key ]
				);
			}
		}

		return $errors;
	}

	private function refresh_acf_registration(): void {
		$this->pages->flush_page_list_cache();

		if ( function_exists( 'acf_get_local_store' ) ) {
			// Re-run registration so new fields exist within this same request.
			do_action( 'aiwp/refresh_acf' );
		}
	}

	/**
	 * @param array<string,mixed> $validation
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, array $validation = array() ): array {
		return array(
			'success'    => false,
			'error'      => array(
				'code'    => $code,
				'message' => $message,
			),
			'validation' => array(
				'errors'   => $validation['errors'] ?? array(),
				'warnings' => $validation['warnings'] ?? array(),
			),
		);
	}
}
