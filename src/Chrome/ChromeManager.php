<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Chrome;

use AIWP\Designer\ACF\FieldValueManager;
use AIWP\Designer\Design\CSSValidator;
use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Security\AuditLogger;
use AIWP\Designer\Security\Sanitizer;
use AIWP\Designer\Storage\FileStore;
use AIWP\Designer\Template\TemplateEngine;
use AIWP\Designer\Template\TemplateParser;
use AIWP\Designer\Template\TemplateValidator;
use AIWP\Designer\Versioning\VersionManager;

/**
 * One header and one footer, shared by every AIWP page.
 *
 * The content lives on a hidden WordPress page so it is editable through normal
 * ACF fields, exactly like page content. The markup and CSS live on disk and are
 * versioned like a page.
 */
final class ChromeManager {

	public const OPTION_PAGE    = 'aiwp_chrome_page_id';
	public const OPTION_VERSION = 'aiwp_chrome_version';
	public const META_CHROME    = '_aiwp_chrome';
	public const SCOPE          = '[data-aiwp-chrome]';

	private FileStore $files;
	private PageRepository $pages;
	private VersionManager $versions;
	private FieldValueManager $values;
	private ?MenuRenderer $menus;

	public function __construct( FileStore $files, PageRepository $pages, VersionManager $versions, FieldValueManager $values, ?MenuRenderer $menus = null ) {
		$this->files    = $files;
		$this->pages    = $pages;
		$this->versions = $versions;
		$this->values   = $values;
		$this->menus    = $menus;
	}

	public function version(): int {
		return absint( get_option( self::OPTION_VERSION, 0 ) );
	}

	public function exists(): bool {
		return $this->version() > 0 && 0 !== $this->holder_page_id( false );
	}

	public function dir( ?int $version = null ): string {
		$root = $this->files->root() . '/chrome';
		return null === $version ? $root . '/current' : $root . '/versions/' . absint( $version );
	}

	public function header_template(): string {
		return $this->files->read( $this->dir() . '/header.aiwp' ) ?? '';
	}

	public function footer_template(): string {
		return $this->files->read( $this->dir() . '/footer.aiwp' ) ?? '';
	}

	public function css(): string {
		return $this->files->read( $this->dir() . '/chrome.css' ) ?? '';
	}

	/**
	 * The CSS as it was written, before scoping.
	 */
	public function authored_css(): string {
		$css = $this->files->read( $this->dir() . '/authored.css' );

		if ( null !== $css && '' !== $css ) {
			return $css;
		}

		return CSSValidator::format( $this->css() );
	}

	public function css_url(): string {
		$path = $this->dir() . '/chrome.css';
		return file_exists( $path ) ? $this->files->path_to_url( $path ) : '';
	}

	public function css_path(): string {
		return $this->dir() . '/chrome.css';
	}

