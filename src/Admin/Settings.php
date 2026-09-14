<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;

/**
 * MCP connection screen and plugin settings.
 */
final class Settings {

	public const OPTION_DELETE_ON_UNINSTALL = 'aiwp_delete_data_on_uninstall';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function render_connection(): void {
		$endpoint = rest_url( AIWP_REST_NAMESPACE . '/mcp' );
		$tokens   = $this->plugin->auth_provider()->tokens();
		$new      = get_transient( 'aiwp_new_token_' . get_current_user_id() );

		echo '<div class="wrap"><h1>' . esc_html__( 'MCP Connection', 'aiwp-designer' ) . '</h1>';
		echo '<p><strong>' . esc_html__( 'Development / manual connection mode.', 'aiwp-designer' ) . '</strong> ';
		echo esc_html__( 'A bearer token identifies one WordPress user. The token grants exactly that user\'s capabilities and nothing more.', 'aiwp-designer' ) . '</p>';

		echo '<table class="widefat" style="max-width:820px"><tbody>';
		printf( '<tr><th style="width:160px">Endpoint</th><td><code>%s</code></td></tr>', esc_html( $endpoint ) );
		printf( '<tr><th>Transport</th><td>HTTP (JSON-RPC 2.0, POST)</td></tr>' );
		printf( '<tr><th>Protocol</th><td><code>%s</code></td></tr>', esc_html( AIWP_MCP_PROTOCOL_VERSION ) );
		echo '</tbody></table>';

		if ( is_string( $new ) && '' !== $new ) {
			delete_transient( 'aiwp_new_token_' . get_current_user_id() );
			echo '<div class="notice notice-success" style="max-width:820px"><p><strong>' . esc_html__( 'Copy this token now. It is not shown again.', 'aiwp-designer' ) . '</strong></p>';
			printf( '<p><input type="text" readonly value="%s" style="width:100%%;font-family:monospace" onclick="this.select()"></p>', esc_attr( $new ) );
			echo '<p><strong>Connect Claude Code:</strong></p>';
			printf(
				'<pre style="white-space:pre-wrap">claude mcp add --transport http aiwp %s --header "Authorization: Bearer %s"</pre>',
				esc_html( $endpoint ),
				esc_html( $new )
			);
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Tokens', 'aiwp-designer' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Label</th><th>User</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>';

		if ( ! $tokens ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No tokens yet.', 'aiwp-designer' ) . '</td></tr>';
		}

		foreach ( $tokens as $token ) {
			$user = get_userdata( (int) $token['user_id'] );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $token['label'] ),
				esc_html( $user ? $user->user_login : 'unknown' ),
				esc_html( (string) $token['created'] ),
				esc_html( '' !== $token['last_used'] ? (string) $token['last_used'] : 'never' ),
				wp_kses_post( $this->revoke_form( (string) $token['id'] ) )
			);
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Create a token', 'aiwp-designer' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aiwp_create_token' );
		echo '<input type="hidden" name="action" value="aiwp_create_token">';
		echo '<p><label>Label <input type="text" name="label" value="Claude" class="regular-text" required></label></p>';
		submit_button( __( 'Generate token', 'aiwp-designer' ) );
		echo '</form></div>';
	}

	private function revoke_form( string $id ): string {
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Revoke this token?\')">';
		wp_nonce_field( 'aiwp_revoke_token' );
		echo '<input type="hidden" name="action" value="aiwp_revoke_token">';
		printf( '<input type="hidden" name="token_id" value="%s">', esc_attr( $id ) );
		echo '<button class="button-link" style="color:#b32d2e">Revoke</button></form>';
		return (string) ob_get_clean();
	}

	public function render(): void {
		$delete = (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );

		echo '<div class="wrap"><h1>' . esc_html__( 'AIWP Designer Settings', 'aiwp-designer' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aiwp_save_settings' );
		echo '<input type="hidden" name="action" value="aiwp_save_settings">';

		echo '<table class="form-table"><tbody><tr><th scope="row">' . esc_html__( 'Uninstall', 'aiwp-designer' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="delete_data" value="1" %s> %s</label>',
			checked( $delete, true, false ),
			esc_html__( 'Delete all AIWP data when the plugin is uninstalled', 'aiwp-designer' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default. When off, uninstalling keeps your pages, ACF content, generated files and design system.', 'aiwp-designer' ) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button();
		echo '</form></div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( CapabilityManager::MANAGE_CONNECTIONS ) || ! check_admin_referer( 'aiwp_save_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'aiwp-designer' ) );
		}

		update_option( self::OPTION_DELETE_ON_UNINSTALL, ! empty( $_POST['delete_data'] ) );

		wp_safe_redirect( admin_url( 'admin.php?page=aiwp-settings&updated=1' ) );
		exit;
	}

	public function handle_create_token(): void {
		if ( ! current_user_can( CapabilityManager::MANAGE_CONNECTIONS ) || ! check_admin_referer( 'aiwp_create_token' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'aiwp-designer' ) );
		}

		$label  = sanitize_text_field( wp_unslash( (string) ( $_POST['label'] ?? 'MCP client' ) ) );
		$result = $this->plugin->auth_provider()->create( get_current_user_id(), $label );

		// Shown once, then gone.
		set_transient( 'aiwp_new_token_' . get_current_user_id(), $result['token'], 300 );

		wp_safe_redirect( admin_url( 'admin.php?page=aiwp-mcp' ) );
		exit;
	}

	public function handle_revoke_token(): void {
		if ( ! current_user_can( CapabilityManager::MANAGE_CONNECTIONS ) || ! check_admin_referer( 'aiwp_revoke_token' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'aiwp-designer' ) );
		}

		$this->plugin->auth_provider()->revoke( sanitize_text_field( wp_unslash( (string) ( $_POST['token_id'] ?? '' ) ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=aiwp-mcp' ) );
		exit;
	}
}
