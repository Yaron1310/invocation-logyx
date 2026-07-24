![Invocation](assets/banner-772x250.png)

# Invocation — AI Page & Section Builder

> Forge on-theme WordPress pages with AI — grounded in your own blocks, patterns, theme, media, and links.

Invocation is a self-hosted, open-source AI assistant for the WordPress block editor (**WordPress 7.0+**). It leans on core for everything provider-related — you connect your own OpenAI, Anthropic, or Google key once under **Settings → Connectors** — and focuses on the part core doesn't do: turning your site's *actual* registered blocks, block patterns, `theme.json` design tokens, media, and internal links into valid, on-theme Gutenberg layouts.

No external service. No database. No Docker at runtime. Your AI key stays in core; nothing is sent anywhere until you ask it to generate.

## What it does

- **Generate** a single section or a full multi-section page from a prompt.
- **Fill a pattern** — pick one of your theme's block patterns and have AI write real content into it, keeping the structure.
- **Refine** any block in place from the block toolbar ("make this punchier", "add a CTA", …).
- **Stays on-brand** via an editable **Site Brief** (purpose, audience, voice, offerings) injected into every generation.
- **Never invents** block types, image URLs, or internal links — it uses what actually exists on your site (and repairs guessed links).

## Requirements

| | |
|---|---|
| WordPress | **7.0+** |
| PHP | **8.1+** |
| AI provider | The official **[AI plugin](https://wordpress.org/plugins/ai/)** + a provider added under **Settings → Connectors** |
| Other plugins | Nothing else — the MCP Adapter is bundled (see below) |

> **You must set up an AI provider.** WordPress 7.0 ships the AI Client and the Connectors *framework*. Install the official **[AI plugin](https://wordpress.org/plugins/ai/)**, then open **Settings → Connectors** (`/wp-admin/options-connectors.php`) and add a provider (OpenAI, Anthropic, or Google) with your API key — providers are installed right from that page. Without a provider configured, generation fails with *"No models found that support text generation."* Invocation's admin page links both steps.

### WordPress core features it relies on

Invocation is built entirely on first-party WordPress 7.0 building blocks — it does not bundle its own AI/provider code:

| Core feature | Used for |
|---|---|
| **Abilities API** (`wp_register_ability`) | Registering Invocation's capabilities (see below) |
| **PHP AI Client** (`wp_ai_client_prompt()`) | Sending prompts and getting structured output |
| **Connectors API** (Settings → Connectors) | Provider selection + your API key (BYO key) |
| Native `parse_blocks()` / `serialize_blocks()` | Validating and normalising generated markup |

**No other plugins required.** Invocation **bundles** the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) (via Composer + the Jetpack Autoloader), so MCP works out of the box with nothing extra to install. If you happen to also run the standalone MCP Adapter plugin, the Jetpack Autoloader makes them coexist.

## Abilities ("skills")

Every capability is a registered **Ability**, which means it is callable in PHP (`wp_get_ability()`), over the REST API (`/wp-json/wp-abilities/v1/…`), and — with the MCP Adapter — as an MCP tool. Each enforces its own capability check.

| Ability | What it does | Capability |
|---|---|---|
| `invocation/generate-layout` | Build a section / full page / fill-from-pattern | `edit_posts` * |
| `invocation/refine-block` | Revise existing block markup from an instruction | `edit_posts` * |
| `invocation/list-patterns` | List the site's block patterns (sections) | `edit_posts` |
| `invocation/list-blocks` | List registered block types (incl. custom) | `edit_posts` |
| `invocation/get-theme-context` | `theme.json` colors, fonts, layout sizes | `edit_posts` |
| `invocation/search-media` | Find real images in the media library | `upload_files` |
| `invocation/search-internal-links` | Find real internal URLs (pages/posts/terms) | `edit_posts` |
| `invocation/gather-site-context` | Build/refresh the Site Brief | `manage_options` |
| `invocation/create-page` | Create a page/post (draft by default) | post type's create cap |
| `invocation/update-page` | Update a page/post by id | `edit_post` (that post) |
| `invocation/save-pattern` | Save a section / full-page layout as a reusable pattern | `wp_block` create cap (`edit_posts`) |
| `invocation/list-templates` | List the theme's assignable page templates (e.g. "Page No Title") | `edit_posts` |

\* The generative abilities' capability is filterable via `invocation_generation_capability` (default `edit_posts`) so you can limit who can spend the AI budget.

## MCP server

Invocation bundles the official WordPress MCP Adapter and registers an MCP server that exposes the abilities above as tools at:

```
/wp-json/invocation/mcp
```

Tools are named `invocation-<ability>` (e.g. `invocation-generate-layout`). Authenticate with a WordPress **Application Password**. This lets an agent in **Claude Code**, Claude Desktop, Cursor, etc. generate *and persist* whole pages end-to-end (`generate-layout` → `create-page`).

### Connecting a Claude client

Open **Invocation → Connect** in wp-admin. It creates an Application Password in one click and hands you a copy-ready config for either client.

- **Claude Desktop** (easiest) — paste the generated block into `claude_desktop_config.json`. It proxies to this site through [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote); requires Node.js.
- **Claude Code** — a ready-made plugin in [`clients/claude-code/`](clients/claude-code/), installed from this repo's marketplace. One install drives any number of sites via the bundled Fleet Hub:
  ```
  /plugin marketplace add invocation97/invocation
  /plugin install invocation@invocation
  /invocation:connect
  ```

> **claude.ai custom connectors are not supported yet.** They authenticate over OAuth, which the WordPress REST API does not speak — it uses Application Passwords. Use Claude Desktop in the meantime.

## Editor experience

- **Sidebar** (Invocation icon in the editor): choose a scope (section / full page / fill a pattern), a tone, write a prompt → blocks are inserted.
- **Block toolbar → Refine**: rewrite the selected block in place.
- **Admin → Invocation**: generate and edit your Site Brief.

## How it works

A small **context-provider** layer gathers grounding (theme tokens, an allow-list of blocks, relevant patterns, media, internal links, the Site Brief) and renders it into the system prompt. The model returns structured JSON, which is validated and normalised through WordPress' native `parse_blocks()` / `serialize_blocks()`, with a pass that repairs any guessed internal links to real URLs. Everything runs server-side; the editor just inserts the result.

## Installation

**From a release:** download the latest `invocation.zip` and install it via *Plugins → Add New → Upload*, then activate. Configure a provider under *Settings → Connectors*.

**From source:** see [CONTRIBUTING.md](CONTRIBUTING.md).

## Contributing

Issues and pull requests are welcome — please read [CONTRIBUTING.md](CONTRIBUTING.md) first. Security reports: see [SECURITY.md](SECURITY.md).

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
