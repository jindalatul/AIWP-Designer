<?php
/**
 * Unit-test bootstrap.
 *
 * These tests cover the parts that do not need a WordPress database: the
 * template parser, the validators, the CSS scoper and the schema. A handful of
 * WordPress escaping helpers are stubbed so those classes can run standalone.
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'AIWP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'AIWP_PLUGIN_URL', 'http://example.test/wp-content/plugins/aiwp-designer/' );
define( 'AIWP_VERSION', '0.1.0' );
define( 'AIWP_TEMPLATE_LANGUAGE_VERSION', 1 );

require_once dirname( __DIR__ ) . '/src/autoload.php';

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	$url = trim( (string) $url );
	if ( preg_match( '/^(javascript|vbscript|data):/i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function wp_kses_post( $text ) {
	return strip_tags( (string) $text, '<p><a><strong><em><ul><ol><li><br>' );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, (int) $flags );
}

function __( $text, $domain = '' ) {
	return $text;
}

function wp_get_attachment_image_url( $id, $size = 'large' ) {
	return 'http://example.test/wp-content/uploads/image-' . (int) $id . '.jpg';
}

function wp_get_attachment_url( $id ) {
	return wp_get_attachment_image_url( $id );
}

function wp_get_attachment_image_srcset( $id, $size = 'full' ) {
	return sprintf( 'http://example.test/i-%1$d-800.jpg 800w, http://example.test/i-%1$d-2400.jpg 2400w', (int) $id );
}

function wp_get_attachment_metadata( $id ) {
	return array( 'width' => 2400, 'height' => 1600 );
}

function get_post_meta( $id, $key, $single = false ) {
	return '_wp_attachment_image_alt' === $key ? 'Alt from the media library' : '';
}

function esc_url_raw( $url ) {
	return $url;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
		random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ),
		random_int( 0, 0x0fff ), random_int( 0, 0x3fff ) | 0x8000,
		random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
	);
}
