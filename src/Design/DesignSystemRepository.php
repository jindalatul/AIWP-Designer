<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use AIWP\Designer\Storage\FileStore;

/**
 * Reads and writes the active design system and its versions.
 */
final class DesignSystemRepository {

	private const OPTION_VERSION = 'aiwp_design_version';

	private FileStore $files;

	public function __construct( FileStore $files ) {
		$this->files = $files;
	}

	public function current(): DesignSystem {
		$raw = $this->files->read( $this->files->design_dir() . '/tokens.json' );
		if ( null === $raw ) {
			return DesignSystem::defaults();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return DesignSystem::defaults();
		}

		return DesignSystem::from_array( $decoded );
	}

	public function current_version(): int {
		return absint( get_option( self::OPTION_VERSION, 0 ) );
	}

	public function global_css(): string {
		return $this->files->read( $this->files->design_dir() . '/global.css' ) ?? '';
	}

	/**
	 * Just the CSS the design system author wrote, without the compiled tokens
	 * and the plugin baseline that are prepended when it is stored.
	 */
	public function authored_global_css(): string {
		return $this->files->read( $this->files->design_dir() . '/authored.css' ) ?? '';
	}

	/**
	 * The components this site is built from.
	 */
	public function components(): ComponentLibrary {
		$raw = $this->files->read( $this->files->design_dir() . '/components.json' );
		if ( null === $raw ) {
			return ComponentLibrary::empty();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? ComponentLibrary::from_array( $decoded ) : ComponentLibrary::empty();
	}

	public function global_css_url(): string {
		$path = $this->files->design_dir() . '/global.css';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return $this->files->path_to_url( $path );
	}

	public function global_css_path(): string {
		return $this->files->design_dir() . '/global.css';
	}

	/**
	 * Store a new design system version and make it current.
	 *
	 * @return array{success:bool,version:int,errors:string[],warnings:string[]}
	 */
	public function store( DesignSystem $system, string $global_css, ?ComponentLibrary $components = null ): array {
		$validator = new CSSValidator();
		$result    = $validator->validate( $global_css, true );

		if ( ! $result['valid'] ) {
			return array(
				'success'  => false,
				'version'  => $this->current_version(),
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
			);
		}

		// Components are part of the site's visual language, so they are
		// versioned with it and roll back with it. Not sending them keeps the
		// ones already there rather than quietly deleting the site's library.
		$components = $components ?? $this->components();
		$components_css = $components->css();

		if ( '' !== $components_css ) {
			$check = $validator->validate( $components_css, true );

			if ( ! $check['valid'] ) {
				return array(
					'success'  => false,
					'version'  => $this->current_version(),
					'errors'   => array_map(
						static fn( string $e ): string => 'component library: ' . $e,
						$check['errors']
					),
					'warnings' => $result['warnings'],
				);
			}

			$components_css = $check['css'];
			$result['warnings'] = array_merge( $result['warnings'], $check['warnings'] );
		}

		$version = $this->current_version() + 1;
		$system  = $system->with_version( $version );

		// The plugin baseline is a separate stylesheet shipped with the plugin, so
		// updating the plugin updates it everywhere without re-saving the design.
		$compiled = $system->to_css() . "\n" . $result['css'];

		if ( '' !== $components_css ) {
			$compiled .= "\n\n/* ---- component library ---- */\n" . $components_css;
		}

		$targets = array(
			$this->files->design_dir( $version ) => true,
			$this->files->design_dir()           => true,
		);

		foreach ( array_keys( $targets ) as $dir ) {
			$ok = $this->files->atomic_write( $dir . '/tokens.json', (string) wp_json_encode( $system->tokens(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
				&& $this->files->atomic_write( $dir . '/global.css', $compiled )
				&& $this->files->atomic_write( $dir . '/authored.css', $result['css'] )
				&& $this->files->atomic_write( $dir . '/components.json', (string) wp_json_encode( $components->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			if ( ! $ok ) {
				$why = $this->files->last_error();

				return array(
					'success'  => false,
					'version'  => $this->current_version(),
					'errors'   => array(
						'AIWP_FILE_WRITE_FAILED: could not write the design system. '
							. ( '' !== $why ? $why : 'The plugin could not write inside the uploads folder.' ),
					),
					'warnings' => $result['warnings'],
				);
			}
		}

		update_option( self::OPTION_VERSION, $version, true );
		update_option( 'aiwp_design_updated', time(), false );

		return array(
			'success'  => true,
			'version'  => $version,
			'errors'   => array(),
			'warnings' => $result['warnings'],
		);
	}

}
