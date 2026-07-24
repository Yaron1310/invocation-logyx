# Releasing Invocation

This guide covers cutting a release of the Invocation WordPress plugin (and the companion Claude Code plugin).

## 0. What ships

The distributed plugin zip contains: `invocation.php`, `readme.txt`, `inc/`, `build/`, `src/` (the compiled-JS sources, required by wp.org), `vendor/` (the bundled MCP Adapter), and `composer.json` (controlled by the `files` field in `package.json`). Dev-only files (`node_modules/`, `clients/`, `tools/`, `.gitignore`, `docker-compose.yml`, `composer.lock`, `package-lock.json`) are **not** shipped.

Note: `wp-scripts plugin-zip` also includes `README.md` and `package.json` regardless of the `files`
field. Both are harmless (no secrets) and wp.org accepts them — verify with `unzip -Z1 invocation.zip`.

## 1. Bump the version (keep these three in sync)

The version must match in all three places or WordPress.org will reject the release:

- `invocation.php` header — `Version: X.Y.Z`
- `readme.txt` — `Stable tag: X.Y.Z`
- `package.json` — `"version": "X.Y.Z"`

Also update `readme.txt` → `Tested up to:` to the latest WordPress version you've tested against, and add a `== Changelog ==` entry.

## 2. Build

```bash
composer install --no-dev   # bundles vendor/ (MCP Adapter via Jetpack Autoloader)
npm ci
npm run build               # compiles src/index.js + src/admin.js -> build/
```

(No local PHP/Composer? Run Composer through Docker: `docker run --rm -v "$PWD":/app -w /app composer:2 install --no-dev`.)

## 3. Pre-flight checks

```bash
npm run tools:install   # one-time: PHPCS + WPCS into tools/vendor/ (Docker, gitignored)
npm run lint            # ESLint + wp-prettier, php -l, and PHPCS (WordPress Coding Standards)
```

Run **Plugin Check** against the packaged file set (this is what reviewers run). Locally via the Docker dev env:

```bash
docker compose run --rm --user 33:33 -e HOME=/tmp wpcli \
  wp plugin check invocation --format=csv \
  --exclude-files=.gitignore,.eslintignore,.eslintrc.json,.prettierignore,.prettierrc.js,.editorconfig,.DS_Store,.mcp.json,.env,README.md,CLAUDE.md,RELEASE.md,CONTRIBUTING.md,SECURITY.md,docker-compose.yml,package.json,package-lock.json,composer.lock,invocation.zip,phpcs.xml.dist \
  --exclude-directories=node_modules,src,clients,tools,.claude,.claude-plugin,.github,.vscode,assets
```

Expected: `No errors found.` Fix anything reported before continuing.

Manual smoke test on a clean WordPress 7.0 site:
1. Activate the plugin; confirm no fatal and the admin notice behaves (shown only until a Site Brief exists / MCP Adapter missing).
2. Settings: open **Invocation → Generate from my site → Save**.
3. Editor: open the **Invocation** sidebar; generate a section, a full page, and fill a pattern.
4. Toolbar: select a block and **Refine**.

## 4. Package

```bash
npm run plugin-zip   # -> invocation.zip
unzip -Z1 invocation.zip   # verify: only invocation.php, readme.txt, inc/**, build/** (no hidden/dev files)
```

## 5. Tag + GitHub release

```bash
git tag -a vX.Y.Z -m "Invocation vX.Y.Z"
git push origin vX.Y.Z
gh release create vX.Y.Z invocation.zip --title "vX.Y.Z" --notes "…changelog…"
```

## 6. WordPress.org submission (first release)

1. Submit the plugin for review at https://wordpress.org/plugins/developers/add/ (upload `invocation.zip`). First review is manual.
2. **Disclosure (required):** Invocation sends page context and prompts to the AI provider the user configures under Settings → Connectors. Add a "uses an external service" section to `readme.txt` describing this (what data is sent, to which provider, and their terms/privacy links) before submitting — reviewers require this for plugins that contact third-party services.
3. Once approved you get an SVN repo. Release flow:
   ```bash
   svn co https://plugins.svn.wordpress.org/invocation invocation-svn
   # copy the packaged files into trunk/ (the zip's contents, not the zip)
   rsync -a --delete --exclude='.svn' <unzipped invocation>/ invocation-svn/trunk/
   cd invocation-svn
   svn add --force trunk/*
   svn cp trunk tags/X.Y.Z
   svn ci -m "Release X.Y.Z"
   ```
