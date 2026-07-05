=== Invocation - AI Page & Section Builder ===
Contributors: danilo1997
Tags: ai, gutenberg, blocks, patterns, content
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build styled Gutenberg content with AI, grounded in your own block theme and patterns. Bring your own AI key via WordPress Connectors.

== Description ==

Invocation is a self-hosted, open-source AI assistant for the WordPress block editor. It uses WordPress 7.0 core for everything provider-related — connect your own OpenAI, Anthropic, or Google key once under Settings → Connectors — and focuses on the part core does not do: turning your site's registered blocks, theme.json design tokens, and section patterns into valid, on-theme Gutenberg layouts.

Features:

* Generate a section or a full page from a prompt, grounded in your theme.
* Fill a chosen section pattern with real content.
* Refine any block in place from the editor toolbar.
* Uses real media and real internal links — never invented URLs.
* A Site Brief that keeps every generation on-brand.
* A built-in MCP server — drive everything from AI agents like Claude Code, with no extra plugin to install.

Everything runs on your server. No external service, database, or Docker required. Capabilities are registered as WordPress Abilities, so they are also available over REST and to MCP agents.

== External services ==

Invocation generates content using the AI provider you configure in WordPress 7.0 core under Settings → Connectors (for example OpenAI, Anthropic, or Google). When you trigger a generation, refinement, or "generate Site Brief" action, the plugin sends your prompt plus relevant context from your own site (such as your theme's design tokens, the names of your registered blocks and patterns, titles/URLs of your published pages, and media metadata) to that provider through core's AI Client in order to produce block markup. No data is sent unless you take one of these actions, and the plugin itself transmits nothing to any other external service. Your AI provider credentials are managed by WordPress core Connectors, not by Invocation.

Review the terms and privacy policy of whichever AI provider you connect before sending content. Those policies are published by the provider you choose (for example OpenAI, Anthropic, or Google), not by Invocation.

== Installation ==

1. Ensure you are running WordPress 7.0 or later.
2. Install the official "AI" plugin, then open Settings → Connectors and add a provider (OpenAI, Anthropic, or Google) with your API key — providers are installed from that page. WordPress core provides the AI Client and Connectors framework; a provider is required to supply a model.
3. Upload Invocation to the `wp-content/plugins/invocation` directory, or install it through the Plugins screen, and activate it. (The MCP server is built in — no other plugins needed.)
4. Open Invocation from the admin menu (it links you to both setup steps) and generate your Site Brief.

== Changelog ==

= 0.2.0 =
* Bundle the WordPress MCP Adapter, so MCP works with no extra plugin install.
* Generate sections / full pages, fill block patterns, and refine blocks in place, grounded in your theme, blocks, media, and internal links.
* Site Brief, create/update pages, and a built-in MCP server for AI agents (Claude Code).

= 0.1.0 =
* Initial release.

== Development ==

Invocation is open source. The block-editor UI is built from the JavaScript sources in `src/` (bundled with the plugin) using @wordpress/scripts — run `npm install && npm run build` to regenerate `build/`. Source repository: https://github.com/invocation97/invocation
