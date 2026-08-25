<?php
/**
 * Invocation admin page: the Site Brief editor + AI setup guidance.
 *
 * @package Invocation
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INVOCATION_ADMIN_SLUG = 'invocation';

/**
 * Version of the Automattic WordPress MCP proxy offered in the Claude Desktop
 * configuration.
 *
 * Pinned deliberately: the snippet we hand users is fetched by `npx` at launch,
 * so `@latest` would silently pull whatever is newest and could break their
 * setup (or ship code we never tested) without any action on their part. Bump
 * this after verifying the new version against the MCP endpoint.
 *
 * @see https://www.npmjs.com/package/@automattic/mcp-wordpress-remote
 */
const INVOCATION_WP_MCP_PROXY_VERSION = '0.3.5';

/**
 * Register the top-level Invocation admin menu.
 */
add_action(
	'admin_menu',
	static function (): void {
		add_menu_page(
			__( 'Invocation', 'invocation' ),
			__( 'Invocation', 'invocation' ),
			'manage_options',
			INVOCATION_ADMIN_SLUG,
			'invocation_render_admin_page',
			'dashicons-layout',
			59
		);
	}
);

/**
 * Add a "Settings" link to the plugin's row on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( INVOCATION_FILE ),
	static function ( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . INVOCATION_ADMIN_SLUG ) ),
			esc_html__( 'Settings', 'invocation' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

/**
 * Enqueue the admin React app on the Invocation page.
 */
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( 'toplevel_page_' . INVOCATION_ADMIN_SLUG !== $hook ) {
			return;
		}
		$asset_path = INVOCATION_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;
		wp_enqueue_script(
			'invocation-admin',
			INVOCATION_URL . 'build/admin.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? INVOCATION_VERSION,
			true
		);
		wp_set_script_translations( 'invocation-admin', 'invocation' );
		wp_enqueue_style( 'wp-components' );

		$style_path = INVOCATION_DIR . 'build/admin.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'invocation-admin',
				INVOCATION_URL . 'build/admin.css',
				array( 'wp-components' ),
				$asset['version'] ?? INVOCATION_VERSION
			);
		}
	}
);


/**
 * The admin page tabs, as slug => label.
 *
 * @return array<string, string>
 */
function invocation_admin_tabs(): array {
	return array(
		'chat'    => __( 'Chat', 'invocation' ),
		'brief'   => __( 'Site Brief', 'invocation' ),
		'connect' => __( 'Connect', 'invocation' ),
		'setup'   => __( 'Setup', 'invocation' ),
	);
}

/**
 * The currently selected admin tab, validated against the known tabs.
 */
function invocation_current_admin_tab(): string {
	$tabs = invocation_admin_tabs();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch, no state change.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	return isset( $tabs[ $tab ] ) ? $tab : 'brief';
}

/**
 * Render the Invocation admin page: a tabbed shell around the Site Brief app,
 * the client connection guides, and the AI provider setup.
 */
function invocation_render_admin_page(): void {
	$tabs    = invocation_admin_tabs();
	$current = invocation_current_admin_tab();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Invocation', 'invocation' ); ?></h1>

		<nav class="nav-tab-wrapper wp-clearfix invocation-tabs" aria-label="<?php esc_attr_e( 'Invocation sections', 'invocation' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . INVOCATION_ADMIN_SLUG . '&tab=' . $slug ) ); ?>"
					class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
					<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>
				><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $current ) {
			case 'chat':
				invocation_render_chat_tab();
				break;
			case 'connect':
				invocation_render_connect_tab();
				break;
			case 'setup':
				invocation_render_setup_tab();
				break;
			default:
				invocation_render_brief_tab();
				break;
		}
		?>
	</div>
	<?php
}

/**
 * Site Brief tab — mount point for the React app.
 */
function invocation_render_brief_tab(): void {
	?>
	<div class="invocation-panel">
		<div id="invocation-admin-root"></div>
	</div>
	<?php
}