	public function schema(): ?PageSchema {
		$page_id = $this->holder_page_id( false );
		return $page_id ? $this->pages->schema( $page_id ) : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function content(): array {
		$page_id = $this->holder_page_id( false );
		$schema  = $this->schema();

		if ( ! $page_id || ! $schema instanceof PageSchema ) {
			return array();
		}

		return $this->values->read( $page_id, $schema );
	}

	/**
	 * Render the header, or the footer, using live field values.
	 */
	public function render( string $part ): string {
		$template = 'header' === $part ? $this->header_template() : $this->footer_template();
		if ( '' === trim( $template ) ) {
			return '';
		}

		$key    = 'aiwp_chrome_ast_' . $part . '_' . substr( md5( $template ), 0, 12 );
		$ast    = wp_cache_get( $key, 'aiwp' );

		if ( ! $ast instanceof \AIWP\Designer\Template\TemplateAST ) {
			$ast = ( new TemplateParser() )->parse( $template );
			wp_cache_set( $key, $ast, 'aiwp', 300 );
		}

		$html = ( new TemplateEngine() )->render( $ast, $this->content() );

		// Navigation comes from Appearance > Menus, not from this template.
		if ( $this->menus instanceof MenuRenderer ) {
			$html = $this->menus->inject( $html );
		}

		return sprintf(
			'<%1$s class="aiwp-chrome aiwp-chrome--%2$s" data-aiwp-chrome="%2$s">%3$s</%1$s>',
			'header' === $part ? 'header' : 'footer',
			esc_attr( $part ),
			$html
		);
	}

	/**
	 * Store a new header and footer.
	 *
	 * @param array<string,mixed> $input sections, content, header, footer, css
	 * @return array<string,mixed>
	 */
	public function store( array $input ): array {
		$schema = PageSchema::from_array( (array) ( $input['sections'] ?? array() ) );

		$errors   = $schema->errors();
		$warnings = array();

		$validator = new TemplateValidator();

		$header = (string) ( $input['header'] ?? '' );
		$footer = (string) ( $input['footer'] ?? '' );

		if ( '' === trim( $header ) && '' === trim( $footer ) ) {
			return $this->fail( array( 'Send at least a header or a footer.' ) );
		}

		foreach ( array( 'header' => $header, 'footer' => $footer ) as $part => $markup ) {
			if ( '' === trim( $markup ) ) {
				continue;
			}
			$result = $validator->validate( $markup, $schema );
			foreach ( $result['errors'] as $error ) {
				$errors[] = sprintf( 'AIWP_TEMPLATE_INVALID (%s): %s', $part, $error );
			}
			foreach ( $result['warnings'] as $warning ) {
				$warnings[] = sprintf( '%s: %s', $part, $warning );
			}
		}

		// match_root: the header and footer elements carry the scope attribute, so a
		// rule written for .aiwp-chrome--header has to be able to match them.
		$css_result = ( new CSSValidator() )->validate_and_scope_to( (string) ( $input['css'] ?? '' ), self::SCOPE, true );
		foreach ( $css_result['errors'] as $error ) {
			$errors[] = 'AIWP_CSS_INVALID: ' . $error;
		}
		foreach ( $css_result['warnings'] as $warning ) {
			$warnings[] = $warning;
		}

		if ( array() !== $errors ) {
			return $this->fail( $errors, $warnings );
		}

		$page_id = $this->holder_page_id( true );
		if ( 0 === $page_id ) {
			return $this->fail( array( 'AIWP_FILE_WRITE_FAILED: could not create the chrome holder page.' ) );
		}

		$uuid = $this->pages->uuid( $page_id );
		$this->pages->save_schema( $page_id, $schema );
		$this->pages->flush_page_list_cache();
		do_action( 'aiwp/refresh_acf' );

		$version = $this->versions->next_version( $page_id );
		$content = (array) ( $input['content'] ?? array() );

		$payload = array(
			'header.aiwp'   => $header,
			'footer.aiwp'   => $footer,
			'chrome.css'    => $css_result['css'],
			'authored.css'  => (string) ( $input['css'] ?? '' ),
			'schema.json'   => (string) wp_json_encode( $schema->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'content.json'  => (string) wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'manifest.json' => (string) wp_json_encode(
				array(
					'chrome_version' => $version,
					'updated_at'     => gmdate( 'c' ),
					'note'           => Sanitizer::text( $input['note'] ?? '' ),
				),
				JSON_PRETTY_PRINT
			),
		);

		$snapshot = $this->versions->snapshot_files(
			$page_id,
			$uuid,
			$version,
			$this->dir( $version ),
			$payload,
			array( 'chrome_version' => $version ),
			'build_chrome'
		);

		if ( ! $snapshot['success'] ) {
			return $this->fail( array( $snapshot['error'] ), $warnings );
		}

		if ( ! $this->files->copy_dir( $this->dir( $version ), $this->dir() ) ) {
			return $this->fail( array( 'AIWP_FILE_WRITE_FAILED: the new chrome could not be made current.' ), $warnings );
		}

		$problems = $this->values->write( $page_id, $schema, $content );
		if ( array() !== $problems ) {
			return $this->fail( $problems, $warnings );
		}

		update_option( self::OPTION_VERSION, $version, true );
		AuditLogger::log( 'chrome_updated', array( 'version' => $version ) );

		return array(
			'success'  => true,
			'version'  => $version,
			'scope'    => self::SCOPE,
			'edit_url' => get_edit_post_link( $page_id, 'raw' ),
			'warnings' => $warnings,
			'errors'   => array(),
		);
	}

	/**
	 * The hidden page that holds the chrome's ACF fields.
	 */
	public function holder_page_id( bool $create ): int {
		$page_id = absint( get_option( self::OPTION_PAGE, 0 ) );

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) {
			return $page_id;
		}

		if ( ! $create ) {
			return 0;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => __( 'Site header & footer', 'aiwp-designer' ),
				'post_name'    => 'aiwp-site-chrome',
				'post_status'  => 'draft',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		$page_id = (int) $page_id;
		$this->pages->mark_enabled( $page_id, Sanitizer::uuid() );
		update_post_meta( $page_id, self::META_CHROME, '1' );
		update_option( self::OPTION_PAGE, $page_id, true );

		return $page_id;
	}

	public function is_holder( int $page_id ): bool {
		return '1' === (string) get_post_meta( $page_id, self::META_CHROME, true );
	}

	/**
	 * @param string[] $errors
	 * @param string[] $warnings
	 * @return array<string,mixed>
	 */
	private function fail( array $errors, array $warnings = array() ): array {
		return array(
			'success'  => false,
			'error'    => array(
				'code'    => 'AIWP_TEMPLATE_INVALID',
				'message' => 'The header and footer package did not pass validation. Nothing was changed.',
			),
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => $warnings,
		);
	}
}
