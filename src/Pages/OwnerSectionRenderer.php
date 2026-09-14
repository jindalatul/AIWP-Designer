<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

/**
 * Renders a section a person added, when the page template does not.
 *
 * A site owner who adds fields expects to see them on the page. The template
 * was written before those fields existed, so it does not mention them, and
 * without this the owner fills in a form and nothing happens — which reads as
 * the plugin being broken.
 *
 * So anything the template does not print gets printed here, in plain ruled
 * rows built from the site's own tokens. It is deliberately modest: it is not
 * trying to be designed, it is trying to be visible and correct until the AI
 * places it properly. The AI is told these sections exist and asked to fold
 * them into the template next time it touches the page.
 */
final class OwnerSectionRenderer {

	/**
	 * @param array<int,array<string,mixed>> $sections
	 * @param array<string,mixed>            $content
	 */
	public function render( array $sections, array $content, string $template ): string {
		$out = '';

		foreach ( $sections as $section ) {
			$id = (string) $section['id'];

			// The template already prints this one. Leave it alone.
			if ( $this->template_uses( $template, $id ) ) {
				continue;
			}

			$values = (array) ( $content[ $id ] ?? array() );
			$rows   = $this->rows( (array) $section['fields'], $values );

			if ( '' === $rows ) {
				continue;
			}

			$out .= sprintf(
				'<section class="aiwp-extra" data-aiwp-extra="%s"><h2 class="aiwp-extra__h">%s</h2>%s</section>',
				esc_attr( $id ),
				esc_html( (string) $section['label'] ),
				$rows
			);
		}

		return $out;
	}

	/**
	 * Does the template print anything from this section?
	 *
	 * Matched on the dotted path rather than the bare id, so a section called
	 * "hours" is not considered handled because the word appears in a class name.
	 */
	private function template_uses( string $template, string $id ): bool {
		return 1 === preg_match( '/\{\{[^}]*:\s*' . preg_quote( $id, '/' ) . '\./', $template );
	}

	/**
	 * @param array<string,mixed> $fields
	 * @param array<string,mixed> $values
	 */
	private function rows( array $fields, array $values ): string {
		$out = '';

		foreach ( $fields as $field ) {
			$name  = (string) $field['name'];
			$value = $values[ $name ] ?? null;

			$rendered = 'aiwp_repeater' === $field['type']
				? $this->repeater( (array) ( $field['sub_fields'] ?? array() ), (array) $value )
				: $this->one( (string) $field['type'], $value );

			if ( '' === $rendered ) {
				continue;
			}

			$out .= sprintf(
				'<div class="aiwp-extra__row"><p class="aiwp-extra__k">%s</p><div class="aiwp-extra__v">%s</div></div>',
				esc_html( (string) $field['label'] ),
				$rendered
			);
		}

		return '' === $out ? '' : '<div class="aiwp-extra__rows">' . $out . '</div>';
	}

	/**
	 * @param array<string,mixed>      $sub_fields
	 * @param array<int,array<mixed>>  $rows
	 */
	private function repeater( array $sub_fields, array $rows ): string {
		$out = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$parts = array();
			foreach ( $sub_fields as $sub ) {
				$one = $this->one( (string) $sub['type'], $row[ $sub['name'] ] ?? null );
				if ( '' !== $one ) {
					$parts[] = $one;
				}
			}

			if ( array() !== $parts ) {
				$out .= '<li class="aiwp-extra__item">' . implode( ' ', $parts ) . '</li>';
			}
		}

		return '' === $out ? '' : '<ul class="aiwp-extra__list">' . $out . '</ul>';
	}

	/**
	 * One value, escaped for the kind of thing it is.
	 */
	private function one( string $type, mixed $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'image':
				$id = is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : (int) $value;
				if ( $id < 1 ) {
					return '';
				}
				$img = wp_get_attachment_image( $id, 'large', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
				return is_string( $img ) ? $img : '';

			case 'file':
				$url = wp_get_attachment_url( (int) $value );
				return $url ? sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Download', 'aiwp-designer' ) ) : '';

			case 'wysiwyg':
				return wp_kses_post( (string) $value );

			case 'url':
				$url = esc_url( (string) $value );
				return '' === $url ? '' : sprintf( '<a href="%s">%s</a>', $url, esc_html( (string) $value ) );

			case 'link':
				if ( ! is_array( $value ) || empty( $value['url'] ) ) {
					return '';
				}
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( (string) $value['url'] ),
					esc_html( (string) ( $value['title'] ?? $value['url'] ) )
				);

			case 'true_false':
				return $value ? esc_html__( 'Yes', 'aiwp-designer' ) : esc_html__( 'No', 'aiwp-designer' );

			case 'textarea':
				return nl2br( esc_html( (string) $value ) );

			default:
				if ( is_array( $value ) ) {
					return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
				}
				return esc_html( (string) $value );
		}
	}
}
