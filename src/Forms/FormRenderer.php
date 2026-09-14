<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Forms;

/**
 * Renders the real form markup. This is plugin-owned, trusted HTML: the action,
 * the method, the token and the field names are never AI-supplied.
 *
 * The markup carries predictable class names so page CSS can lay it out.
 */
final class FormRenderer {

	/**
	 * Replace every <div data-aiwp-form="id"></div> placeholder with a real form.
	 *
	 * @param array<string,mixed> $state errors and old values from a failed submission
	 */
	public function inject( string $html, FormSchema $forms, int $page_id, array $state = array() ): string {
		if ( $forms->is_empty() || false === strpos( $html, 'data-aiwp-form' ) ) {
			return $html;
		}

		$pattern = '/(<(div|section|aside)\b[^>]*\bdata-aiwp-form="([a-zA-Z0-9_-]+)"[^>]*>)\s*(<\/\2>)/i';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $match ) use ( $forms, $page_id, $state ): string {
				$form = $forms->get( $match[3] );
				if ( null === $form ) {
					return $match[0];
				}
				return $match[1] . $this->render( $form, $page_id, $state ) . $match[4];
			},
			$html
		);
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $state
	 */
	public function render( array $form, int $page_id, array $state = array() ): string {
		$form_id  = (string) $form['id'];
		$dom_id   = 'aiwp-form-' . $form_id;
		$is_this  = ( $state['form_id'] ?? '' ) === $form_id;
		$errors   = $is_this ? (array) ( $state['errors'] ?? array() ) : array();
		$values   = $is_this ? (array) ( $state['values'] ?? array() ) : array();
		$sent     = $is_this && ! empty( $state['sent'] );

		if ( $sent ) {
			return sprintf(
				'<div class="aiwp-form aiwp-form--sent" id="%s" role="status"><p class="aiwp-form__success">%s</p></div>',
				esc_attr( $dom_id ),
				esc_html( (string) $form['success_message'] )
			);
		}

		$out = sprintf(
			'<form class="aiwp-form" id="%s" method="post" action="%s" novalidate>',
			esc_attr( $dom_id ),
			esc_url( admin_url( 'admin-post.php' ) )
		);

		$out .= '<input type="hidden" name="action" value="aiwp_form_submit">';
		$out .= sprintf( '<input type="hidden" name="aiwp_token" value="%s">', esc_attr( FormToken::create( $page_id, $form_id ) ) );
		$out .= sprintf( '<input type="hidden" name="aiwp_return" value="%s">', esc_attr( (string) get_permalink( $page_id ) ) );

		// Honeypot. Hidden from people, tempting to a bot.
		$out .= '<div class="aiwp-form__trap" aria-hidden="true">'
			. '<label>Leave this field empty<input type="text" name="aiwp_website" tabindex="-1" autocomplete="off" value=""></label>'
			. '</div>';

		if ( array() !== $errors ) {
			$out .= '<div class="aiwp-form__errors" role="alert"><p>' . esc_html__( 'Please check the highlighted fields.', 'aiwp-designer' ) . '</p></div>';
		}

		if ( '' !== (string) $form['title'] ) {
			$out .= sprintf( '<p class="aiwp-form__title">%s</p>', esc_html( (string) $form['title'] ) );
		}

		$out .= '<div class="aiwp-form__fields">';
		foreach ( $form['fields'] as $field ) {
			$out .= $this->render_field( $field, $dom_id, $errors, $values );
		}
		$out .= '</div>';

		if ( '' !== (string) $form['consent_note'] ) {
			$out .= sprintf( '<p class="aiwp-form__note">%s</p>', esc_html( (string) $form['consent_note'] ) );
		}

		$out .= sprintf(
			'<button type="submit" class="aiwp-form__submit">%s</button>',
			esc_html( (string) $form['submit_label'] )
		);

		$out .= '</form>';

		return $out;
	}

	/**
	 * @param array<string,mixed>  $field
	 * @param array<string,string> $errors
	 * @param array<string,mixed>  $values
	 */
	private function render_field( array $field, string $dom_id, array $errors, array $values ): string {
		$name    = (string) $field['name'];
		$id      = $dom_id . '-' . $name;
		$value   = $values[ $name ] ?? '';
		$error   = $errors[ $name ] ?? '';
		$classes = 'aiwp-form__field aiwp-form__field--' . $field['type'] . ' aiwp-form__field--' . $field['width'];
		if ( '' !== $error ) {
			$classes .= ' is-invalid';
		}

		$described = array();
		if ( '' !== (string) $field['help'] ) {
			$described[] = $id . '-help';
		}
		if ( '' !== $error ) {
			$described[] = $id . '-error';
		}

		$describedby = $described ? sprintf( ' aria-describedby="%s"', esc_attr( implode( ' ', $described ) ) ) : '';
		$required    = $field['required'] ? ' required aria-required="true"' : '';
		$invalid     = '' !== $error ? ' aria-invalid="true"' : '';
		$placeholder = '' !== (string) $field['placeholder'] ? sprintf( ' placeholder="%s"', esc_attr( (string) $field['placeholder'] ) ) : '';

		$out = sprintf( '<div class="%s">', esc_attr( $classes ) );

		if ( 'consent' !== $field['type'] && 'checkbox' !== $field['type'] ) {
			$out .= sprintf(
				'<label class="aiwp-form__label" for="%s">%s%s</label>',
				esc_attr( $id ),
				esc_html( (string) $field['label'] ),
				$field['required'] ? '<span class="aiwp-form__required" aria-hidden="true">*</span>' : ''
			);
		}

		switch ( $field['type'] ) {
			case 'textarea':
				$out .= sprintf(
					'<textarea class="aiwp-form__input" id="%s" name="fields[%s]" rows="%d"%s%s%s%s>%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $field['rows'],
					$placeholder,
					$required,
					$invalid,
					$describedby,
					esc_textarea( (string) $value )
				);
				break;

			case 'select':
				$out .= sprintf(
					'<select class="aiwp-form__input" id="%s" name="fields[%s]"%s%s%s>',
					esc_attr( $id ),
					esc_attr( $name ),
					$required,
					$invalid,
					$describedby
				);
				$out .= sprintf( '<option value="">%s</option>', esc_html__( 'Please choose', 'aiwp-designer' ) );
				foreach ( (array) $field['choices'] as $key => $label ) {
					$out .= sprintf(
						'<option value="%s"%s>%s</option>',
						esc_attr( (string) $key ),
						selected( (string) $value, (string) $key, false ),
						esc_html( (string) $label )
					);
				}
				$out .= '</select>';
				break;

			case 'radio':
				$out .= '<div class="aiwp-form__choices" role="radiogroup">';
				foreach ( (array) $field['choices'] as $key => $label ) {
					$choice_id = $id . '-' . $key;
					$out      .= sprintf(
						'<label class="aiwp-form__choice" for="%s"><input type="radio" id="%s" name="fields[%s]" value="%s"%s>%s</label>',
						esc_attr( $choice_id ),
						esc_attr( $choice_id ),
						esc_attr( $name ),
						esc_attr( (string) $key ),
						checked( (string) $value, (string) $key, false ),
						esc_html( (string) $label )
					);
				}
				$out .= '</div>';
				break;

			case 'checkbox':
			case 'consent':
				$out .= sprintf(
					'<label class="aiwp-form__choice aiwp-form__choice--single" for="%s"><input type="checkbox" id="%s" name="fields[%s]" value="1"%s%s%s%s>%s%s</label>',
					esc_attr( $id ),
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (string) $value, '1', false ),
					$required,
					$invalid,
					$describedby,
					esc_html( (string) $field['label'] ),
					$field['required'] ? '<span class="aiwp-form__required" aria-hidden="true">*</span>' : ''
				);
				break;

			default:
				$out .= sprintf(
					'<input class="aiwp-form__input" type="%s" id="%s" name="fields[%s]" value="%s"%s%s%s%s%s>',
					esc_attr( $this->input_type( (string) $field['type'] ) ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$placeholder,
					$required,
					$invalid,
					$describedby,
					$this->autocomplete( (string) $field['type'], $name )
				);
				break;
		}

		if ( '' !== (string) $field['help'] ) {
			$out .= sprintf(
				'<p class="aiwp-form__help" id="%s-help">%s</p>',
				esc_attr( $id ),
				esc_html( (string) $field['help'] )
			);
		}

		if ( '' !== $error ) {
			$out .= sprintf(
				'<p class="aiwp-form__error" id="%s-error">%s</p>',
				esc_attr( $id ),
				esc_html( $error )
			);
		}

		return $out . '</div>';
	}

	private function input_type( string $type ): string {
		$map = array(
			'email'  => 'email',
			'tel'    => 'tel',
			'url'    => 'url',
			'number' => 'number',
		);
		return $map[ $type ] ?? 'text';
	}

	private function autocomplete( string $type, string $name ): string {
		$token = '';

		if ( 'email' === $type ) {
			$token = 'email';
		} elseif ( 'tel' === $type ) {
			$token = 'tel';
		} elseif ( false !== strpos( $name, 'name' ) ) {
			$token = 'name';
		} elseif ( false !== strpos( $name, 'company' ) || false !== strpos( $name, 'organisation' ) ) {
			$token = 'organization';
		}

		return '' === $token ? '' : sprintf( ' autocomplete="%s"', esc_attr( $token ) );
	}
}
