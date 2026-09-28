# Brand Fleet

Network-managed brand variables and bulk site management for WordPress multisite. Define the information your sites share once, let individual sites inherit or override it, and push changes across hundreds of sites with a reviewable preview before anything is written.

## Features

- **Variable registry** — define shared variables (business name, phones, address, logos, accent color, socials, or your own) with a default value, scope, and who may change it.
- **Per-site inheritance** — enrolled sites inherit network defaults and can override values where the definition allows it. Sites that are not enrolled keep reading their own options.
- **Sites in Fleet** — managed sites are listed first, then unenrolled sites, each in site-ID order; sorting happens before pagination and works with search. The first visit indexes existing profiles once per network (100 sites per read).
- **Bulk updates** — select up to 10,000 sites, preview the exact before/after for each one, then apply the reviewed batch in resumable steps. Every batch is recorded in an activity log.
- **Add Site onboarding** — new sites get inherited defaults pre-filled, with required site-specific fields enforced. When a site is created with MultilingualPress “Based on site”, links copied from the starting site (pages, posts, synced patterns, navigation, templates and template parts) are pointed at the new site, so a cloned location never links back to its template.
- **Dynamic brand blocks** — server-rendered, build-free blocks that read the current site's values:
  - `brand-fleet/brand-header`
  - `brand-fleet/brand-footer` (default and corporate layouts)
  - `brand-fleet/shared-content` — renders a page from a master site with this site's values swapped in
- **Configurable footer** — the brand footer's "Services" column is set per site under **Settings → Brand Identity** (one service per line), with a neutral list when left empty.
- **Content tokens** — `{{business_name}}`, `{{legal_name}}`, `{{phone_sales}}`, `{{phone_support}}`, `{{address}}`, and any defined variable key resolve per site in post content.
- **Abilities API** — `brand-fleet/inspect`, `save-definition`, `configure-site`, `clone-site`, `preview-bulk`, and `advance-bulk` for automation and MCP clients.
- **Clone a location over Secure MCP** — `brand-fleet/clone-site` creates a new site from an existing location without the Add Site screen: it copies pages, posts, synced patterns, navigation, templates, template parts, global styles and design options, points copied links at the new site, fires `brand_fleet_cloned_site( $source, $site, $id_map )` so companion plugins (for example hub-page governance) can carry their own links across, and enrolls it with the variables you supply. Required clone fields (for example `business_name`, `location_name`) must be provided, the new site is private unless `public` is true, and `confirm: true` is required. Media stays in the source library. Locations with more than 500 items should be cloned from Network Admin.
- **Cache-friendly** — output varies only by site, never by visitor, so each site caches at full hit rate on edge caches keyed by host and path.

## Requirements

- WordPress 6.9+ multisite
- PHP 8.0+

## Install

1. Copy this repository into `wp-content/plugins/brand-fleet`.
2. Network-activate **Brand Fleet** from **Network Admin → Plugins**.
3. Open **Network Admin → Brand Fleet** to define variables and enroll sites.

Per-site identity settings live under **Settings → Brand Identity** on each site.

## Testing

On a disposable `*.localhost` multisite with Brand Fleet network-activated, run `wp eval-file tests/clone-site.php`. It creates sites, pages and variables; never run it on a real network. Hub-page governance checks run only when Network Content Governance is active.

## Development

Coding standards use the WordPress VIP ruleset:

```bash
composer global require automattic/vipwpcs
phpcs --standard=phpcs.xml.dist
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
