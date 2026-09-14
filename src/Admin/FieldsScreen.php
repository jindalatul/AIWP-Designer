<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Admin;

use AIWP\Designer\Plugin;
use AIWP\Designer\REST\SectionsController;

/**
 * A screen for the shape of a page's content, separate from the content itself.
 *
 * This started as a box at the bottom of the page editor, which was wrong.
 * Editing what fields exist is an occasional job — you do it once and then fill
 * them in for months — and burying it under a long form meant scrolling past
 * everything you were not doing to reach the thing you were.
 *
 * So it has its own screen, with a page picker, exactly like Code. The editor
 * keeps a link to it next to the title rather than the panel itself.
 */
final class FieldsScreen {

	public const SLUG = 'aiwp-fields';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function url_for( int $page_id ): string {
		return add_query_arg(
			array( 'page' => self::SLUG, 'aiwp_page' => $page_id ),
			admin_url( 'admin.php' )
		);
	}

	public function enqueue(): void {
		$page_id = $this->current_page_id();
		$dir     = AIWP_PLUGIN_DIR . 'assets/dist/';

		wp_enqueue_style( 'aiwp-sections', AIWP_PLUGIN_URL . 'assets/dist/sections.css', array( 'dashicons' ), (string) @filemtime( $dir . 'sections.css' ) ); // phpcs:ignore
		wp_enqueue_script( 'aiwp-sections', AIWP_PLUGIN_URL . 'assets/dist/sections.js', array(), (string) @filemtime( $dir . 'sections.js' ), true ); // phpcs:ignore

		wp_localize_script( 'aiwp-sections', 'aiwpSections', $this->config( $page_id ) );
	}

	public function render(): void {
		$page_id = $this->current_page_id();

		echo '<div class="wrap aiwp-fields">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Fields', 'aiwp-designer' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->picker( $page_id );

		if ( 0 === $page_id ) {
			echo '<p>' . esc_html__( 'Choose a page.', 'aiwp-designer' ) . '</p></div>';
			return;
		}

		echo '<p class="description aiwp-fields__note">';
		esc_html_e(
			'What this page can hold. Add fields the design does not have and they appear on the page in the site\'s own styling; the AI is told about them so it can place them properly. Changes save as you make them.',
			'aiwp-designer'
		);
		echo '</p>';

		echo '<div id="aiwp-sections"><p class="aiwp-sec__say"></p><div class="aiwp-sec__list"></div></div>';
		echo '</div>';
	}

	private function picker( int $page_id ): void {
		echo '<form method="get" class="aiwp-fields__picker">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		printf( '<label for="aiwp-fields-page">%s</label> ', esc_html__( 'Page', 'aiwp-designer' ) );

		echo '<select name="aiwp_page" id="aiwp-fields-page" onchange="this.form.submit()">';
		printf( '<option value="0">%s</option>', esc_html__( 'Choose a page…', 'aiwp-designer' ) );

		foreach ( $this->plugin->pages()->content_page_ids() as $id ) {
			printf(
				'<option value="%d"%s>%s</option>',
				$id,
				selected( $id === $page_id, true, false ),
				esc_html( (string) get_the_title( $id ) )
			);
		}
		echo '</select>';

		if ( $page_id > 0 ) {
			printf(
				' <a class="button" href="%s">%s</a>',
				esc_url( (string) get_edit_post_link( $page_id ) ),
				esc_html__( 'Edit content', 'aiwp-designer' )
			);

			// The three things somebody moves between on one page: its words,
			// what fields it can hold, and the markup underneath.
			if ( CodeEditor::can_use() ) {
				printf(
					' <a class="button" href="%s">%s</a>',
					esc_url( CodeEditor::url_for_page( $page_id ) ),
					esc_html__( 'Template &amp; CSS', 'aiwp-designer' )
				);
			}

			printf(
				' <a class="button" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $this->plugin->page_manager()->preview_url( $page_id ) ),
				esc_html__( 'Preview', 'aiwp-designer' )
			);
		}

		echo '</form>';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function config( int $page_id ): array {
		return array(
			'root'   => esc_url_raw( rest_url( AIWP_REST_NAMESPACE ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'pageId' => $page_id,
			'types'  => SectionsController::TYPES,
			'i18n'   => array(
				'addField'           => __( 'Add field', 'aiwp-designer' ),
				'addSection'         => __( 'Add section', 'aiwp-designer' ),
				'cancel'             => __( 'Cancel', 'aiwp-designer' ),
				'copied'             => __( 'Copied', 'aiwp-designer' ),
				'copyByHand'         => __( 'Copy it', 'aiwp-designer' ),
				'copyByHandLong'     => __( 'The browser would not let us copy. Select the line and press Ctrl+C, or Cmd+C on a Mac.', 'aiwp-designer' ),
				'copyRef'            => __( 'Copy this for the template', 'aiwp-designer' ),
				'fieldName'          => __( 'Field name, for example Saturday', 'aiwp-designer' ),
				'firstField'         => __( 'Its first field, for example Monday to Friday', 'aiwp-designer' ),
				'fromDesign'         => __( 'part of the design', 'aiwp-designer' ),
				'needBoth'           => __( 'Give the section a name and one field to start it off.', 'aiwp-designer' ),
				'needName'           => __( 'Give the field a name.', 'aiwp-designer' ),
				'newSection'         => __( 'Add a new section', 'aiwp-designer' ),
				'removeField'        => __( 'Remove', 'aiwp-designer' ),
				'removeFieldBody'    => __( 'The field goes, and so does anything typed into it. This cannot be undone from here.', 'aiwp-designer' ),
				'removeFieldTitle'   => __( 'Remove the field "%s"?', 'aiwp-designer' ),
				'removeOwnBody'      => __( 'The section goes, with every field in it and everything typed into them. This cannot be undone from here.', 'aiwp-designer' ),
				'removeDesignBody'   => __( 'This section is part of the design the AI built. Removing it deletes its content, and the page will have holes where it used to be until the design is rebuilt.', 'aiwp-designer' ),
				'removeSection'      => __( 'Remove section', 'aiwp-designer' ),
				'removeSectionTitle' => __( 'Remove the section "%s"?', 'aiwp-designer' ),
				'sectionName'        => __( 'Section name, for example Opening hours', 'aiwp-designer' ),
				'typeToConfirm'      => __( 'Type %s to confirm', 'aiwp-designer' ),
				'usedByDesign'       => __( 'The page prints these right now: %s', 'aiwp-designer' ),
			),
		);
	}

	private function current_page_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['aiwp_page'] ) ? absint( $_GET['aiwp_page'] ) : 0;

		return $id > 0 && $this->plugin->pages()->is_aiwp_page( $id ) ? $id : 0;
	}
}
