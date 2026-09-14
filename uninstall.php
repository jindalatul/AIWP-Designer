<?php
/**
 * Uninstall. By default nothing the user made is deleted.
 *
 * Two different jobs live here. Caches, capabilities and the plugin's own
 * bookkeeping always go, because they are ours and mean nothing without the
 * plugin. Pages, content and files only go when the site owner has ticked the
 * box that says so.
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Deletes every option and transient whose name starts with a prefix.
 *
 * The caches are keyed by page id and by a hash of the template, so there is
 * no list of them to walk. The prefix is the only handle we have.
 */
$aiwp_forget_prefix = static function ( string $prefix ) use ( $wpdb ): void {
	$like = $wpdb->esc_like( $prefix ) . '%';

	$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);

	foreach ( (array) $names as $name ) {
		delete_option( (string) $name );
	}
};

// Our caches. They are rebuilt from the schema, so they are never worth keeping.
foreach ( array( '_transient_aiwp_', '_transient_timeout_aiwp_', 'aiwp_ast_', 'aiwp_chrome_ast_', 'aiwp_article_ast_', 'aiwp_archive_ast_' ) as $aiwp_prefix ) {
	$aiwp_forget_prefix( $aiwp_prefix );
}

// The log is ours and describes a plugin that is going away.
delete_option( 'aiwp_audit_log' );

/**
 * The capabilities we added to roles. Left behind, they sit in every role row
 * forever and show up in any plugin that lists capabilities.
 */
foreach ( array( 'administrator', 'editor' ) as $aiwp_role_name ) {
	$aiwp_role = get_role( $aiwp_role_name );

	if ( ! $aiwp_role instanceof WP_Role ) {
		continue;
	}

	foreach ( array( 'aiwp_edit_pages', 'aiwp_publish_pages', 'aiwp_manage_design', 'aiwp_manage_connections' ) as $aiwp_cap ) {
		$aiwp_role->remove_cap( $aiwp_cap );
	}
}

// Each person's own "how many rows per page" choice.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'aiwp_pages_per_page' ) ); // phpcs:ignore WordPress.DB

$aiwp_delete_everything = (bool) get_option( 'aiwp_delete_data_on_uninstall', false );

if ( ! $aiwp_delete_everything ) {
	// Keep the rest, but stop claiming the tables are up to date: a later
	// reinstall has to run its upgrade steps again.
	delete_option( 'aiwp_db_version' );
	return;
}

// Pages, ACF values, generated files and the design system.
$aiwp_page_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_aiwp_enabled', '1' )
);

foreach ( (array) $aiwp_page_ids as $aiwp_page_id ) {
	wp_delete_post( (int) $aiwp_page_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiwp_versions" ); // phpcs:ignore WordPress.DB

/**
 * Every option the plugin owns.
 *
 * Anything missing from this list survives the uninstall and quietly changes
 * how a later install behaves, so it is written out in full rather than
 * matched on a prefix.
 */
$aiwp_options = array(
	'aiwp_archive_version',
	'aiwp_article_kinds',
	'aiwp_brand_inputs',
	'aiwp_chrome_page_id',
	'aiwp_chrome_version',
	'aiwp_db_version',
	'aiwp_delete_data_on_uninstall',
	'aiwp_design_updated',
	'aiwp_design_version',
	'aiwp_form_secret',
	'aiwp_mcp_tokens',
	'aiwp_menu_locations',
	'aiwp_preview_secret',
);

foreach ( $aiwp_options as $aiwp_option ) {
	delete_option( $aiwp_option );
}

// A safety net for anything named aiwp_* that this list has not caught up with.
$aiwp_forget_prefix( 'aiwp_' );

$aiwp_uploads = wp_upload_dir();
$aiwp_root    = trailingslashit( $aiwp_uploads['basedir'] ) . 'aiwp-designer';

if ( is_dir( $aiwp_root ) ) {
	$aiwp_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $aiwp_root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $aiwp_iterator as $aiwp_file ) {
		$aiwp_file->isDir() ? @rmdir( $aiwp_file->getPathname() ) : @unlink( $aiwp_file->getPathname() ); // phpcs:ignore
	}
	@rmdir( $aiwp_root ); // phpcs:ignore
}
