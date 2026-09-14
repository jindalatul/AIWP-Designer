<?php
declare( strict_types = 1 );

namespace AIWP\Designer\MCP\Resources;

use AIWP\Designer\MCP\Tools\ToolRegistry;
use AIWP\Designer\Plugin;

/**
 * Read-only MCP resources. URIs are logical; no server file paths are exposed.
 */
final class ResourceRegistry {

	private Plugin $plugin;
	private ToolRegistry $tools;

	public function __construct( Plugin $plugin, ToolRegistry $tools ) {
		$this->plugin = $plugin;
		$this->tools  = $tools;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function list(): array {
		$resources = array(
			array(
				'uri'         => 'aiwp://site/context',
				'name'        => 'Site context',
				'description' => 'Site name, URL, theme, existing pages and brand inputs.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'aiwp://site/capabilities',
				'name'        => 'Site capabilities',
				'description' => 'Field types, behaviors and template features available right now.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'aiwp://design/system',
				'name'        => 'Design system',
				'description' => 'Active tokens, CSS variables and global CSS.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'aiwp://template-language',
				'name'        => 'Template language',
				'description' => 'The complete .aiwp template language reference.',
				'mimeType'    => 'text/markdown',
			),
			array(
				'uri'         => 'aiwp://site/chrome',
				'name'        => 'Site header and footer',
				'description' => 'The shared chrome: schema, content, markup and CSS.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'aiwp://behaviors',
				'name'        => 'Behavior library',
				'description' => 'The interactions AI can declare, and the markup hooks each one needs.',
				'mimeType'    => 'text/markdown',
			),
		);

		foreach ( $this->plugin->pages()->content_page_ids() as $page_id ) {
			$resources[] = array(
				'uri'         => 'aiwp://page/' . $page_id,
				'name'        => 'Page: ' . get_the_title( $page_id ),
				'description' => 'Schema, content, template and CSS for page ' . $page_id . '.',
				'mimeType'    => 'application/json',
			);
		}

		return $resources;
	}

	/**
	 * @return array<int,array<string,string>>|null
	 */
	public function read( string $uri, int $user_id ): ?array {
		$json = static fn( string $u, array $data ): array => array(
			array(
				'uri'      => $u,
				'mimeType' => 'application/json',
				'text'     => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			),
		);

		$markdown = static fn( string $u, string $text ): array => array(
			array(
				'uri'      => $u,
				'mimeType' => 'text/markdown',
				'text'     => $text,
			),
		);

		switch ( $uri ) {
			case 'aiwp://site/context':
				return $json( $uri, $this->tools->build_site_context() );

			case 'aiwp://site/capabilities':
				return $json( $uri, $this->tools->build_capabilities() );

			case 'aiwp://design/system':
				return $json( $uri, $this->tools->build_design_system() );

			case 'aiwp://site/chrome':
				return $json( $uri, $this->tools->site_get_chrome() );

			case 'aiwp://template-language':
				$prompt = $this->plugin->prompt_loader()->load( 'core/template-language' );
				return $markdown( $uri, $prompt['body'] ?? '' );

			case 'aiwp://behaviors':
				$prompt = $this->plugin->prompt_loader()->load( 'core/template-language' );
				return $markdown( $uri, $prompt['body'] ?? '' );
		}

		if ( preg_match( '#^aiwp://page/(\d+)$#', $uri, $matches ) ) {
			$result = $this->tools->call( 'page_get', array( 'page_id' => (int) $matches[1] ), $user_id );
			return $json( $uri, $result );
		}

		return null;
	}
}
