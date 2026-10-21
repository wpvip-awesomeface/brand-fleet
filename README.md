# Brand Fleet

Run hundreds of brand or location sites from one place.

Brand Fleet is a WordPress multisite plugin. You enter shared information once: business name, phone numbers, hours, logos, legal text. Every site shows it, and each location can keep its own value where you allow it. When something changes, you update it once, check a preview, and it rolls out to every site.

## What it looks like

You define a variable, say `phone_sales`. Each location gets its own number. In any page, template or SEO title, write:

```
Call us at {{phone_sales}}
```

Each site prints its own number. Change the number for 40 Quebec locations in one batch, review the before and after for each site, then apply.

## The main ideas

| Idea | What it means |
|---|---|
| **Variable** | A piece of shared information, like `business_name` or `opening_hours`. You choose its type (text, phone, image, hours, list, and more) and who can change it. |
| **Site value** | What one location shows. Sites inherit the shared value until they set their own, if the variable allows it. |
| **Token** | `{{variable_key}}` in content. It's replaced with the site's value when the page is shown. |
| **Segment** | A named group of sites, like "Province = Quebec" or "Language = French". Use it to target changes or give a group its own default. |
| **Batch** | A change across many sites. It's always previewed first, runs in the background, and can be undone. |

## What you can do

**Manage information across sites**
- Set shared values once, and let locations override them where you allow it.
- Give a segment its own default, for example French wording for French sites.
- Import values from a spreadsheet or Google Sheet, with a preview of every row.
- Download every site's values as a CSV.

**Change many sites safely**
- Bulk update up to 10,000 sites. Sites edited since the preview are skipped, not overwritten.
- Find and replace text across pages, patterns, templates, menus and SEO fields.
- Undo any of the last 10 batches.
- See who changed what, when, and restore an earlier value.

**Build pages that adapt per site**
- Blocks for the brand header, footer, opening hours and lists.
- A shared-content block that shows one master page (like a privacy policy) on every site, with each site's details filled in. Regional versions are picked automatically.
- Tokens also work in titles, SEO fields, image and link settings, and block bindings.
- Local SEO: each site prints its own schema.org `LocalBusiness` data.

**Run the fleet**
- Create a new location from an existing one, links and all.
- Give team members fleet roles without making them super admins.
- Let site admins request a change to a value the network controls.
- Add analytics or tag-manager code to every site, or chosen sites.
- Get an email when an import fails or a batch stops.
- Scan for tokens that would print blank or as raw text.

Every feature, in detail: [docs/features.md](docs/features.md).

## Install

Requires WordPress 6.9+ multisite and PHP 8.0+.

1. Copy this repository to `wp-content/plugins/brand-fleet`.
2. Network-activate **Brand Fleet** under **Network Admin → Plugins**.
3. Go to **Network Admin → Brand Fleet** to add variables and enroll sites.

On WordPress VIP, you can load it as a must-use plugin instead. Put it in `client-mu-plugins/brand-fleet/` and add a loader file that requires `brand-fleet/brand-fleet.php`.

Sites only change when you enroll them. Sites that aren't enrolled keep their own settings.

## Safe by design

- **Preview first.** Nothing changes across sites until you've seen the exact before and after.
- **No surprise overwrites.** Pages or sites edited after a preview are skipped and listed.
- **Undo and history.** Batches can be undone, and every value change is logged.
- **Fast pages.** What a page shows depends only on the site, never on the visitor, so pages stay fully cached.
- **Locked where it matters.** Only network admins can add embed code, and values the network locks can't be changed from a site.

## For developers

### Automation (Abilities API and MCP)

Every fleet action is available as an ability for scripts and AI agents, under the `brand-fleet/` prefix. Writes follow the same rules as the admin screens: start a preview, check `bulk-status`, then confirm with `advance-bulk`. Abilities that overwrite data are marked `destructive`. See the full list in [docs/features.md](docs/features.md).

### Hooks

Filters:

| Hook | Use it to |
|---|---|
| `brand_fleet_tokens( $tokens, $site )` | Add, change or remove tokens for a site. |
| `brand_fleet_text_filters( $hooks )` | Swap tokens in other plugins' text (for example another SEO plugin). |
| `brand_fleet_shared_content( $html, $slug, $kind )` | Change shared-content HTML. It must not vary by visitor. |
| `brand_fleet_purge_post_types( $types, $site )` | Choose which post types are purged from cache when values change. |
| `brand_fleet_local_business( $data, $site )` | Change the LocalBusiness schema before it prints. |
| `brand_fleet_ability_allowed( $allowed, $name )` | Change who can run an ability. |

Actions:

| Hook | Fires when |
|---|---|
| `brand_fleet_definition_saved( $key, $definition, $previous )` | A variable is created or changed. |
| `brand_fleet_profile_saved( $site, $new, $old, $via )` | A site's values are saved. An empty `$old` means the site was just enrolled. `$via` says how: `edit`, `bulk`, `import`, `undo`, `request` or `restore`. |
| `brand_fleet_batch_finished( $job, $phase, $applying )` | A batch finishes its preview (`ready`) or apply (`complete`), or is `cancelled` or `stopped`. |
| `brand_fleet_cloned_site( $source, $site, $id_map )` | A location was cloned. |

### Checks

Every pull request runs a syntax check and the WordPress VIP coding standard. To run them locally:

```bash
composer install
composer lint
composer phpcs
```

Integration tests live in `tests/`. Run each one with `wp eval-file tests/<name>.php` on a **disposable** local multisite (`*.localhost` or a VIP dev-env `*.lndo.site`). They create sites and content, so reset the network between files. Never run them on a real network.

### Uninstall

Deleting the plugin stops its scheduled tasks and clears any batches in progress. Brand data (variables, segments, site values, history) is kept, because removing it would change what every site shows. To delete that too, add this to `wp-config.php` first:

```php
define( 'BRAND_FLEET_DELETE_DATA', true );
```

Must-use installs never run the uninstall step.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