/**
 * Chat tab — mount point for the built-in chat assistant.
 */
function invocation_render_chat_tab(): void {
	?>
	<div class="invocation-panel">
		<div id="invocation-chat-root"></div>
	</div>
	<?php
}

/**
 * Setup tab — pointers to the WordPress AI plugin and Connectors, where the
 * user's own provider key lives.
 */
function invocation_render_setup_tab(): void {
	$ai_plugin_url  = admin_url( 'plugin-install.php?tab=plugin-information&plugin=ai' );
	$connectors_url = admin_url( 'options-connectors.php' );
	?>
	<div class="invocation-panel">
		<p class="invocation-intro">
			<?php esc_html_e( 'On-site generation runs through your own AI provider. Install the official WordPress AI plugin, then open Connectors to add a provider (OpenAI, Anthropic, or Google) and your API key.', 'invocation' ); ?>
		</p>

		<div class="invocation-section">
			<h2 class="invocation-section__title"><?php esc_html_e( 'AI provider', 'invocation' ); ?></h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'Required for the editor sidebar, Refine, and the generate tools. Reading context and saving pages work without it.', 'invocation' ); ?>
			</p>
			<p class="invocation-actions">
				<a class="button button-secondary" href="<?php echo esc_url( $ai_plugin_url ); ?>"><?php esc_html_e( 'Install the AI plugin', 'invocation' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( $connectors_url ); ?>"><?php esc_html_e( 'Open Connectors', 'invocation' ); ?></a>
			</p>
		</div>

		<div class="invocation-section">
			<h2 class="invocation-section__title"><?php esc_html_e( 'Using Claude instead', 'invocation' ); ?></h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'If you drive this site from Claude, Claude composes the layouts on your Claude subscription and Invocation only reads context and saves pages — so no provider key is needed here. See the Connect tab.', 'invocation' ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Connect tab — how to drive this site from a Claude client.
 *
 * Claude Desktop leads because it is the least technical route that actually
 * works today: a JSON block with credentials, no repository or CLI. claude.ai
 * connectors are deliberately not documented as a path — they authenticate over
 * OAuth, which the WordPress REST API (and so this MCP endpoint) does not speak.
 */
function invocation_render_connect_tab(): void {
	$endpoint = rest_url( 'invocation/mcp' );
	$user     = wp_get_current_user();
	$username = $user->exists() ? $user->user_login : 'your-wp-username';

	$app_name      = __( 'Claude — Invocation', 'invocation' );
	$authorize_url = add_query_arg(
		'app_name',
		rawurlencode( $app_name ),
		admin_url( 'authorize-application.php' )
	);

	$desktop_snippet = sprintf(
		"{\n  \"mcpServers\": {\n    \"invocation\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"@automattic/mcp-wordpress-remote@%s\"],\n      \"env\": {\n        \"WP_API_URL\": \"%s\",\n        \"WP_API_USERNAME\": \"%s\",\n        \"WP_API_PASSWORD\": \"PASTE-YOUR-APPLICATION-PASSWORD\"\n      }\n    }\n  }\n}",
		INVOCATION_WP_MCP_PROXY_VERSION,
		$endpoint,
		$username
	);

	$code_snippet = sprintf(
		"{\n  \"my-site\": {\n    \"url\": \"%s\",\n    \"user\": \"%s\",\n    \"appPassword\": \"PASTE-YOUR-APPLICATION-PASSWORD\"\n  }\n}",
		$endpoint,
		$username
	);

	$guide_url = 'https://github.com/invocation97/invocation/tree/main/clients/claude-code';
	?>
	<div class="invocation-panel">
		<p class="invocation-intro">
			<?php esc_html_e( 'Drive this site from Claude — generate, fill, and refine on-theme layouts, and save them as pages. Claude does the writing on your Claude subscription; this site supplies the theme, patterns, media, and links.', 'invocation' ); ?>
		</p>

		<div class="invocation-section">
			<h2 class="invocation-section__title"><?php esc_html_e( 'Step 1 — Create an Application Password', 'invocation' ); ?></h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'Both clients below sign in with a WordPress Application Password. Create one now — it is shown only once, so copy it before leaving the screen.', 'invocation' ); ?>
			</p>
			<?php if ( wp_is_application_passwords_available() ) : ?>
				<p class="invocation-actions">
					<a class="button button-primary" href="<?php echo esc_url( $authorize_url ); ?>">
						<?php esc_html_e( 'Create password', 'invocation' ); ?>
					</a>
				</p>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Application Passwords are unavailable on this site. They require HTTPS (or a recognized local environment). Enable HTTPS, then reload this page.', 'invocation' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="invocation-note">
				<?php
				printf(
					/* translators: %s: this site's MCP endpoint URL. */
					esc_html__( 'This site\'s MCP endpoint is %s', 'invocation' ),
					'<code>' . esc_html( $endpoint ) . '</code>'
				);
				?>
			</p>
		</div>

		<div class="invocation-section">
			<h2 class="invocation-section__title">
				<?php esc_html_e( 'Step 2 — Claude Desktop', 'invocation' ); ?>
				<span class="invocation-badge"><?php esc_html_e( 'Easiest', 'invocation' ); ?></span>
			</h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'In Claude Desktop, open Settings → Developer → Edit Config, then paste this into claude_desktop_config.json and restart Claude Desktop. Requires Node.js on your computer.', 'invocation' ); ?>
			</p>
			<textarea class="invocation-field invocation-desktop-snippet" readonly rows="14"><?php echo esc_textarea( $desktop_snippet ); ?></textarea>
			<p class="invocation-actions">
				<button type="button" class="button button-secondary invocation-copy" data-copy-target=".invocation-desktop-snippet">
					<?php esc_html_e( 'Copy configuration', 'invocation' ); ?>
				</button>
			</p>
			<p class="invocation-note">
				<?php esc_html_e( 'Replace PASTE-YOUR-APPLICATION-PASSWORD with the password from step 1, keeping its spaces.', 'invocation' ); ?>
			</p>
		</div>

		<div class="invocation-section">
			<h2 class="invocation-section__title"><?php esc_html_e( 'Step 2 (alternative) — Claude Code', 'invocation' ); ?></h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'For the terminal, and for managing many sites at once. Install the plugin, then run /invocation:connect and follow the prompts — it writes and verifies the entry for you.', 'invocation' ); ?>
			</p>
			<pre class="invocation-code"><code>/plugin marketplace add invocation97/invocation
