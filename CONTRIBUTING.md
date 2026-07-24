# Contributing to Invocation

Thanks for your interest in improving Invocation! This guide covers local setup, standards, and how to propose changes.

## Project layout

```
invocation.php          # Main plugin file (header + bootstrap)
inc/                    # PHP: abilities, context providers, MCP, admin, write ops
src/                    # JavaScript sources (editor sidebar, refine toolbar, admin app)
build/                  # Compiled JS (generated; do not edit by hand)
assets/                 # Logo / banner sources + PNGs (for the wp.org listing)
clients/claude-code/    # Companion Claude Code plugin (MCP)
```

Only `invocation.php`, `readme.txt`, `inc/`, `build/`, and `src/` ship in the plugin zip (see the `files` field in `package.json`).

## Prerequisites

- **Docker** (for the local WordPress 7.0 environment)
- **Node.js 22+** and **npm** (for building the editor JS)

## Local setup

```bash
git clone https://github.com/invocation97/invocation.git
cd invocation
npm install
npm run build                 # compile src/ -> build/

docker compose up -d          # WordPress (latest) + MariaDB + WP-CLI

# install + activate (first run)
docker compose run --rm wpcli wp core install \
  --url=http://localhost:8080 --title="Invocation Dev" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
docker compose run --rm wpcli wp plugin activate invocation
```

WordPress runs at http://localhost:8080 (admin / admin). The plugin is bind-mounted, so PHP edits are live; re-run `npm run build` (or `npm start` for watch mode) after JS changes.

To exercise generation you need an AI provider key under **Settings → Connectors**. For MCP, also install the adapter:

```bash
docker compose run --rm --user 33:33 -e HOME=/tmp wpcli \
  wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
```

## Standards

- **PHP**: follows the WordPress Coding Standards, enforced by PHPCS via `phpcs.xml.dist`; target **PHP 8.1**, `declare(strict_types=1)`. Prefix functions with `invocation_`, constants with `INVOCATION_`, and use the `invocation` text domain. Every ability/REST route must have a real `permission_callback`.
- **JavaScript**: built with `@wordpress/scripts`, linted by ESLint with the WordPress config plus wp-prettier.
- **Editors**: `.editorconfig` is the source of truth (tabs, LF, final newline). VS Code users get matching settings from `.vscode/settings.json` and recommended extensions from `.vscode/extensions.json`.
- **Capabilities & escaping**: validate/sanitize input, escape output, and prepare all SQL.

## Before opening a PR

```bash
npm run build

# One-time, for PHP linting (runs PHPCS in Docker — no local PHP install needed):
npm run tools:install

# JS lint + PHP syntax + PHPCS (WordPress Coding Standards).
# Most violations are auto-fixable: npm run lint:js:fix && npm run lint:php:fix
npm run lint

# Plugin Check must pass (this is what wp.org reviewers run)
docker compose run --rm --user 33:33 -e HOME=/tmp wpcli \
  wp plugin check invocation
```

Please also smoke-test your change in the editor (generate a section, refine a block) where relevant.

## Pull requests

- Branch from `main`; keep PRs focused on a single change.
- Fill in the PR template (it appears automatically).
- Reference any related issue (`Fixes #123`).
- A PR should: build cleanly, pass Plugin Check, and update docs (`README.md` / `readme.txt`) when behavior changes.

## Commit messages

Short, imperative subject line ("Add X", "Fix Y"), with a brief body explaining the *why* when it isn't obvious.

## Reporting

- **Bugs / features**: open an issue using the provided templates.
- **Security**: do not open a public issue — see [SECURITY.md](SECURITY.md).

By contributing, you agree your contributions are licensed under GPL-2.0-or-later.
