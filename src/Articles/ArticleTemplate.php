<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

use AIWP\Designer\Design\CSSValidator;
use AIWP\Designer\Pages\PageSchema;
use AIWP\Designer\Security\AuditLogger;
use AIWP\Designer\Storage\FileStore;
use AIWP\Designer\Template\TemplateValidator;

/**
 * One design that every article renders through.
 *
 * A landing page is one design for one page, so its template lives with it. An
 * article is the opposite: two hundred articles, one design. Storing a template
 * per post would mean two hundred copies of it and two hundred ways to drift,
 * so it is stored once, here, and bound to a kind of article.
 *
 * The body is not stored here. It stays in post_content, written in the normal
 * WordPress editor, because a writer needs a real writing surface and the rest
 * of WordPress expects to find it there. This holds the furniture around it:
 * the schema for the extra fields, the markup, and the CSS.
 *
 * `kind` exists so a how-to and a case study can look different later. Today
 * there is one, called default.
 */
final class ArticleTemplate {

	public const DEFAULT_KIND = 'default';
	public const SCOPE        = '[data-aiwp-article]';
	public const OPTION_KINDS = 'aiwp_article_kinds';

	private FileStore $files;

	private string $kind;

	public function __construct( FileStore $files, string $kind = self::DEFAULT_KIND ) {
		$this->files = $files;
		$this->kind  = '' !== sanitize_key( $kind ) ? sanitize_key( $kind ) : self::DEFAULT_KIND;
	}

	public function kind(): string {
		return $this->kind;
	}

	/**
	 * What owns these fields. Not a post: one article design owns the same
	 * fields on every post of a type, so the key has to come from the design.
	 */
	public function field_uuid(): string {
		return 'aiwp_article_' . $this->kind;
	}

	public function version(): int {
		$kinds = (array) get_option( self::OPTION_KINDS, array() );

		return absint( $kinds[ $this->kind ] ?? 0 );
	}

	public function exists(): bool {
		return $this->version() > 0 && '' !== trim( $this->template() );
	}

	public function dir( ?int $version = null ): string {
		$root = $this->files->root() . '/articles/' . $this->kind;

		return null === $version ? $root . '/current' : $root . '/versions/' . absint( $version );
	}

	public function template(): string {
		return $this->files->read( $this->dir() . '/template.aiwp' ) ?? '';
	}

	public function css(): string {
		return $this->files->read( $this->dir() . '/style.css' ) ?? '';
	}

	/** The CSS as written, before it was scoped to the article wrapper. */
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
	 * Every kind that has been built.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array_keys( array_filter( (array) get_option( self::OPTION_KINDS, array() ) ) );
	}

	/**
	 * Store a new version of this article design.
	 *
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

		// The post's own fields are not in the schema, so the validator is told
		// about them before it decides which paths are real.
		$result = ( new TemplateValidator() )->validate(
			$template,
			$schema->with_sections( ArticleContent::schema_sections() )
		);

		foreach ( $result['errors'] as $error ) {
			$errors[] = 'AIWP_TEMPLATE_INVALID: ' . $error;
		}
		$warnings = array_merge( $warnings, $result['warnings'] );

		if ( false === strpos( $template, 'post.body' ) ) {
			$warnings[] = 'This template never prints post.body, so the article text will not appear anywhere.';
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
				return $this->fail( array( rtrim( 'AIWP_FILE_WRITE_FAILED: could not write the article design. ' . $this->files->last_error() ) ), $warnings );
			}
		}

		$kinds                = (array) get_option( self::OPTION_KINDS, array() );
		$kinds[ $this->kind ] = $version;
		update_option( self::OPTION_KINDS, $kinds, true );

		AuditLogger::log( 'article_template_stored', array( 'kind' => $this->kind, 'version' => $version ) );

		return array(
			'success'  => true,
			'kind'     => $this->kind,
			'version'  => $version,
			'errors'   => array(),
			'warnings' => $warnings,
		);
	}

	/**
	 * @param string[] $errors
	 * @param string[] $warnings
	 * @return array<string,mixed>
	 */
	private function fail( array $errors, array $warnings = array() ): array {
		return array(
			'success'  => false,
			'kind'     => $this->kind,
			'version'  => $this->version(),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}
}
