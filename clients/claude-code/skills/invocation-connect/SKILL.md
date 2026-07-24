---
description: Connect (register) a WordPress site running the Invocation plugin to Claude Code, so its tools become available. Use when the user wants to add, connect, register, or set up a WordPress site with Invocation / the Fleet Hub, or when an Invocation tool fails because a site isn't in the registry.
---

# Connecting a WordPress site to Invocation

The `invocation` MCP server (the Fleet Hub, `hub.js`) routes tools to WordPress sites listed in a **site registry**: `~/.config/invocation/sites.json`, or the path in the `INVOCATION_SITES_FILE` environment variable. Connecting a site means adding one entry to that file and confirming it works.

Each entry:

```json
{
  "blog": {
    "url": "https://blog.example.com",
    "user": "your-wp-username",
    "appPassword": "xxxx xxxx xxxx xxxx xxxx xxxx"
  }
}
```

- **key** — the site name used in conversation ("build a page on **blog**"). Lowercase, no spaces.
- **url** — the site origin (the hub appends `/wp-json/invocation/mcp`), or a full endpoint URL. For plain-permalink sites use the `‹site›/?rest_route=/invocation/mcp` form.
- **user** — the WordPress username the Application Password belongs to.
- **appPassword** — a WordPress Application Password (keep the spaces).

## Steps

1. **Ask** for the site URL and a short site name (unless already known).
2. **Application Password**: point the user to the site's Invocation admin page — `‹SITE›/wp-admin/admin.php?page=invocation` → **Connect** → **Create password** (WordPress's native app-password screen; the password shows once). Direct link: `‹SITE›/wp-admin/authorize-application.php?app_name=Claude%20%E2%80%94%20Invocation`. Get the username too.
3. **Write the registry**: read the existing file (honor `INVOCATION_SITES_FILE`), **merge** the new entry without clobbering other sites, write valid pretty-printed JSON, and `chmod 600` it. Confirm before replacing an existing name.
4. **Verify**: call `list-sites`, then `check-site` with the new `site`. Use `check-site`, not one of the `invocation-*` tools — until a site is registered the hub advertises only `list-sites` and `check-site`, so the per-site tools may not exist yet. On failure, map the cause — 401 → wrong user/password; ENOTFOUND/timeout → wrong URL; 404 → try the `?rest_route=` form; app-passwords unavailable → needs HTTPS/local.
5. **Wrap up**: a successful `check-site` refreshes the tool list, so the site's tools work straight away. If they don't appear, reconnect with `/mcp`.

For the full interactive walkthrough this mirrors, see the `/invocation:connect` command. Once connected, use the `invocation` usage skill to build and edit content.
