<?php
/**
 * Trusted article shell. AI never edits this file.
 *
 * One design, many posts. The body is WordPress's own, rendered by the_content
 * inside the AI's markup.
 *
 * @package AIWP\Designer
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aiwp_plugin = \AIWP\Designer\Plugin::instance();
$aiwp_post   = get_queried_object();

if ( ! $aiwp_post instanceof WP_Post || ! $aiwp_plugin->articles()->applies_to( $aiwp_post ) ) {
	get_template_part( 'index' );
	return;
}

$aiwp_html  = $aiwp_plugin->articles()->renderer_for( $aiwp_post )->render( $aiwp_post );
$aiwp_block = wp_is_block_theme();

// An article uses the site header and footer when one has been built, and the
// theme's otherwise, so a post never renders bare.
$aiwp_site_chrome = $aiwp_plugin->chrome()->exists();

$aiwp_part = static function ( string $slug ): void {
	if ( ! function_exists( 'do_blocks' ) ) {
		return;
	}
	$markup = do_blocks( sprintf( '<!-- wp:template-part {"slug":"%s","tagName":"div"} /-->', $slug ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core block output.
	echo $markup;
};

if ( ! $aiwp_site_chrome && ! $aiwp_block ) {
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
} else {
	$aiwp_part( 'header' );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
echo $aiwp_html;

if ( $aiwp_site_chrome ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field by the template engine.
	echo $aiwp_plugin->chrome()->render( 'footer' );
} else {
	$aiwp_part( 'footer' );
}

wp_footer();
?>
</body>
</html>
