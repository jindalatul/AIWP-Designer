<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\Security\CapabilityManager;
use AIWP\Designer\Transfer\SiteExporter;
use AIWP\Designer\Transfer\SiteImporter;

/**
 * Moving a site from one WordPress to another.
 *
 * People build on a local install or on staging and then need the thing on the
 * real site. WordPress's own export carries the post and nothing else, so a
 * site moved that way arrives with its words and no design at all. This packs
 * the whole thing into one file and puts it back together on the other side.
 */
final class TransferScreen {

	public const SLUG = 'aiwp-transfer';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks(): void {
		add_action( 'admin_post_aiwp_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_aiwp_import', array( $this, 'handle_import' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public function render(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_DESIGN ) ) {
			wp_die( esc_html__( 'You cannot move this site.', 'aiwp-designer' ) );
		}

		$notice = get_transient( 'aiwp_transfer_note_' . get_current_user_id() );
		delete_transient( 'aiwp_transfer_note_' . get_current_user_id() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Move this site', 'aiwp-designer' ) . '</h1>';

		if ( is_array( $notice ) ) {
			$this->render_notice( $notice );
		}

		$this->render_export();
		$this->render_import();

		echo '</div>';
	}

	private function render_export(): void {
		$pages = count( $this->plugin->pages()->all_page_ids() );

		echo '<div class="card" style="max-width:820px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Take a copy', 'aiwp-designer' ) . '</h2>';
		echo '<p>' . esc_html__( 'One file holding every page, the words in them, the design system, the header and footer, the menus and the images they use.', 'aiwp-designer' ) . '</p>';

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of pages */
					_n( '%d page will be included.', '%d pages will be included.', $pages, 'aiwp-designer' ),
					$pages
				)
			)
		);

		echo '<p class="description">' . esc_html__( 'MCP tokens and the signing secrets stay behind. A file like this travels by email, and a token in it would be a key to this site.', 'aiwp-designer' ) . '</p>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'aiwp_export' );
		echo '<input type="hidden" name="action" value="aiwp_export">';
		printf( '<p><button type="submit" class="button button-primary button-hero">%s</button></p>', esc_html__( 'Download the site file', 'aiwp-designer' ) );
		echo '</form></div>';
	}

	private function render_import(): void {
		echo '<div class="card" style="max-width:820px;margin-top:20px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Bring a copy in', 'aiwp-designer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose a file taken from another site. Pages that came from that file before are updated in place; anything else on this site is left alone.', 'aiwp-designer' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'This changes the design system, the header and footer and the menus on this site. Take a copy of this site first if you want a way back.', 'aiwp-designer' ) . '</p>';

		printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'aiwp_import' );
		echo '<input type="hidden" name="action" value="aiwp_import">';
		echo '<p><input type="file" name="aiwp_file" accept=".zip" required></p>';
		printf(
			'<p><label><input type="checkbox" name="confirm" value="1" required> %s</label></p>',
			esc_html__( 'I understand this replaces the design of this site.', 'aiwp-designer' )
		);
		printf( '<p><button type="submit" class="button button-primary">%s</button></p>', esc_html__( 'Import', 'aiwp-designer' ) );
		echo '</form></div>';
	}

	/** @param array<string,mixed> $notice */
	private function render_notice( array $notice ): void {
		$bad = ! empty( $notice['bad'] );

		printf( '<div class="notice notice-%s"><p><strong>%s</strong></p>', $bad ? 'error' : 'success', esc_html( (string) $notice['title'] ) );

		if ( ! empty( $notice['lines'] ) ) {
			echo '<ul style="list-style:disc;margin-left:20px">';
			foreach ( (array) $notice['lines'] as $line ) {
				printf( '<li>%s</li>', esc_html( (string) $line ) );
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	// -- handlers ------------------------------------------------------------

	public function handle_export(): void {
		check_admin_referer( 'aiwp_export' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_DESIGN ) ) {
			wp_die( esc_html__( 'You cannot take a copy of this site.', 'aiwp-designer' ) );
		}

		$name = sanitize_file_name( sprintf( 'aiwp-%s-%s.zip', wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site', gmdate( 'Y-m-d' ) ) );
		$path = trailingslashit( get_temp_dir() ) . uniqid( 'aiwp-export-', false ) . '.zip';

		$exporter = new SiteExporter( $this->plugin );

		if ( null === $exporter->write( $path ) ) {
			$this->note( true, __( 'The file could not be written.', 'aiwp-designer' ), $exporter->problems() );
			$this->go_back();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $path );
		exit;
	}

	public function handle_import(): void {
		check_admin_referer( 'aiwp_import' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_DESIGN ) ) {
			wp_die( esc_html__( 'You cannot import into this site.', 'aiwp-designer' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$upload = isset( $_FILES['aiwp_file'] ) ? $_FILES['aiwp_file'] : null;

		if ( ! is_array( $upload ) || ! isset( $upload['tmp_name'] ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
			$this->note( true, __( 'No file arrived. It may be larger than this server accepts.', 'aiwp-designer' ), array() );
			$this->go_back();
		}

		$path     = (string) $upload['tmp_name'];
		$importer = new SiteImporter( $this->plugin );

		if ( null === $importer->inspect( $path ) ) {
			$this->note( true, __( 'That file cannot be used.', 'aiwp-designer' ), $importer->problems() );
			$this->go_back();
		}

		if ( ! $importer->run( $path ) ) {
			$this->note( true, __( 'The import stopped.', 'aiwp-designer' ), $importer->problems() );
			$this->go_back();
		}

		$this->note(
			array() !== $importer->problems(),
			__( 'The site was imported.', 'aiwp-designer' ),
			array_merge( $importer->done(), $importer->problems() )
		);

		$this->go_back();
	}

	/** @param string[] $lines */
	private function note( bool $bad, string $title, array $lines ): void {
		set_transient(
			'aiwp_transfer_note_' . get_current_user_id(),
			array( 'bad' => $bad, 'title' => $title, 'lines' => $lines ),
			60
		);
	}

	private function go_back(): void {
		wp_safe_redirect( self::url() );
		exit;
	}
}
