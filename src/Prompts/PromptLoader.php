<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Prompts;

/**
 * Loads prompt markdown files from inside the plugin. Nothing else.
 */
final class PromptLoader {

	private string $base;

	public function __construct( ?string $base = null ) {
		$this->base = rtrim( $base ?? AIWP_PLUGIN_DIR . 'prompts', '/' );
	}

	/**
	 * @return array{id:string,version:int,category:string,description:string,body:string}|null
	 */
	public function load( string $relative ): ?array {
		$path = $this->resolve( $relative );
		if ( null === $path ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return null;
		}

		return $this->parse( $raw, $relative );
	}

	/**
	 * A prompt name can never escape the prompts directory.
	 */
	private function resolve( string $relative ): ?string {
		if ( false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '..' ) ) {
			return null;
		}
		if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $relative ) ) {
			return null;
		}

		$path = $this->base . '/' . $relative . '.md';
		$real = realpath( $path );
		$root = realpath( $this->base );

		if ( false === $real || false === $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * @return array{id:string,version:int,category:string,description:string,body:string}|null
	 */
	private function parse( string $raw, string $relative ): ?array {
		$raw = ltrim( $raw );

		if ( 0 !== strpos( $raw, '---' ) ) {
			return null;
		}

		$end = strpos( $raw, "\n---", 3 );
		if ( false === $end ) {
			return null;
		}

		$front = trim( substr( $raw, 3, $end - 3 ) );
		$body  = trim( substr( $raw, $end + 4 ) );

		$meta = array();
		foreach ( preg_split( '/\r?\n/', $front ) ?: array() as $line ) {
			if ( ! preg_match( '/^([a-z_]+)\s*:\s*(.*)$/i', trim( $line ), $m ) ) {
				continue;
			}
			$meta[ strtolower( $m[1] ) ] = trim( $m[2], " \"'" );
		}

		if ( empty( $meta['id'] ) ) {
			return null;
		}

		return array(
			'id'          => (string) $meta['id'],
			'version'     => absint( $meta['version'] ?? 1 ),
			'category'    => (string) ( $meta['category'] ?? explode( '/', $relative )[0] ),
			'description' => (string) ( $meta['description'] ?? '' ),
			'body'        => $body,
		);
	}
}
