# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**Invocation** is a self-hosted WordPress plugin (WP 7.0+, PHP 8.1+) that generates on-theme Gutenberg block layouts with AI. It ships no AI/provider code of its own — it relies on WordPress 7.0 core (Abilities API, PHP AI Client `wp_ai_client_prompt()`, and the Connectors framework for BYO provider key). The plugin's actual job is *grounding*: turning the site's registered blocks, `theme.json` tokens, block patterns, media, and internal links into valid, on-theme block markup.

The repo directory is `blocksmith-plugin` but the plugin was rebranded to **Invocation**; the internal prefix is `invocation_` / `INVOCATION_` and the text domain is `invocation`.

## Commands

```bash
npm install
npm run build          # compile src/ -> build/ (wp-scripts, entrypoints: src/index.js, src/admin.js)
npm start              # watch mode
npm run lint:js        # ESLint (+ wp-prettier) over src/
npm run lint:js:fix    # auto-fix JS
npm run plugin-zip     # produce distributable zip

# PHP: PHPCS with the WordPress Coding Standards, run through Docker (no local PHP needed).
npm run tools:install  # one-time: install PHPCS + WPCS into tools/vendor (gitignored)
npm run lint:php       # PHPCS against phpcs.xml.dist
npm run lint:php:fix   # phpcbf auto-fix

npm run lint           # everything: JS + PHP syntax + PHPCS
```

PHP tooling lives in `tools/composer.json` with its own gitignored `tools/vendor/`, deliberately
separate from the plugin's committed-and-shipped `vendor/`. Never add dev dependencies to the root
`composer.json` — that tree ships to wp.org.

Local WordPress env (Docker: WP + MariaDB + WP-CLI, plugin bind-mounted so PHP edits are live):

```bash
docker compose up -d
docker compose run --rm wpcli wp plugin activate invocation
# WP.org reviewers run this — it must pass:
docker compose run --rm --user 33:33 -e HOME=/tmp wpcli wp plugin check invocation
```

There is **no PHP test suite**. Verification is: `npm run lint` (ESLint + `php -l` + PHPCS), `wp plugin check`, and a manual editor smoke test (generate a section, refine a block). `build/` is gitignored but *is* shipped in the plugin zip — rebuild after JS changes.

## Architecture

**Everything is an Ability.** Each capability is registered via `wp_register_ability()` (in `inc/`), which for free gives: JSON-schema input/output validation, REST exposure (`meta.show_in_rest` → `/wp-json/wp-abilities/v1/…`), and automatic MCP tool surfacing. There is no separate REST or transport code. Every ability has its own `permission_callback`. When adding a capability, register an ability — don't add a REST route.

**Bootstrap** (`invocation.php`): checks core deps (`WP_Ability`, `wp_ai_client_prompt`) and bails with an admin notice if absent, then `require_once`s each `inc/*.php` on `plugins_loaded`. The bundled MCP Adapter (in `vendor/`, loaded via Jetpack Autoloader) is booted separately at `plugins_loaded` priority 20.

**The generation pipeline** is the heart of the plugin. Two generative abilities (`generate-layout`, `refine-block`) share the context system in `inc/context.php`:

1. `invocation_gather_context($query, $input)` runs every enabled **context provider** (theme, blocks, patterns, media, links) — each provider is `{ enabled, gather, render }` and the set is filterable via `invocation_context_providers` (this is the extension point for add-ons).
2. `invocation_context_grounding_lines($ctx)` renders providers into system-instruction lines.
3. `invocation_generate_text()` calls the WP AI client asking for structured JSON (raises the request timeout to 120s for full-page generations).
4. `invocation_finalize_markup()` validates via native `parse_blocks()`/`serialize_blocks()`, collects unregistered-block warnings, and repairs guessed internal links to real URLs.

Nothing is persisted by generation — the caller (editor, REST, or MCP agent) decides. Persistence is separate abilities (`create-page`, `update-page` in `inc/pages.php`).

**Grounding is a hard invariant.** The prompts and the finalize pass exist to stop the model inventing block types, image URLs, or internal links — it may only use what actually exists on the site. Preserve this when editing prompts (`inc/context.php` render functions, `invocation_build_layout_system_instruction`).

**MCP** (`inc/mcp.php`): registers one server exposing the abilities listed in `invocation_mcp_abilities()` as `invocation-<ability>` tools at `/wp-json/invocation/mcp`. To expose a new ability over MCP, add it to that list. Client config for Claude Code lives in `clients/claude-code/`.

**Capability gate:** the generative abilities use `invocation_generation_capability()` (default `edit_posts`, filter `invocation_generation_capability`) so owners can restrict who spends AI budget. Other abilities gate on the relevant native cap (`upload_files`, `manage_options`, per-post `edit_post`).

### Layout of `inc/`

| File | Role |
|---|---|
| `abilities.php` | Ability category + read abilities (`get-theme-context`, `list-blocks`); their execute callbacks double as context gatherers |
| `context.php` | Provider system, `gather`/`render`/`generate_text`/`finalize_markup` — shared by all generation |
| `generate-layout.php` | `generate-layout` ability (section / full-page / fill-from-pattern) |
| `refine-block.php` | `refine-block` ability (rewrite one block in place) |
| `patterns.php` / `internal-links.php` / `search-media.php` | Providers + their `list-*`/`search-*` abilities. Patterns include both registered (code) patterns and the site's saved `wp_block` user patterns (referenced as `user:{id}`, resolvable by `fill-from-pattern`) |
| `pages.php` | `create-page` / `update-page` write abilities (both accept a `template` slug) |
| `templates.php` | `list-templates` ability + template validate/apply helpers; page templates are stored in the `_wp_page_template` post meta, discovered via `WP_Theme::get_page_templates()` |
| `save-pattern.php` | `save-pattern` write ability — persists markup as a reusable `wp_block` pattern (unsynced by default); saved patterns feed back into generation grounding |
| `site-brief.php` | Editable Site Brief (`gather-site-context`) injected into generations |
| `mcp.php` | MCP server registration |
| `admin.php` / `editor.php` | Admin page + editor asset enqueue |

### JavaScript (`src/`)

Thin client — all intelligence is server-side. `index.js` = editor `PluginSidebar` calling `generate-layout` over the Abilities REST endpoint and inserting parsed blocks. `refine.js` = block-toolbar Refine button. `admin.js` = Site Brief admin app.

## Conventions

- PHP: `declare(strict_types=1)`, WordPress Coding Standards, PHP 8.1 target. Prefix functions `invocation_`, constants `INVOCATION_`, text domain `invocation`.
- Only `invocation.php`, `readme.txt`, `inc/`, `build/`, `src/`, `vendor/`, `composer.json` ship in the zip (see `files` in `package.json`).
- Update `README.md` / `readme.txt` when behavior changes; bump `INVOCATION_VERSION` (in `invocation.php`), `package.json`, and `readme.txt` `Stable tag` together on release.
