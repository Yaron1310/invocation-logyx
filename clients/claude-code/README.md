# Invocation — Claude Code plugin

Drive one or **many** WordPress sites running [Invocation](https://github.com/invocation97/invocation) from a single Claude Code install: generate, fill, and refine on-theme Gutenberg layouts, look up each site's theme tokens, patterns, media, and internal links, and create/update pages — all over MCP, all on your Claude subscription.

The plugin ships the **Invocation Fleet Hub** (`hub.js`), a zero-dependency MCP server that reads a registry of your sites and routes every tool call to the site you name. One plugin, one tool set, unlimited sites — ideal for agencies maintaining a fleet of client sites.

## Prerequisites (on each WordPress site)

1. WordPress **7.0+**.
2. The **Invocation** plugin installed and active. The MCP server is **built in** — nothing else to install (the MCP Adapter ships inside Invocation).
3. An **Application Password** for your user on that site: *Users → Profile → Application Passwords*.

Each site's MCP endpoint is `https://THE-SITE/wp-json/invocation/mcp` (or `https://THE-SITE/?rest_route=/invocation/mcp` without pretty permalinks). You never call it directly — the hub does.

> An AI provider key under Settings → Connectors is **optional**: it's only needed for the on-server `generate-layout` / `refine-block` tools and the in-editor sidebar. When driving sites from Claude Code, Claude can read context, compose the block markup itself (covered by your Claude subscription), and persist it with `create-page` / `update-page` — no per-site API keys required.

## Install

```bash
/plugin marketplace add invocation97/invocation
/plugin install invocation@invocation
```

Requires Node 18+ on the machine running Claude Code.

## Register your sites

Create `~/.config/invocation/sites.json` (see `sites.example.json`):

```json
{
  "blog":        { "url": "https://blog.example.com",  "user": "danilo",     "appPassword": "xxxx xxxx xxxx xxxx" },
  "client-shop": { "url": "https://shop.client.com",   "user": "agency-bot", "appPassword": "xxxx xxxx xxxx xxxx" }
}
```

- The key is the **site name** you'll use in conversation ("create a pricing page on client-shop").
- `url` is the site origin; the hub appends the endpoint path. Paste a full endpoint URL instead (anything containing `/invocation/mcp` or `rest_route=`) for non-pretty-permalink or unusual setups.
- To keep the registry elsewhere, set `INVOCATION_SITES_FILE=/path/to/sites.json` in your environment.
- Adding a site later = adding one JSON entry. Tool calls pick it up immediately; restart (or `/mcp` reconnect) to refresh the `site` name list shown in tool schemas.

Keep this file out of git — it holds credentials. `chmod 600` it.

## Use it

Restart Claude Code, then:

- `list-sites` — see what's registered.
- Every Invocation tool (`invocation-create-page`, `invocation-get-theme-context`, …) takes a required `site` argument, so you can just say things like:
  - *"On **blog**, generate a three-card pricing section matching the theme and add it to the Pricing page."*
  - *"Create the same 'Summer sale' landing page on **blog** and **client-shop**, adapted to each site's theme."*
- `/invocation:build-section a pricing section with three plan cards`

## Tools

`list-sites`, plus per site: `invocation-generate-layout`, `invocation-refine-block`, `invocation-list-patterns`, `invocation-search-media`, `invocation-search-internal-links`, `invocation-get-theme-context`, `invocation-list-blocks`, `invocation-gather-site-context`, `invocation-create-page`, `invocation-update-page`, `invocation-save-pattern`, `invocation-list-templates`.

The hub fetches the tool list live from your sites, so new abilities in future Invocation versions appear automatically.

## Local / development sites

Local sites (Local by Flywheel, wp-env, Docker) work the same — register them in `sites.json` with their local URL.

1. **Application Passwords require HTTPS or a "local" environment.** Local by Flywheel serves HTTPS out of the box. On a plain-HTTP local box, enable them (dev only) with a mu-plugin: `add_filter( 'wp_is_application_passwords_available', '__return_true' );`
2. **Plain permalinks:** use the `?rest_route=/invocation/mcp` form as the `url`.
3. **Self-signed TLS:** trust the cert in your OS keychain, or (dev only) start Claude Code with `NODE_TLS_REJECT_UNAUTHORIZED=0`, or use the HTTP `?rest_route=` URL.

## Single-site alternative (no hub)

If you only ever talk to one site, you can skip the registry and add the endpoint directly:

```bash
claude mcp add --transport http invocation \
  "https://my-site.com/wp-json/invocation/mcp" \
  --header "Authorization: Basic $(printf 'USERNAME:APP PASSWORD' | base64)"
```

or use the official proxy:

```json
{
  "mcpServers": {
    "invocation": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://my-site.com/wp-json/invocation/mcp",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your application password"
      }
    }
  }
}
```

## Provider key vs. your Claude subscription

Two different AI usages are involved:

- **Claude Code's reasoning** (planning, composing markup) is covered by your Claude subscription — across every site in your fleet.
- **On-site generation** (`invocation-generate-layout`, `invocation-refine-block`, the generate action of `invocation-gather-site-context`, and the editor sidebar) runs on the **WordPress server** through that site's Connectors provider and needs an API key there, billed separately.

To run the whole fleet on **only your Claude subscription**: use the read tools (`invocation-get-theme-context`, `invocation-list-patterns`, `invocation-search-media`, `invocation-search-internal-links`) → let Claude write the blocks → persist with `invocation-create-page` / `invocation-update-page`. None of those touch a provider key.
