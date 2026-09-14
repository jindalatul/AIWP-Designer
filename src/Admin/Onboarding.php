<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;

/**
 * Brand and business answers. The AI reads these through site.get_context when
 * the site has no existing design to learn from.
 */
final class Onboarding {

	public const OPTION = 'aiwp_brand_inputs';

	public const FIELDS = array(
		'business_name'      => 'Business name',
		'business_summary'   => 'What the business does',
		'audience'           => 'Who the customers are',
		'products'           => 'Products or services',
		'preferred_style'    => 'Preferred style (a few words)',
		'brand_colors'       => 'Brand colours (hex, comma separated)',
		'brand_guidelines'   => 'Anything else about the brand',
		'reference_websites' => 'Reference websites (one per line)',
	);

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function render(): void {
		$values = (array) get_option( self::OPTION, array() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Brand & Business', 'aiwp-designer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your AI reads these answers before it designs anything. Short and specific beats long and vague.', 'aiwp-designer' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aiwp_save_brand' );
		echo '<input type="hidden" name="action" value="aiwp_save_brand">';
		echo '<table class="form-table"><tbody>';

		foreach ( self::FIELDS as $key => $label ) {
			$value = (string) ( $values[ $key ] ?? '' );
			$long  = in_array( $key, array( 'business_summary', 'products', 'brand_guidelines', 'reference_websites' ), true );

			printf( '<tr><th scope="row"><label for="aiwp-%s">%s</label></th><td>', esc_attr( $key ), esc_html( $label ) );
			if ( $long ) {
				printf( '<textarea id="aiwp-%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $key ), esc_attr( $key ), esc_textarea( $value ) );
			} else {
				printf( '<input id="aiwp-%s" type="text" name="%s" value="%s" class="regular-text">', esc_attr( $key ), esc_attr( $key ), esc_attr( $value ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( CapabilityManager::EDIT_PAGES ) || ! check_admin_referer( 'aiwp_save_brand' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'aiwp-designer' ) );
		}

		$values = array();
		foreach ( array_keys( self::FIELDS ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw            = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
			$values[ $key ] = sanitize_textarea_field( $raw );
		}

		update_option( self::OPTION, $values, false );

		wp_safe_redirect( admin_url( 'admin.php?page=aiwp-brand&updated=1' ) );
		exit;
	}
}
