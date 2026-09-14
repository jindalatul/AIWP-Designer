<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * One escaper per output context. Never one generic escape for everything.
 */
final class OutputEscaper {

	public static function escape( string $filter, mixed $value ): string {
		switch ( $filter ) {
			case 'text':
				return esc_html( self::stringify( $value ) );

			case 'attr':
				return esc_attr( self::stringify( $value ) );

			case 'url':
				return esc_url( self::url_value( $value ) );

			case 'html':
				return wp_kses_post( self::stringify( $value ) );

			case 'post_body':
				/*
				 * The only filter that does not escape, and the only one the
				 * validator restricts to a single path. The value can only come
				 * from ArticleRenderer, which puts the_content() output there:
				 * WordPress content, already filtered on save according to what
				 * the person who wrote it was allowed to publish. Escaping it
				 * again would strip the embeds and blocks the editor produced.
				 */
				return is_string( $value ) ? $value : '';

			case 'image_url':
				return esc_url( self::image_url( $value ) );

			case 'image_srcset':
				return esc_attr( self::image_srcset( $value ) );

			case 'image_alt':
				return esc_attr( self::image_alt( $value ) );

			case 'image_width':
				return (string) self::image_dimension( $value, 'width' );

			case 'image_height':
				return (string) self::image_dimension( $value, 'height' );

			default:
				return esc_html( self::stringify( $value ) );
		}
	}

	private static function stringify( mixed $value ): string {
		if ( is_array( $value ) ) {
			// ACF link fields arrive as an array.
			if ( isset( $value['title'] ) ) {
				return (string) $value['title'];
			}
			if ( isset( $value['url'] ) ) {
				return (string) $value['url'];
			}
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}

	private static function url_value( mixed $value ): string {
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			return (string) $value['url'];
		}
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( (int) $value );
			return is_string( $url ) ? $url : '';
		}
		return self::stringify( $value );
	}

	/**
	 * The attachment id behind whatever shape the field holds.
	 */
	private static function attachment_id( mixed $value ): int {
		if ( is_array( $value ) ) {
			$value = $value['ID'] ?? ( $value['id'] ?? 0 );
		}
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * The responsive srcset WordPress already generated for this image.
	 */
	private static function image_srcset( mixed $value ): string {
		$id = self::attachment_id( $value );
		if ( 0 === $id ) {
			return '';
		}
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		return is_string( $srcset ) ? $srcset : '';
	}

	/**
	 * Alt text from the media library, so it does not have to be retyped per page.
	 */
	private static function image_alt( mixed $value ): string {
		$id = self::attachment_id( $value );
		if ( 0 === $id ) {
			return '';
		}
		return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}

	/**
	 * Intrinsic width or height, so pages reserve the right space while loading.
	 */
	private static function image_dimension( mixed $value, string $which ): int {
		$id = self::attachment_id( $value );
		if ( 0 === $id ) {
			return 0;
		}
		$meta = wp_get_attachment_metadata( $id );
		return is_array( $meta ) ? absint( $meta[ $which ] ?? 0 ) : 0;
	}

	/**
	 * Attachment ID (or an array/URL) to a real WordPress image URL.
	 */
	private static function image_url( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) ) {
				return (string) $value['url'];
			}
			if ( isset( $value['ID'] ) ) {
				$value = $value['ID'];
			} elseif ( isset( $value['id'] ) ) {
				$value = $value['id'];
			}
		}

		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_image_url( (int) $value, 'full' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( (int) $value );
			}
			return is_string( $url ) ? $url : '';
		}

		$string = self::stringify( $value );

		// Only http(s) and site-relative URLs.
		if ( '' === $string ) {
			return '';
		}
		if ( preg_match( '#^(https?:)?//#i', $string ) || 0 === strpos( $string, '/' ) ) {
			return $string;
		}

		return '';
	}
}
