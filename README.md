# Brand Fleet

Network-managed brand variables and bulk site management for WordPress multisite. Define the information your sites share once, let individual sites inherit or override it, and push changes across hundreds of sites with a reviewable preview before anything is written.

## Features

- **Variable registry** — define shared variables (business name, phones, address, logos, accent color, socials, or your own) with a default value, scope, and who may change it.
- **Per-site inheritance** — enrolled sites inherit network defaults and can override values where the definition allows it. Sites that are not enrolled keep reading their own options. Order: the site's own value, then its main data site, then the first matching segment default, then the network default.
- **Segments** — name a group of sites by their dropdown or yes / no values (for example Province = Quebec, or Language = French) under **Brand Fleet → Segments**. Segments target bulk updates, find & replace, token checks and embeds, and give any variable a different default per segment (a French call to action, a Quebec disclaimer), including variables that are otherwise the same on every site. The segment list order decides which default wins when a site is in several. Membership is indexed in site meta, so targeting stays fast on large networks; while the index refreshes in the background, targeting scans profiles instead.
- **Imported values and spreadsheet import** — a variable can be set to *Each site has its own value, from an import*: nobody edits it by hand, and it is filled from a CSV under **Brand Fleet → Import** (paste it, or link a Google Sheet published to the web as CSV). Column headers are variable keys; one column matches rows to sites by site ID, site address, or any variable marked as an identifier (for example a practice code). Every import shows a preview with skipped rows and invalid cells, then applies as a background batch. An optional daily import applies without review, as the person who set it up, and skips days when they have another batch open.
- **Token check** — a read-only background scan that lists, per site, pages, patterns, template parts and menus whose `{{tokens}}` would print as raw text (no such variable) or print nothing (empty value). Run it after converting legacy shortcodes to tokens.
- **Fleet access by role** — under **Brand Fleet → Access**, network admins give main-site roles one or more fleet capabilities: manage variables, segments and connections (`brand_fleet_manage`); run bulk updates, find & replace, imports and token checks (`brand_fleet_bulk`); edit any site's values (`brand_fleet_edit_values`). People with these work from **Brand Fleet** in the main site's dashboard, without being super admins. Abilities check the same capabilities. Embed code, cloning and Add Site still need a network admin.
- **Sites in Fleet** — managed sites are listed first, then unenrolled sites, each in site-ID order; sorting happens before pagination and works with search. Existing profiles are indexed once per network, a few hundred sites per visit, so large networks never time out.
- **Bulk updates** — select up to 10,000 sites, preview the exact before/after for each one, then apply the reviewed batch. Previews and applies run in the background on VIP Cron Control, so you can close the page; progress is saved every 25 sites and an interrupted batch picks up where it stopped. Sites edited after the preview are skipped, not overwritten. A batch that is applying can be stopped, and a new preview can't replace it by accident. Every batch is recorded in an activity log.
- **Cache purges that scale** — changed sites (and the locations that read from them) join one deduplicated purge queue that the background runner works through on a time budget. Changing a variable definition purges enrolled fleet sites only.
- **Add Site onboarding** — new sites get inherited defaults pre-filled, with required site-specific fields enforced. When a site is created with MultilingualPress “Based on site”, links copied from the starting site (pages, posts, synced patterns, navigation, templates and template parts) are pointed at the new site, so a cloned location never links back to its template.
- **Dynamic brand blocks** — server-rendered, build-free blocks that read the current site's values:
  - `brand-fleet/brand-header`
  - `brand-fleet/brand-footer` (default and corporate layouts)
  - `brand-fleet/shared-content` — renders a page, or a synced pattern (a "showcase" such as an FAQ set or team list), from a master site with this site's values swapped in. A slug can hold tokens for regional versions: `privacy-{{province}}` shows `privacy-qc` on Quebec sites and falls back to `privacy`. Define a network variable `content_master` (a site ID) with segment defaults to pick a different master per segment, for example a French master for French sites. Editing a master page or pattern clears the shared copy at once and purges only the location pages that show shared content.
