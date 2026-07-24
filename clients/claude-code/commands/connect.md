---
description: Connect a WordPress site (running Invocation) to Claude Code
---

Register a WordPress site with the Invocation Fleet Hub so its tools become available in this session. Follow these steps interactively — do not skip the verification.

Optional site URL or name from the user: $ARGUMENTS

## 1. Gather the site

Ask the user for (unless already given in the arguments or conversation):

- The **site URL** (e.g. `https://blog.example.com`). Accept a bare origin or a full MCP endpoint.
- A short **site name** — the handle they'll use in conversation ("build a pricing page on **blog**"). Lowercase, no spaces. Suggest one derived from the domain if they don't care.

## 2. Get an Application Password

Tell the user to open the site's Invocation admin page and use the **Connect** tab:

> `‹SITE URL›/wp-admin/admin.php?page=invocation` → **Create password**

That opens WordPress's native authorization screen; after they approve it, WordPress shows the generated password **once**. Ask them to paste it here.

- If they can't find the panel, the direct link is `‹SITE URL›/wp-admin/authorize-application.php?app_name=Claude%20%E2%80%94%20Invocation`.
- Also ask for their **WordPress username** if it isn't already known (the Connect panel shows it pre-filled in the snippet).

Application Passwords are usually formatted like `xxxx xxxx xxxx xxxx xxxx xxxx` (six groups). Keep the spaces — they're part of the password.

## 3. Write the registry

The registry is `~/.config/invocation/sites.json`, unless `INVOCATION_SITES_FILE` is set in the environment — check that first and honor it.

1. Read the current file if it exists; parse it as JSON. If it doesn't exist, start from `{}` and create the directory.
2. **Merge** the new entry — never overwrite or drop existing sites. If the chosen site name already exists, confirm with the user before replacing it.
3. Each entry is:
   ```json
   "‹name›": { "url": "‹site url›", "user": "‹wp username›", "appPassword": "‹pasted password›" }
   ```
   Use the site origin for `url` (the hub appends the endpoint path). If the user gave a full endpoint URL, or the site uses plain permalinks, keep the `?rest_route=/invocation/mcp` form they provided.
4. Write the file back as valid, pretty-printed JSON, then `chmod 600` it (it holds credentials).

## 4. Verify the connection

Confirm it actually works before declaring success:

1. Call the hub's `list-sites` tool and check the new name appears.
2. Call `check-site` with `site: "‹name›"`. Success reports how many tools the site offers, and refreshes the tool list so that site's `invocation-*` tools become available in this session.

Use `check-site` rather than one of the `invocation-*` tools here: before a site is registered the hub only advertises `list-sites` and `check-site`, so the per-site tools may not exist yet at this point in the conversation.

If verification fails, map the error and fix it rather than guessing:

- **401 / "not allowed"** → wrong username or password, or a typo/missing space in the password. Re-check step 2.
- **ENOTFOUND / timeout / connection refused** → the `url` is wrong or the site is unreachable.
- **404** → the endpoint path is off; retry the `url` as `‹SITE URL›/?rest_route=/invocation/mcp` (plain-permalink form).
- **Application Passwords unavailable** → the site needs HTTPS or a recognized local environment.

## 5. Wrap up

Tell the user the site is connected and show an example of using it: *"On ‹name›, generate a hero section matching the theme."*

A successful `check-site` refreshes the tool list automatically, so the site's tools should be usable straight away. If they still do not appear, a `/mcp` reconnect (or restarting Claude Code) will pick them up.
