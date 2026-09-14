<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;

/**
 * Form submissions, newest first.
 */
final class Entries {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function render(): void {
		$per_page = 25;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$total  = $this->plugin->entries()->count();
		$rows   = $this->plugin->entries()->recent( $per_page, ( $paged - 1 ) * $per_page );
		$pages  = max( 1, (int) ceil( $total / $per_page ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Form Entries', 'aiwp-designer' ) . '</h1>';
		printf( '<p>%s</p>', esc_html( sprintf( /* translators: %d: number of entries */ __( '%d submission(s) stored.', 'aiwp-designer' ), $total ) ) );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing yet. Entries appear here as soon as someone sends a form.', 'aiwp-designer' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:150px">' . esc_html__( 'When', 'aiwp-designer' ) . '</th>';
		echo '<th style="width:200px">' . esc_html__( 'Page / form', 'aiwp-designer' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'aiwp-designer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$values = json_decode( (string) $row['payload'], true );
			$values = is_array( $values ) ? $values : array();

			echo '<tr><td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			printf(
				'<td><a href="%s">%s</a><br><code>%s</code></td>',
				esc_url( (string) get_permalink( (int) $row['page_id'] ) ),
				esc_html( (string) get_the_title( (int) $row['page_id'] ) ),
				esc_html( (string) $row['form_id'] )
			);

			echo '<td><dl style="margin:0;display:grid;grid-template-columns:max-content 1fr;gap:4px 16px">';
			foreach ( $values as $key => $value ) {
				printf(
					'<dt style="color:#666">%s</dt><dd style="margin:0;white-space:pre-wrap">%s</dd>',
					esc_html( (string) $key ),
					esc_html( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) )
				);
			}
			echo '</dl></td></tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages" style="margin-top:12px">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</p>';
		}

		echo '</div>';
	}
}
