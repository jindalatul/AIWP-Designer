<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\Design\CSSValidator;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Security\AuditLogger;
use AIWP\Designer\Storage\FileStore;
use AIWP\Designer\Template\TemplateValidator;

/**
 * The design for a list of articles: the blog index, a category, a tag, a
 * search result, an author's posts.
 *
 * One design covers all of them. They differ only in what the list is of and
 * what it should be called, and both of those are values the renderer supplies
 * rather than reasons to draw a second layout.
 *
 * Without this, a site that builds twenty articles has no page that lists them,
 * so the only way to reach one is to already know its address.
 */
final class ArchiveTemplate {

	public const SCOPE          = '[data-aiwp-archive]';
	public const OPTION_VERSION = 'aiwp_archive_version';

	private FileStore $files;

	public function __construct( FileStore $files ) {
		$this->files = $files;
	}

	public function version(): int {
		return absint( get_option( self::OPTION_VERSION, 0 ) );
	}

	public function exists(): bool {
		return $this->version() > 0 && '' !== trim( $this->template() );
	}

	public function dir( ?int $version = null ): string {
		$root = $this->files->root() . '/archive';

		return null === $version ? $root . '/current' : $root . '/versions/' . absint( $version );
	}

	public function template(): string {
		return $this->files->read( $this->dir() . '/template.aiwp' ) ?? '';
	}

	public function css(): string {
		return $this->files->read( $this->dir() . '/style.css' ) ?? '';
	}

	public function authored_css(): string {
		return $this->files->read( $this->dir() . '/authored.css' ) ?? '';
	}

	public function css_path(): string {
		return $this->dir() . '/style.css';
	}

	public function css_url(): string {
		$path = $this->css_path();

		return file_exists( $path ) ? $this->files->path_to_url( $path ) : '';
	}

	public function schema(): ?PageSchema {
		$raw = $this->files->read( $this->dir() . '/schema.json' );
		if ( null === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? PageSchema::from_array( $decoded ) : null;
	}

	/**
	 * @param array<string,mixed> $input sections, template, css
	 * @return array<string,mixed>
	 */
	public function store( array $input ): array {
		$schema   = PageSchema::from_array( (array) ( $input['sections'] ?? array() ) );
		$template = (string) ( $input['template'] ?? '' );

		// A design with no extra fields of its own is legitimate: an archive reads
		// everything from WordPress, and an article can be body and typography
		// alone. Only judge the schema when one was actually sent.
		$errors   = array() === (array) ( $input['sections'] ?? array() ) ? array() : $schema->errors();
		$warnings = array();

		if ( '' === trim( $template ) ) {
			return $this->fail( array( 'AIWP_TEMPLATE_INVALID: send a template.' ) );
		}

		$result = ( new TemplateValidator() )->validate(
			$template,
			$schema->with_sections( ArchiveContent::schema_sections() )
		);

		foreach ( $result['errors'] as $error ) {
			$errors[] = 'AIWP_TEMPLATE_INVALID: ' . $error;
		}
		$warnings = array_merge( $warnings, $result['warnings'] );

		if ( false === strpos( $template, 'archive.items' ) ) {
			$warnings[] = 'This template never loops over archive.items, so the list will be empty whatever is in it.';
		}

		$css    = (string) ( $input['css'] ?? '' );
		$scoped = '';

		if ( '' !== trim( $css ) ) {
			$check = ( new CSSValidator() )->validate_and_scope_to( $css, self::SCOPE, true );

			foreach ( $check['errors'] as $error ) {
				$errors[] = 'AIWP_CSS_INVALID: ' . $error;
			}
			$warnings = array_merge( $warnings, $check['warnings'] );
			$scoped   = $check['css'];
		}

		if ( array() !== $errors ) {
			return $this->fail( $errors, $warnings );
		}

		$version = $this->version() + 1;

		foreach ( array( $this->dir( $version ), $this->dir() ) as $dir ) {
			$ok = $this->files->atomic_write( $dir . '/template.aiwp', $template )
				&& $this->files->atomic_write( $dir . '/style.css', $scoped )
				&& $this->files->atomic_write( $dir . '/authored.css', $css )
				&& $this->files->atomic_write( $dir . '/schema.json', (string) wp_json_encode( $schema->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			if ( ! $ok ) {
				return $this->fail(
					array( 'AIWP_FILE_WRITE_FAILED: could not write the archive design. ' . $this->files->last_error() ),
					$warnings
				);
			}
		}

		update_option( self::OPTION_VERSION, $version, true );
		AuditLogger::log( 'archive_template_stored', array( 'version' => $version ) );

		return array( 'success' => true, 'version' => $version, 'errors' => array(), 'warnings' => $warnings );
	}

	/**
	 * @param string[] $errors
	 * @param string[] $warnings
	 * @return array<string,mixed>
	 */
	private function fail( array $errors, array $warnings = array() ): array {
		return array(
			'success'  => false,
			'version'  => $this->version(),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}
}
