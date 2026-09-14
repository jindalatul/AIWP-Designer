<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Media;

use AIWP\Designer\Security\Sanitizer;

/**
 * Puts an image into the WordPress media library on the AI's behalf.
 *
 * Three ways in: a public URL, base64 bytes, or SVG markup the AI wrote. All
 * three end at the same place, where the bytes are sniffed for what they
 * really are before anything is written. The claimed type and the file name
 * the caller sent are never trusted.
 */
final class ImageImporter {

	public const MAX_BYTES = 8388608; // 8 MB.

	/** Bounds on the picture itself, not the file. */
	public const MAX_SIDE   = 10000;
	public const MAX_PIXELS = 40000000;

	/** Real media types we accept, and the extension each one must be saved as. */
	public const TYPES = array(
		'image/jpeg'    => 'jpg',
		'image/png'     => 'png',
		'image/gif'     => 'gif',
		'image/webp'    => 'webp',
		'image/avif'    => 'avif',
		'image/svg+xml' => 'svg',
	);

	/** Marks an attachment this plugin created, so it can be told apart later. */
	public const SOURCE_META = '_aiwp_image_source';

	/**
	 * @return array{ok:bool,error:string,code:string,image?:array<string,mixed>,removed?:string[]}
	 */
	public function from_url( string $url, string $alt, string $title = '', string $filename = '' ): array {
		$url = trim( $url );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $this->fail( 'AIWP_BAD_IMAGE_URL', 'The image URL must start with http:// or https://.' );
		}

		// wp_safe_remote_get refuses loopback and private addresses, so a URL
		// cannot be used to read something inside the server's own network.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'limit_response_size' => self::MAX_BYTES + 1024,
				'redirection'         => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( 'AIWP_IMAGE_FETCH_FAILED', 'Could not download that image: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status > 299 ) {
			return $this->fail( 'AIWP_IMAGE_FETCH_FAILED', sprintf( 'The image URL answered with status %d.', $status ) );
		}

		if ( '' === $filename ) {
			$filename = (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}

		return $this->store( (string) wp_remote_retrieve_body( $response ), $alt, $title, $filename );
	}

	/**
	 * @return array{ok:bool,error:string,code:string,image?:array<string,mixed>,removed?:string[]}
	 */
	public function from_base64( string $data, string $alt, string $title = '', string $filename = '' ): array {
		// Accept a data: URI as well as bare base64, because both get pasted.
		if ( preg_match( '#^data:[^;,]*;base64,#i', $data ) ) {
			$data = (string) preg_replace( '#^data:[^;,]*;base64,#i', '', $data );
		}

		$data = preg_replace( '/\s+/', '', $data ) ?? $data;

		if ( '' === $data ) {
			return $this->fail( 'AIWP_BAD_IMAGE_DATA', 'No image data was sent.' );
		}

		$bytes = base64_decode( $data, true );
		if ( false === $bytes || '' === $bytes ) {
			return $this->fail( 'AIWP_BAD_IMAGE_DATA', 'The data is not valid base64.' );
		}

		return $this->store( $bytes, $alt, $title, $filename );
	}

	/**
	 * @return array{ok:bool,error:string,code:string,image?:array<string,mixed>,removed?:string[]}
	 */
	public function from_svg( string $markup, string $alt, string $title = '', string $filename = '' ): array {
		$clean = ( new SvgSanitizer() )->sanitize( $markup );

		if ( ! $clean['ok'] ) {
			return $this->fail( 'AIWP_BAD_SVG', $clean['error'] );
		}

		$result = $this->store( $clean['svg'], $alt, $title, '' === $filename ? 'illustration.svg' : $filename );

		if ( $result['ok'] && array() !== $clean['removed'] ) {
			$result['removed'] = $clean['removed'];
		}

		return $result;
	}

