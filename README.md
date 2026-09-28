# Brand Fleet

Network-managed brand variables and bulk site management for WordPress multisite. Define the information your sites share once, let individual sites inherit or override it, and push changes across hundreds of sites with a reviewable preview before anything is written.

## Features

- **Variable registry** — define shared variables (business name, phones, address, logos, accent color, socials, or your own) with a default value, scope, and who may change it.
- **Per-site inheritance** — enrolled sites inherit network defaults and can override values where the definition allows it. Sites that are not enrolled keep reading their own options.
- **Bulk updates** — select up to 10,000 sites, preview the exact before/after for each one, then apply the reviewed batch in resumable steps. Every batch is recorded in an activity log.
- **Add Site onboarding** — new sites get inherited defaults pre-filled, with required site-specific fields enforced.
- **Dynamic brand blocks** — server-rendered, build-free blocks that read the current site's values:
  - `brand-fleet/brand-header`
  - `brand-fleet/brand-footer` (default and corporate layouts)
  - `brand-fleet/shared-content` — renders a page from a master site with this site's values swapped in
- **Configurable footer** — the brand footer's "Services" column is set per site under **Settings → Brand Identity** (one service per line), with a neutral list when left empty.
- **Content tokens** — `{{business_name}}`, `{{legal_name}}`, `{{phone_sales}}`, `{{phone_support}}`, `{{address}}`, and any defined variable key resolve per site in post content.
- **Abilities API** — `brand-fleet/inspect`, `save-definition`, `configure-site`, `preview-bulk`, and `advance-bulk` for automation and MCP clients.
- **Cache-friendly** — output varies only by site, never by visitor, so each site caches at full hit rate on edge caches keyed by host and path.

## Requirements

- WordPress 6.9+ multisite
- PHP 8.0+

## Install

1. Copy this repository into `wp-content/plugins/brand-fleet`.
2. Network-activate **Brand Fleet** from **Network Admin → Plugins**.
3. Open **Network Admin → Brand Fleet** to define variables and enroll sites.

Per-site identity settings live under **Settings → Brand Identity** on each site.

## Development

Coding standards use the WordPress VIP ruleset:

```bash
composer global require automattic/vipwpcs
phpcs --standard=phpcs.xml.dist
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
