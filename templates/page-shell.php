<?php
/**
 * Trusted page shell. AI never edits this file.
 *
 * 1. verify this really is an AIWP page
 * 2. site chrome (classic theme header/footer, block theme template parts, or none)
 * 3. rendered AI template
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiwp_plugin  = \AIWP\Designer\Plugin::instance();
$aiwp_pages   = $aiwp_plugin->pages();
$aiwp_page_id = get_queried_object_id();

if ( ! $aiwp_page_id || ! $aiwp_pages->is_aiwp_page( $aiwp_page_id ) ) {
	get_template_part( 'index' );
	return;
}

$aiwp_chrome = (string) $aiwp_pages->manifest( $aiwp_page_id )->get( 'chrome', 'theme' );
$aiwp_html   = $aiwp_plugin->renderer()->render( $aiwp_page_id );
$aiwp_block  = wp_is_block_theme();

// "site" means the AIWP header and footer, shared by every AIWP page. If none
// has been built yet, fall back to the theme rather than rendering a bare page.
$aiwp_site_chrome = 'site' === $aiwp_chrome && $aiwp_plugin->chrome()->exists();
if ( 'site' === $aiwp_chrome && ! $aiwp_site_chrome ) {
	$aiwp_chrome = 'theme';
}

/**
 * A block theme has no header.php or footer.php. Calling get_header() there is
 * deprecated, so its header and footer template parts are rendered instead.
 */
$aiwp_part = static function ( string $slug ): void {
	if ( ! function_exists( 'do_blocks' ) ) {
		return;
	}
	$markup = do_blocks( sprintf( '<!-- wp:template-part {"slug":"%s","tagName":"div"} /-->', $slug ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core block output.
	echo $markup;
};

if ( 'theme' === $aiwp_chrome && ! $aiwp_block && ! $aiwp_site_chrome ) {
	get_header();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_html;
	get_footer();
	return;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();

if ( $aiwp_site_chrome ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_plugin->chrome()->render( 'header' );
} elseif ( 'theme' === $aiwp_chrome ) {
	$aiwp_part( 'header' );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
echo $aiwp_html;

if ( $aiwp_site_chrome ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_plugin->chrome()->render( 'footer' );
} elseif ( 'theme' === $aiwp_chrome ) {
	$aiwp_part( 'footer' );
}

wp_footer();
?>
</body>
</html>