- **Configurable footer** — the brand footer's "Services" column is set per site under **Settings → Brand Identity** (one service per line), with a neutral list when left empty.
- **Typed variables** — text, multiline text, link, image, email, phone number, color, and number. Each type gets a matching input everywhere a value is entered (definitions, site overrides, bulk updates, Add Site): colors get a color picker, and images take a URL or an upload/pick from the Media Library (stored on the VIP File Service). Site values show a color swatch or image thumbnail. Typing a site value turns on its override automatically.
- **Content tokens** — `{{business_name}}`, `{{legal_name}}`, `{{phone_sales}}`, `{{phone_support}}`, `{{address}}`, and any defined variable key resolve per site in post content, block theme headers and footers, block widgets, post titles, excerpts, the document title, and SEO titles and descriptions (Yoast and Rank Math; add more hooks with the `brand_fleet_text_filters` filter).
- **Tokens in block settings** — link, image, email, phone, color, and number tokens also resolve inside HTML attributes, for example `<img src="{{hero_image}}">`, `<a href="tel:{{phone_sales}}">`, or `style="background:{{accent}}"`. Text tokens only resolve in attributes people read (`alt`, `title`, `aria-label`, …), never in links, class names, or event handlers, and link attributes are re-validated as a whole URL.
- **Dropdown, yes / no and date variables** — a dropdown lists the choices you define; a yes / no variable shows your own wording for each answer (for example “Open on weekends” / “Closed on weekends”); a date shows in each site's date format. Variables can carry an optional group (lists are organized by group) and help text shown to editors.
- **Network-locked embeds** — the *Embed code* type adds script or HTML (analytics, chat, tag managers) to the head, the start of the body, or the end of every enrolled site, or only the sites you choose. Only network admins who can post unfiltered HTML can create or change one, and sites never can. Embeds can use `{{tokens}}` for per-site IDs; inside an embed a token only fills in when its value is a plain ID or link, so a site value can never change the code.
- **Find & replace across the fleet** — search every fleet site, a segment, a brand hub, or chosen sites for text in pages, posts, synced patterns, template parts, templates or menus, and replace it with new text or a variable token. An option also covers SEO titles and descriptions (Yoast, Rank Math) and the custom fields listed under Variables; those fields have no revisions. When a site has more matches than one pass stores, the results offer a one-click repeat on just those sites (`more_sites` for MCP clients). The preview lists every matching page with a before → after snippet; leave pages out with a checkbox. Applying runs in the background, skips pages edited since the preview, and saves through WordPress so each page keeps a revision. Visible text only by default; an option also covers links, image sources and block settings. Also available to MCP clients as `brand-fleet/preview-replace`.
- **Custom fields** — list post meta keys under **Variable definitions → Where variables work**, and `{{tokens}}` in those fields return each site's values on the front end. wp-admin keeps showing the tokens.
- **Block bindings** — a `brand-fleet/variable` bindings source. In the block editor, connect a heading, paragraph, image, or button to a variable from the block's **Attributes** panel (settings tab); the editor previews this site's value and the front end renders it per site.
- **Export and import definitions between networks** — move a fleet setup (variable keys, labels, types, defaults, scope, access policy, required, and clone flags) from one network to another, for example demo → customer or staging → production. **Network Admin → Variable definitions → Export definitions** downloads `brand-fleet-definitions.json`; **Import definitions** accepts a pasted or uploaded file (capped at 1 MB) and always shows a full preview — each key marked new, changed, unchanged, or rejected (with a reason) — before anything is applied. Per-site values, site delegation lists, and site IDs never travel with the export; a key that already allows selected sites to edit it keeps that delegation on import instead of being reset. Every import is recorded once in Recent activity.
- **Abilities API** — `brand-fleet/inspect`, `save-definition`, `save-segments`, `configure-site`, `clone-site`, `preview-bulk`, `preview-replace`, `preview-import`, `preview-token-report`, `bulk-status`, `advance-bulk`, `cancel-bulk`, `export-definitions`, and `import-definitions` for automation and MCP clients. `preview-bulk` and `preview-replace` accept a `segment` instead of `site_ids`. Agents start a preview, poll `bulk-status`, then confirm with `advance-bulk` — no need to step through every site.
- **Clone a location over Secure MCP** — `brand-fleet/clone-site` creates a new site from an existing location without the Add Site screen: it copies pages, posts, synced patterns, navigation, templates, template parts, global styles and design options, points copied links at the new site, fires `brand_fleet_cloned_site( $source, $site, $id_map )` so companion plugins (for example hub-page governance) can carry their own links across, and enrolls it with the variables you supply. Required clone fields (for example `business_name`, `location_name`) must be provided, the new site is private unless `public` is true, and `confirm: true` is required. Media stays in the source library. Locations with more than 500 items should be cloned from Network Admin.
- **Cache-friendly** — output varies only by site, never by visitor, so each site caches at full hit rate on edge caches keyed by host and path. Definitions, resolved values and token maps are cached per request and cleared on any fleet write.
- **Must-use install** — works as an mu-plugin: put it in `client-mu-plugins/brand-fleet/` (VIP) or `mu-plugins/brand-fleet/` with a one-line loader that requires `brand-fleet/brand-fleet.php`. Sites still opt in by enrollment.

## Requirements

- WordPress 6.9+ multisite
- PHP 8.0+

## Install

1. Copy this repository into `wp-content/plugins/brand-fleet`.
2. Network-activate **Brand Fleet** from **Network Admin → Plugins**. Once active, the plugin row shows a "Manage fleet" link (for network admins) that jumps straight to **Network Admin → Brand Fleet**.
3. Open **Network Admin → Brand Fleet** to define variables and enroll sites.

Per-site identity settings live under **Settings → Brand Identity** on each site.

## Testing

On a disposable `*.localhost` multisite with Brand Fleet network-activated, run `wp eval-file tests/clone-site.php`. It creates sites, pages and variables; never run it on a real network. Hub-page governance checks run only when Network Content Governance is active.

`tests/fleet-p0.php` covers segments, segment defaults, regional shared content and showcases, content masters, master-edit purges, fleet access by role, imports, the token check, and find and replace in menus and SEO fields. Run each test file on a freshly reset network.

`tests/find-replace.php` covers find and replace, embeds, and dropdown / yes-no / date variables the same way. `tests/typed-variables.php` covers typed validation, attribute-aware swaps, titles, custom fields and block bindings. Run it with `wp eval-file tests/typed-variables.php` on a disposable local multisite (localhost or a VIP dev-env `*.lndo.site`).

## Development

Coding standards use the WordPress VIP ruleset:

```bash
composer global require automattic/vipwpcs
phpcs --standard=phpcs.xml.dist
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
