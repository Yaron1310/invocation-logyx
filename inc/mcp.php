<?php
/**
 * Expose Invocation's abilities over MCP using the WordPress MCP Adapter, which
 * is bundled with the plugin (in vendor/, loaded via the Jetpack Autoloader) —
 * no separate install required. We register a custom MCP server and let the
 * adapter handle transport, spec versioning, auth and validation.
 *
 * Endpoint (HTTP transport): /wp-json/invocation/mcp
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities exposed as MCP tools.
 *
 * @return list<string>
 */
function invocation_mcp_abilities(): array {
	return array(
		'invocation/generate-layout',
		'invocation/refine-block',
		'invocation/list-patterns',
		'invocation/search-media',
		'invocation/upload-media',
		'invocation/search-internal-links',
		'invocation/get-theme-context',
		'invocation/list-blocks',
		'invocation/gather-site-context',
		'invocation/create-page',
		'invocation/update-page',
		'invocation/duplicate-page',
		'invocation/save-pattern',
		'invocation/list-templates',
	);
}

/**
 * Register the Invocation MCP server with the adapter.
 */
add_action(
	'mcp_adapter_init',
	static function ( $adapter ): void {
		$adapter->create_server(
			'invocation',
			'invocation',
			'mcp',
			'Invocation',
			__( 'Build on-theme Gutenberg layouts, fill section patterns, refine blocks, and read theme, media and link context.', 'invocation' ),
			'v' . INVOCATION_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			invocation_mcp_abilities(),
			array(),
			array()
		);
	}
);

/**
 * Boot the bundled MCP Adapter once all plugins are loaded; this in turn fires
 * the `mcp_adapter_init` action above. Guarded so a missing/!loaded adapter
 * degrades gracefully rather than fataling.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	},
	20
);
