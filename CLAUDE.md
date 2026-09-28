# Brand Fleet: agent guide

WordPress multisite plugin. PHP 8.0+, WordPress 6.9+. No build step: blocks and admin JS ship as plain files.

## Before opening a PR

- Run `bash scripts/lint.sh` (PHP + JS syntax check). It must print `Lint OK`.
- If you touched `.github/`, run `bash scripts/validate-workflows.sh`.
- `tests/clone-site.php` needs a disposable multisite (`wp eval-file`); it cannot run in CI. Say so in the PR instead of claiming it passed.

## Conventions

- Namespace `BrandFleet\`, constants `BRAND_FLEET_`, options/meta `brand_fleet_`, blocks `brand-fleet/*`.
- Code follows `WordPress-VIP-Go` (see `phpcs.xml.dist`): escape output, sanitize input, check capabilities and nonces, prefer `wp_cache_*` over uncached queries.
- Output must vary only by site, never by visitor, so pages stay edge-cacheable.
- Anything that writes across many sites goes through the preview-then-apply bulk flow; never write to sites directly in a loop.
- Update `README.md` when you add or change a user-facing feature or ability.