4. **Assets** (not in the plugin zip) go in the SVN `assets/` dir: `icon-256x256.png`, `banner-772x250.png`, `screenshot-1.png` … (referenced in readme's `== Screenshots ==`).
5. Confirm the live `Stable tag` in trunk `readme.txt` matches the tag you created.

### Pinned third-party versions
- The Claude Desktop config offered on the **Connect** tab pins the Automattic MCP proxy via `INVOCATION_WP_MCP_PROXY_VERSION` in `inc/admin.php` (currently `0.3.5`). It is fetched by `npx` on the user's machine at launch, so `@latest` would let an untested release land in their setup unannounced. Before a release, check for a newer version, verify it against a real MCP endpoint, then bump the constant and the copy in `clients/claude-code/README.md`.

### Notes for WP.org
- The **MCP Adapter** is **bundled** via Composer (loaded with the Jetpack Autoloader) — run `composer install` before packaging so `vendor/` is present. There are no external required plugins, so no `Requires Plugins` header is needed.
- No secrets ship in the repo; keys are the user's, held by core Connectors.

## 7. Claude Code plugin (companion, in `clients/claude-code/`)

Distributed separately from the WP plugin (it's **not** in the zip) — published via the marketplace manifest at the **repo root**, `/.claude-plugin/marketplace.json`, whose single plugin entry has `"source": "./clients/claude-code"`. Relative sources resolve against the marketplace root (the repo root), and `/plugin marketplace add invocation97/invocation` fetches that root manifest.

1. Version it independently in `clients/claude-code/.claude-plugin/plugin.json` (currently 0.3.1). Commands (`commands/*.md`) and skills (`skills/*/SKILL.md`) are auto-discovered — no need to list them in `plugin.json`.
2. Validate both manifests: `claude plugin validate . --strict` and `claude plugin validate ./clients/claude-code --strict`.
3. **Install it and use it** (see below) — validation only checks manifest shape, not that the thing works.
4. Publishing = push to `main` (and optionally `git tag`); users refresh with `/plugin marketplace update`. Install:
   ```
   /plugin marketplace add invocation97/invocation
   /plugin install invocation@invocation
   ```
5. Connecting a site: `/invocation:connect` (or the **Invocation → Connect** admin tab, added in WP 0.2.2) creates an Application Password and writes `~/.config/invocation/sites.json`. The site needs Invocation active (the MCP Adapter is bundled).

### Iterating on the plugin locally

Point a marketplace at the working tree, so every edit is one update away. It reads whatever branch is checked out:

```bash
claude plugin marketplace add /absolute/path/to/blocksmith-plugin
claude plugin install invocation@invocation
```

After changing anything under `clients/claude-code/`, bump `plugin.json` `version` and run the update loop — `plugin update` is a no-op if the version has not changed:

```bash
claude plugin marketplace update invocation
claude plugin update invocation@invocation
# then restart Claude Code (or /reload-plugins) — .mcp.json and MCP servers
# only load at startup; SKILL.md edits apply immediately
```

Inspect what a user would actually get, including the always-on token cost:

```bash
claude plugin details invocation@invocation
```

Swap back to the published source when you're done testing:

```bash
claude plugin marketplace remove invocation
claude plugin marketplace add invocation97/invocation
```

### Smoke test from a *fresh* state

Do this before every Claude-plugin release, in an isolated config so your real setup is untouched:

```bash
export CLAUDE_CONFIG_DIR=$(mktemp -d)
claude plugin marketplace add /absolute/path/to/blocksmith-plugin
claude plugin install invocation@invocation
claude plugin details invocation@invocation   # expect 4 skills + 1 MCP server
```

Then exercise the hub the way a brand-new user would — **with no site registry at all**:

```bash
# Pick the newest cached build explicitly — after an update the cache holds
# several versions, and a bare glob would silently run the older one.
HUB=$(ls -d "$CLAUDE_CONFIG_DIR"/plugins/cache/invocation/invocation/*/hub.js | sort -V | tail -1)

printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | INVOCATION_SITES_FILE=/nonexistent/sites.json node "$HUB"
```

`tools/list` must return `list-sites` and `check-site` — **never an error**. Shipping 0.3.0 with a throwing `tools/list` meant a new user's client registered *zero* tools and had no way to diagnose it; the happy path (a registry that already exists) hides this completely, which is why the fresh-state check is the one that matters. Finish by writing a registry, running `check-site`, and confirming the list grows to 14 tools without a restart.

## 8. Post-release

- Install `invocation.zip` on a fresh WP 7.0 site (`wp plugin install invocation.zip --activate`) and re-run the smoke test.
- Bump to the next dev version.

## Quick checklist

### WordPress plugin

- [ ] Version synced in `invocation.php`, `readme.txt` (Stable tag), `package.json`
- [ ] `readme.txt` Tested up to + Changelog updated
- [ ] `npm ci && npm run build`
- [ ] `npm run lint` clean (ESLint + `php -l` + PHPCS)
- [ ] Plugin Check: No errors found
- [ ] Manual smoke test on WP 7.0
- [ ] `npm run plugin-zip` + verified zip contents (no `.claude-plugin/`, `clients/`, `tools/`)
- [ ] External-service disclosure in `readme.txt` (WP.org)
- [ ] Git tag + GitHub release with `invocation.zip`
- [ ] WP.org trunk + tag committed; assets uploaded

### Claude Code plugin

- [ ] `plugin.json` `version` bumped (required — `plugin update` no-ops without it)
- [ ] `claude plugin validate .` and `… ./clients/claude-code`, both `--strict`
- [ ] `INVOCATION_WP_MCP_PROXY_VERSION` still current, and verified against a live endpoint
- [ ] Installed and updated via the loop: `plugin marketplace update` → `plugin update` → restart
- [ ] `claude plugin details` shows the expected skills + MCP server
- [ ] **Fresh-state check**: with no registry, `tools/list` returns `list-sites` + `check-site` and does not error
- [ ] Connect a real site end to end: `/invocation:connect` → `check-site` → tools usable without a restart
