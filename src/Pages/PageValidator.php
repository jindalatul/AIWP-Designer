<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\ACF\ACFManager;
use AIWP\Designer\Design\CSSValidator;
use AIWP\Designer\Forms\FormSchema;
use AIWP\Designer\Template\TemplateValidator;

/**
 * One place that decides whether a page package may be stored.
 */
final class PageValidator {

	/**
	 * Which forms the template actually places.
	 *
	 * @return string[]
	 */
	private function referenced_forms( string $template ): array {
		if ( ! preg_match_all( '/data-aiwp-form="([a-zA-Z0-9_-]+)"/i', $template, $matches ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
	}

	/**
	 * @param array<string,mixed> $package schema, template, css, behaviors, forms
	 * @return array{valid:bool,errors:string[],warnings:string[],template_ast:mixed,css:string,forms:FormSchema}
	 */
	public function validate( array $package, string $page_uuid ): array {
		$errors   = array();
		$warnings = array();

		$schema = $package['schema'] instanceof PageSchema
			? $package['schema']
			: PageSchema::from_array( (array) ( $package['schema'] ?? array() ) );

		foreach ( $schema->errors() as $error ) {
			$errors[] = $error;
		}

		// Every field type must actually exist on this install.
		foreach ( $schema->fields() as $path => $field ) {
			if ( ! ACFManager::field_type_exists( (string) $field['type'] ) ) {
				$errors[] = sprintf(
					'AIWP_FIELD_TYPE_UNAVAILABLE: field "%s" uses type "%s", which is not available on this site.',
					$path,
					$field['type']
				);
			}
		}

		$template_validator = new TemplateValidator();
		$template_result    = $template_validator->validate( (string) ( $package['template'] ?? '' ), $schema );

		foreach ( $template_result['errors'] as $error ) {
			$errors[] = 'AIWP_TEMPLATE_INVALID: ' . $error;
		}
		foreach ( $template_result['warnings'] as $warning ) {
			$warnings[] = $warning;
		}

		$css_validator = new CSSValidator();
		$css_result    = $css_validator->validate_and_scope( (string) ( $package['css'] ?? '' ), $page_uuid );

		foreach ( $css_result['errors'] as $error ) {
			$errors[] = 'AIWP_CSS_INVALID: ' . $error;
		}
		foreach ( $css_result['warnings'] as $warning ) {
			$warnings[] = $warning;
		}

		// Forms are declarative too: the AI says what to ask, the plugin owns the
		// markup. A placeholder pointing at a form that was never declared is an error.
		$forms = $package['forms'] instanceof FormSchema
			? $package['forms']
			: FormSchema::from_array( (array) ( $package['forms'] ?? array() ) );

		foreach ( $forms->errors() as $error ) {
			$errors[] = 'AIWP_FORM_INVALID: ' . $error;
		}

		foreach ( $this->referenced_forms( (string) ( $package['template'] ?? '' ) ) as $referenced ) {
			if ( ! $forms->has( $referenced ) ) {
				$errors[] = sprintf( 'AIWP_FORM_INVALID: the template places a form called "%s", but no such form is declared.', $referenced );
			}
		}

		foreach ( $forms->ids() as $declared ) {
			if ( ! in_array( $declared, $this->referenced_forms( (string) ( $package['template'] ?? '' ) ), true ) ) {
				$warnings[] = sprintf( 'Form "%s" is declared but never placed. Add <div data-aiwp-form="%1$s"></div> where it should appear.', $declared );
			}
		}

		// Behaviours are declarative; unknown names are a hard stop.
		$known = \AIWP\Designer\Rendering\AssetManager::BEHAVIORS;
		foreach ( (array) ( $package['behaviors'] ?? array() ) as $behavior ) {
			if ( ! in_array( $behavior, $known, true ) ) {
				$errors[] = sprintf( 'Unknown behavior "%s". Available: %s.', (string) $behavior, implode( ', ', $known ) );
			}
		}

		return array(
			'forms'        => $forms,
			'valid'        => array() === $errors,
			'errors'       => array_values( array_unique( $errors ) ),
			'warnings'     => array_values( array_unique( $warnings ) ),
			'template_ast' => $template_result['ast'],
			'css'          => $css_result['css'],
		);
	}
}