	/**
	 * Sniff the bytes, name the file safely, write it, and register it.
	 *
	 * @return array{ok:bool,error:string,code:string,image?:array<string,mixed>}
	 */
	private function store( string $bytes, string $alt, string $title, string $filename ): array {
		if ( '' === $bytes ) {
			return $this->fail( 'AIWP_BAD_IMAGE_DATA', 'The image is empty.' );
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return $this->fail(
				'AIWP_IMAGE_TOO_LARGE',
				sprintf( 'The image is %d bytes; the limit is %d.', strlen( $bytes ), self::MAX_BYTES )
			);
		}

		$mime = $this->sniff( $bytes );
		if ( '' === $mime ) {
			return $this->fail(
				'AIWP_UNSUPPORTED_IMAGE',
				'That file is not an image this site accepts. Allowed: ' . implode( ', ', array_keys( self::TYPES ) ) . '.'
			);
		}

		if ( 'image/svg+xml' !== $mime ) {
			$check = $this->check_dimensions( $bytes );
			if ( '' !== $check ) {
				return $this->fail( 'AIWP_UNSUPPORTED_IMAGE', $check );
			}
		}

		$extension = self::TYPES[ $mime ];
		$name      = $this->safe_filename( $filename, $extension );

		// WordPress refuses SVG (and on older installs webp or avif) uploads by
		// default. Allow this one type for this one write, having already
		// sanitized the file ourselves, then put the rule straight back.
		$allow = static function ( array $mimes ) use ( $mime, $extension ): array {
			$mimes[ $extension ] = $mime;
			return $mimes;
		};

		add_filter( 'upload_mimes', $allow, 999 );
		$written = wp_upload_bits( $name, null, $bytes );
		remove_filter( 'upload_mimes', $allow, 999 );

		if ( ! empty( $written['error'] ) ) {
			return $this->fail( 'AIWP_IMAGE_WRITE_FAILED', (string) $written['error'] );
		}

		$path = (string) $written['file'];

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => '' !== $title ? Sanitizer::text( $title ) : pathinfo( $name, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$path,
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $path );
			return $this->fail( 'AIWP_IMAGE_WRITE_FAILED', $attachment_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$generated = wp_generate_attachment_metadata( $attachment_id, $path );

		if ( 'image/svg+xml' === $mime ) {
			// No image library reads an SVG, so take the size off the viewBox.
			// Without it a template cannot write width and height and the page
			// shifts while it loads.
			$generated = is_array( $generated ) ? $generated : array();
			$size      = $this->svg_size( $bytes );
			if ( array() !== $size ) {
				$generated['width']  = $size['width'];
				$generated['height'] = $size['height'];
				$generated['file']   = _wp_relative_upload_path( $path );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $generated );

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', Sanitizer::text( $alt ) );
		update_post_meta( $attachment_id, self::SOURCE_META, 'aiwp' );

		$meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'ok'    => true,
			'error' => '',
			'code'  => '',
			'image' => array(
				'id'     => (int) $attachment_id,
				'url'    => (string) wp_get_attachment_url( $attachment_id ),
				'alt'    => Sanitizer::text( $alt ),
				'mime'   => $mime,
				'width'  => is_array( $meta ) ? ( $meta['width'] ?? null ) : null,
				'height' => is_array( $meta ) ? ( $meta['height'] ?? null ) : null,
				'bytes'  => strlen( $bytes ),
			),
		);
	}

	/**
	 * Refuse a picture whose stated size is impossible.
	 *
	 * A truncated or hand-made file can claim billions of pixels a side. The
	 * header parses, so the type check passes, and then the first attempt to
	 * make a thumbnail tries to allocate all of it.
	 *
	 * @return string An empty string when the size is fine, else why it is not.
	 */
	private function check_dimensions( string $bytes ): string {
		$info = getimagesizefromstring( $bytes );

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return 'The image header could not be read. The file is probably incomplete.';
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		if ( $width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE ) {
			return sprintf(
				'The image says it is %d by %d pixels. Each side must be between 1 and %d.',
				$width,
				$height,
				self::MAX_SIDE
			);
		}

		if ( $width * $height > self::MAX_PIXELS ) {
			return sprintf(
				'The image is %d pixels in total; the limit is %d.',
				$width * $height,
				self::MAX_PIXELS
			);
		}

		return '';
	}

	/**
	 * The drawing size of an SVG, from its viewBox or its width and height.
	 *
	 * @return array{width:int,height:int}|array{}
	 */
	private function svg_size( string $svg ): array {
		if ( preg_match( '/viewBox\s*=\s*["\']\s*[-\d.eE]+[,\s]+[-\d.eE]+[,\s]+([\d.eE]+)[,\s]+([\d.eE]+)\s*["\']/', $svg, $m ) ) {
			$w = (int) round( (float) $m[1] );
			$h = (int) round( (float) $m[2] );
			if ( $w > 0 && $h > 0 ) {
				return array( 'width' => $w, 'height' => $h );
			}
		}

		if ( preg_match( '/\bwidth\s*=\s*["\']([\d.]+)/', $svg, $w ) && preg_match( '/\bheight\s*=\s*["\']([\d.]+)/', $svg, $h ) ) {
			$width  = (int) round( (float) $w[1] );
			$height = (int) round( (float) $h[1] );
			if ( $width > 0 && $height > 0 ) {
				return array( 'width' => $width, 'height' => $height );
			}
		}

		return array();
	}

	/**
	 * What the bytes actually are, ignoring anything the caller said.
	 */
	private function sniff( string $bytes ): string {
		$info = getimagesizefromstring( $bytes );
		if ( is_array( $info ) && ! empty( $info['mime'] ) && isset( self::TYPES[ $info['mime'] ] ) ) {
			return (string) $info['mime'];
		}

		// getimagesize does not know every format. Fall back to the magic
		// bytes for the ones it misses, and to the XML shape for SVG.
		if ( 0 === strncmp( $bytes, "RIFF", 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		if ( 'ftyp' === substr( $bytes, 4, 4 ) && false !== strpos( substr( $bytes, 8, 16 ), 'avif' ) ) {
			return 'image/avif';
		}

		$head = ltrim( substr( $bytes, 0, 512 ) );
		if ( '' !== $head && ( 0 === strncmp( $head, '<?xml', 5 ) || 0 === strncmp( $head, '<svg', 4 ) ) && false !== stripos( substr( $bytes, 0, 2048 ), '<svg' ) ) {
			return 'image/svg+xml';
		}

		return '';
	}

	/**
	 * A file name the site chose, not one the caller did.
	 */
	private function safe_filename( string $filename, string $extension ): string {
		$base = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
		$base = trim( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $base ) ?? '', '-' );
		$base = strtolower( substr( $base, 0, 60 ) );

		if ( '' === $base ) {
			$base = 'image';
		}

		return $base . '-' . substr( (string) wp_generate_password( 8, false, false ), 0, 6 ) . '.' . $extension;
	}

	/**
	 * @return array{ok:bool,error:string,code:string}
	 */
	private function fail( string $code, string $message ): array {
		return array(
			'ok'    => false,
			'error' => $message,
			'code'  => $code,
		);
	}
}
