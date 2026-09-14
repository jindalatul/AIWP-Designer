<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Forms;

use AIWP\Designer\Pages\PageRepository;
use AIWP\Designer\Security\AuditLogger;

/**
 * Receives a submission, checks it, stores it and notifies.
 *
 * Nothing a visitor sends is trusted: the page and form come from the signed
 * token, not from the request body.
 */
final class FormHandler {

	private const STATE_TTL = 300;

	/**
	 * Submissions allowed from one address per hour.
	 *
	 * Offices, schools and mobile networks put many people behind one address, so
	 * this has to be generous enough not to block real people. Filterable for
	 * sites that need it tighter or looser.
	 */
	private const MAX_PER_IP = 30;

	private PageRepository $pages;
	private EntryRepository $entries;

	public function __construct( PageRepository $pages, EntryRepository $entries ) {
		$this->pages   = $pages;
		$this->entries = $entries;
	}

	public function register(): void {
		add_action( 'admin_post_nopriv_aiwp_form_submit', array( $this, 'handle' ) );
		add_action( 'admin_post_aiwp_form_submit', array( $this, 'handle' ) );
	}

	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a signed token is used instead; the form is public.
		$token    = isset( $_POST['aiwp_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['aiwp_token'] ) ) : '';
		$verified = FormToken::verify( $token );

		if ( ! $verified['valid'] ) {
			$this->bounce_home( 'invalid' );
		}

		$page_id = $verified['page_id'];
		$form_id = $verified['form_id'];

		$schema = $this->forms_for( $page_id );
		$form   = $schema->get( $form_id );

		if ( null === $form ) {
			$this->bounce_home( 'unknown_form' );
		}

		$return = (string) get_permalink( $page_id );

		// A filled honeypot is a bot and nothing else. Accept and discard it, so it
		// learns nothing from the response.
		$trap = isset( $_POST['aiwp_website'] ) ? trim( (string) wp_unslash( $_POST['aiwp_website'] ) ) : '';
		if ( '' !== $trap ) {
			AuditLogger::log( 'form_spam_blocked', array( 'page_id' => $page_id, 'form_id' => $form_id ) );
			$this->redirect( $return, $form_id, array( 'sent' => true ) );
		}

		// Submitted improbably fast. That is usually a bot, but a returning visitor
		// with autofill can do it too, so the message is kept and flagged rather
		// than thrown away.
		$suspect = $verified['age'] < FormToken::MIN_SECONDS;

		$ip_hash = $this->ip_hash();

		/**
		 * How many submissions one address may send per hour.
		 *
		 * @since 0.6.1
		 * @param int $limit
		 */
		$limit = (int) apply_filters( 'aiwp/form_rate_limit', self::MAX_PER_IP );

		if ( $limit > 0 && $this->entries->recent_count_for_ip( $ip_hash ) >= $limit ) {
			$this->redirect(
				$return,
				$form_id,
				array(
					'errors' => array( '_form' => __( 'Too many messages from this connection. Please try again later.', 'aiwp-designer' ) ),
					'values' => array(),
				)
			);
		}

		$submitted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->validate( $form, (array) $submitted );

		if ( array() !== $result['errors'] ) {
			$this->redirect(
				$return,
				$form_id,
				array(
					'errors' => $result['errors'],
					'values' => $result['values'],
				)
			);
		}

		$entry_id = $this->entries->store( $page_id, $form_id, $result['values'], $ip_hash, $suspect ? 'suspect' : 'new' );

		if ( 0 === $entry_id ) {
			$this->redirect(
				$return,
				$form_id,
				array(
					'errors' => array( '_form' => __( 'Sorry, something went wrong saving your message. Please try again.', 'aiwp-designer' ) ),
					'values' => $result['values'],
				)
			);
		}

		$this->notify( $form, $result['values'], $page_id );

		AuditLogger::log(
			'form_submitted',
			array(
				'page_id'  => $page_id,
				'form_id'  => $form_id,
				'entry_id' => $entry_id,
				'suspect'  => $suspect ? 1 : 0,
			)
		);

		$this->redirect( $return, $form_id, array( 'sent' => true ) );
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $submitted
	 * @return array{values:array<string,mixed>,errors:array<string,string>}
	 */
	public function validate( array $form, array $submitted ): array {
		$values = array();
		$errors = array();

		foreach ( $form['fields'] as $field ) {
			$name = (string) $field['name'];
			$raw  = $submitted[ $name ] ?? '';

			if ( is_array( $raw ) ) {
				$raw = '';
			}

			$raw   = substr( (string) $raw, 0, FormSchema::MAX_VALUE_BYTES );
			$value = $this->sanitize( $raw, (string) $field['type'] );

			// Sanitising can empty a value that was simply wrong. Judge the format on
			// what was typed, so a bad address is reported as bad rather than missing.
			$typed = '' !== trim( $raw );

			switch ( $field['type'] ) {
				case 'email':
					if ( $typed && ! is_email( $value ) ) {
						$errors[ $name ] = __( 'Please enter a valid email address.', 'aiwp-designer' );
						$value           = trim( $raw );
					}
					break;

				case 'url':
					if ( $typed && ! wp_http_validate_url( $value ) ) {
						$errors[ $name ] = __( 'Please enter a valid web address.', 'aiwp-designer' );
						$value           = trim( $raw );
					}
					break;

				case 'number':
					if ( '' !== $raw && ! is_numeric( $raw ) ) {
						$errors[ $name ] = __( 'Please enter a number.', 'aiwp-designer' );
					}
					break;

				case 'select':
				case 'radio':
					if ( '' !== $value && ! array_key_exists( $value, (array) $field['choices'] ) ) {
						$errors[ $name ] = __( 'Please choose one of the options.', 'aiwp-designer' );
						$value           = '';
					}
					break;
			}

			if ( ! empty( $field['required'] ) && ! isset( $errors[ $name ] ) && ( '' === $value || '0' === $value && in_array( $field['type'], array( 'checkbox', 'consent' ), true ) ) ) {
				$errors[ $name ] = 'consent' === $field['type']
					? __( 'Please tick this box to continue.', 'aiwp-designer' )
					: __( 'This field is required.', 'aiwp-designer' );
			}

			$values[ $name ] = $value;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	private function sanitize( string $raw, string $type ): string {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $raw );
			case 'url':
				return esc_url_raw( $raw );
			case 'tel':
				return trim( (string) preg_replace( '/[^0-9+()\s.-]/', '', $raw ) );
			case 'number':
				return is_numeric( $raw ) ? (string) ( $raw + 0 ) : '';
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'checkbox':
			case 'consent':
				return '' !== $raw ? '1' : '';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $values
	 */
	private function notify( array $form, array $values, int $page_id ): void {
		$to = '' !== (string) $form['notify_email'] ? (string) $form['notify_email'] : (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: form title or id, 2: site name */
			__( 'New %1$s from %2$s', 'aiwp-designer' ),
			'' !== (string) $form['title'] ? (string) $form['title'] : (string) $form['id'],
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines = array();
		foreach ( $form['fields'] as $field ) {
			$value = (string) ( $values[ $field['name'] ] ?? '' );
			if ( in_array( $field['type'], array( 'checkbox', 'consent' ), true ) ) {
				$value = '1' === $value ? 'Yes' : 'No';
			} elseif ( in_array( $field['type'], array( 'select', 'radio' ), true ) && isset( $field['choices'][ $value ] ) ) {
				$value = (string) $field['choices'][ $value ];
			}
			$lines[] = $field['label'] . ': ' . ( '' !== $value ? $value : '—' );
		}

		$lines[] = '';
		$lines[] = __( 'Sent from', 'aiwp-designer' ) . ': ' . get_permalink( $page_id );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Reply-To only from a value that really is an email address.
		foreach ( $form['fields'] as $field ) {
			if ( 'email' === $field['type'] && is_email( (string) ( $values[ $field['name'] ] ?? '' ) ) ) {
				$headers[] = 'Reply-To: ' . sanitize_email( (string) $values[ $field['name'] ] );
				break;
			}
		}

		wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/**
	 * State is handed to the next request through a short-lived transient, so
	 * values and errors survive the redirect without a session or a query string.
	 *
	 * @param array<string,mixed> $state
	 */
	private function redirect( string $url, string $form_id, array $state ): void {
		$key = wp_generate_password( 20, false, false );
		$state['form_id'] = $form_id;
		set_transient( 'aiwp_form_state_' . $key, $state, self::STATE_TTL );

		$target = add_query_arg( 'aiwp_form', $key, $url ) . '#aiwp-form-' . $form_id;

		wp_safe_redirect( $target, 303 );
		exit;
	}

	private function bounce_home( string $reason ): void {
		AuditLogger::log( 'form_rejected', array( 'reason' => $reason ) );
		wp_safe_redirect( home_url( '/' ), 303 );
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['aiwp_form'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['aiwp_form'] ) ) : '';
		if ( '' === $key ) {
			return array();
		}

		$state = get_transient( 'aiwp_form_state_' . $key );

		return is_array( $state ) ? $state : array();
	}

	private function forms_for( int $page_id ): FormSchema {
		$manifest = $this->pages->manifest( $page_id );
		return FormSchema::from_array( (array) $manifest->get( 'forms', array() ) );
	}

	private function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) );
	}
}
