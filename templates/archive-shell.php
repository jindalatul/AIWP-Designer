<?php
/**
 * Trusted archive shell. AI never edits this file.
 *
 * One design for every list of articles: the blog index, a category, a tag, an
 * author, a search result.
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiwp_plugin = \AIWP\Designer\Plugin::instance();

if ( ! $aiwp_plugin->articles()->archive_applies() ) {
	get_template_part( 'index' );
	return;
}

$aiwp_html   = $aiwp_plugin->articles()->render_archive();
$aiwp_block  = wp_is_block_theme();
$aiwp_chrome = $aiwp_plugin->chrome()->exists();

$aiwp_part = static function ( string $slug ): void {
	if ( ! function_exists( 'do_blocks' ) ) {
		return;
	}
	$markup = do_blocks( sprintf( '<!-- wp:template-part {"slug":"%s","tagName":"div"} /-->', $slug ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core block output.
	echo $markup;
};

if ( ! $aiwp_chrome && ! $aiwp_block ) {
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

if ( $aiwp_chrome ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_plugin->chrome()->render( 'header' );
} else {
	$aiwp_part( 'header' );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
echo $aiwp_html;

if ( $aiwp_chrome ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_plugin->chrome()->render( 'footer' );
} else {
	$aiwp_part( 'footer' );
}

wp_footer();
?>
</body>
</html>