/plugin install invocation@invocation
/invocation:connect</code></pre>
			<p class="invocation-note">
				<?php esc_html_e( 'Prefer to set it up by hand? Add this to ~/.config/invocation/sites.json:', 'invocation' ); ?>
			</p>
			<textarea class="invocation-field invocation-code-snippet" readonly rows="7"><?php echo esc_textarea( $code_snippet ); ?></textarea>
			<p class="invocation-actions">
				<button type="button" class="button button-secondary invocation-copy" data-copy-target=".invocation-code-snippet">
					<?php esc_html_e( 'Copy snippet', 'invocation' ); ?>
				</button>
			</p>
			<p class="invocation-note">
				<a href="<?php echo esc_url( $guide_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Read the full Claude Code guide', 'invocation' ); ?>
				</a>
			</p>
		</div>

		<div class="invocation-section">
			<h2 class="invocation-section__title"><?php esc_html_e( 'What about claude.ai?', 'invocation' ); ?></h2>
			<p class="invocation-section__desc">
				<?php esc_html_e( 'Adding this site as a custom connector on claude.ai is not possible yet. Custom connectors sign in with OAuth, which the WordPress REST API does not support — it uses Application Passwords. Support is planned; until then, use Claude Desktop above.', 'invocation' ); ?>
			</p>
		</div>
	</div>
	<?php
}
