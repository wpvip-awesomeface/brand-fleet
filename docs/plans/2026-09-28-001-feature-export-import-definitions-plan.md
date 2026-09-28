# Export and import variable definitions between networks

Issue: #9 · complexity:high

## Problem frame

Solutions engineers and agencies build a full fleet setup (20–50 variable
definitions) on one network — a demo, staging, or template — and then
recreate it by hand on another. That's slow and error-prone. This adds a way
to export the network's variable *definitions* (not per-site values) as
JSON, and import them into another network with a mandatory preview before
anything is written.

The plugin's existing safety model must hold: no write without a preview,
existing types stay immutable, every write is audited once, and admin
capability + nonce checks happen on every path (UI and Abilities API).

## Requirements (from the issue's acceptance criteria)

- Export button on the **Variable definitions** tab, nonce + `manage_network_options` checked, downloads `brand-fleet-definitions.json`.
- Export envelope carries a format version, `BRAND_FLEET_VERSION`, a UTC export time, and every entry from `Fleet::definitions()`.
- Export drops `sites` entirely and downgrades `access: selected` to `access: none` — site IDs are meaningless on another network.
- Import screen accepts pasted JSON or an uploaded `.json` file, capped at 1 MB.
- Import always shows a preview first: each key is **new**, **changed** (before/after of just the changed fields), **unchanged**, or **rejected** (with a reason).
- Rejections reuse the exact validation `Fleet::save_definition()` already runs (bad key, unsupported type, type change, invalid default, 200-definition cap) — no parallel copy of those rules.
- Applying the preview writes only new/changed keys; if the schema changed since preview, the apply is refused and the message tells the admin to reload and preview again (same `expected`/hash pattern as `save_definition`).
- One **Recent activity** entry per import, listing the keys written.
- Two abilities: `brand-fleet/export-definitions` (read-only) and `brand-fleet/import-definitions` (`preview: true` → read-only preview; writing needs `confirm: true` + the preview's hash).
- Plain-language UI copy; error messages say what to fix.
- `README.md` documents the feature and both abilities.
- `bash scripts/lint.sh` prints `Lint OK`.

## Scope boundaries

**In scope:** definitions only (key, label, type, default, scope, access,
required, clone); the Variable definitions tab UI; the two abilities.

**Out of scope (explicitly, per the issue):**
- Per-site profile values/overrides.
- Deleting definitions missing from the import file (import is additive/corrective only, never destructive).
- Any network transport of the file — it's carried by a person.
- WP-CLI commands.

**Also out of scope for this PR (not called out by the issue, kept small deliberately):**
- Any new JS asset. The existing bulk-update flow uses `fleet-batch.js` because it polls a chunked, resumable batch of up to 10,000 site writes. Import writes at most 200 definitions in one `update_network_option()` call — a single POST-preview / POST-apply pair of plain forms is enough, no polling loop needed.
- A server-side stored "job" (like `Fleet_Jobs`) for the import preview. See the concurrency decision below for why.

## Key technical decisions

**1. Extract `Fleet::validate_definition()` out of `write_definition()`.**
`Fleet::write_definition()` currently mixes concurrency/capability checks
with the actual field validation (key format, type, immutability, scope,
access, site list, default cleaning, required-with-default). Pull the pure
validation part into a new method:

```php
private static function validate_definition( array $defs, string $key, array $input ): array // returns the cleaned $d, or throws
```

`write_definition()` keeps the expected-hash check, the capability check,
and the `$defs[$key] = $d; update_network_option(...)` write, but calls
`validate_definition()` for the field rules. This is the one piece of
production code this feature touches outside the new files, and it's
required by the acceptance criterion that import rejections reuse
`save_definition`'s exact rules rather than a re-implementation that can
drift.

**2. No stored import "job"; the apply step re-submits the raw document.**
`Fleet_Jobs` (bulk updates) stores a per-user job in a network option
because a bulk run touches up to 10,000 sites across resumable chunks. Import
never needs that: at most 200 keys, one write. So the preview page instead
embeds the original JSON text and the schema hash computed at preview time
as hidden fields on the "Apply" form — the same shape as the existing
`schema_hash` hidden field on the single-definition edit form. Applying
re-parses that same document and re-derives the new/changed/rejected
classification from scratch (see decision 3) rather than trusting anything
the browser posted about *which* keys are new/changed. This avoids adding
any new persistent state and follows the "optimistic concurrency via
`expected`/hash" pattern named in the issue, rather than the
job-object pattern (also named in the issue, but that one is about the
preview-then-apply *shape*, which this still follows — preview must happen,
and apply is refused if it's stale).

**3. Apply always re-derives the diff; it never trusts a posted classification.**
Both `preview_import()` and `apply_import()` call the same private
`classify( array $document ): array`, which reads `Fleet::definitions()`
fresh each time. `apply_import()` takes the `schema` lock
(`Fleet::locked('schema', ...)`), re-checks `hash_equals( $expected,
Fleet::hash( Fleet::definitions() ) )` inside the lock, and only if that
still matches does it call `classify()` again and write the new/changed
rows. If the hash check fails, it throws "Definitions changed since preview;
reload and preview again." Because classification is a pure function of
`(document, current definitions)`, a matching hash guarantees the apply step
computes the identical new/changed/rejected set the admin reviewed — no
need to serialize or trust that set across the request boundary.

Concretely, `apply_import()` cannot call `Fleet::save_definition()` (which
calls `Fleet::locked('schema', ...)` itself) in a loop — nesting two calls to
`Fleet::locked()` with the same lock name would self-deadlock (the second
`add_blog_option()` fails because the first is still holding it, and that
failure surfaces as a misleading "Another update is running" error). So
`apply_import()` opens the lock once, builds the full merged `$defs` array
in memory, and does one `update_network_option()` call plus one
`Fleet::audit( 'import', 0, $keys_written )` call — matching the
"one activity entry per import" requirement directly, rather than
suppressing N audit calls with `Fleet::bulk_write()`.

**4. Import never touches the `sites` field on existing keys.**
`sites` isn't part of the exported schema (issue scope: "keys, labels,
types, defaults, scope, access, required, clone"). If a target network
already has a key with `access: selected` and a site list, and the import
carries the same key with a different `label`/`default`, naively replacing
the whole definition would silently wipe that site list — a destructive
side effect the issue doesn't ask for. So when writing a changed existing
key, `apply_import()` carries over the *current* `sites` value; new keys get
`sites: []` (there's nothing to carry over, and a brand-new key can't have
had per-site delegates yet). This is called out explicitly because it's not
obvious from reading `write_definition()` alone, which always takes `sites`
from its `$input`.

**5. The 200-definition cap is checked against a running count during preview.**
`validate_definition()`'s cap check is `! isset( $defs[$key] ) && count( $defs ) >= 200`,
evaluated against whatever `$defs` it's given. If a network at 190
definitions imports 50 new keys, checking each one against the same
snapshot would wrongly accept all 50. Preview must instead walk the
document's keys in file order, validate each against an accumulating local
copy of `$defs`, and only fold accepted keys into that copy before checking
the next one — exactly mirroring what would happen if `save_definition()`
were called once per key in sequence. `classify()` implements this loop
directly (see decision 3 — no reason to actually call `save_definition()` in
a loop, since that would also multiply lock/audit calls per decision 3).

**6. Export is a dedicated `admin-post` action, not folded into `render()`.**
Sending `Content-Disposition: attachment` headers requires nothing to have
been echoed yet. `Fleet_Admin::render()` already echoes the wrapping
`<div class="wrap">` before any tab-specific method runs, so handling the
export inside `definitions()` would hit "headers already sent." A new
`admin_post_brand_fleet_export` action (mirroring the existing
`admin_post_brand_fleet_save`) runs standalone, checks the nonce and
capability, streams the JSON, and exits — same shape as the existing save
handler, just for a GET download instead of a POST write.

**7. Import preview is a second dedicated `admin-post` action, not the
generic `save()` dispatcher.** Every existing task inside
`Fleet_Admin::save()` ends by writing something and then
`wp_safe_redirect()`-ing. A preview isn't a write and has nothing sensible
to redirect to (redirecting would lose the POSTed document via the
GET round-trip, since it can be up to 1 MB). So preview gets its own
`admin_post_brand_fleet_import_preview` action that renders the full preview
page inline and exits. Only the actual write — `task=import-apply` — goes
through the existing `save()` dispatcher and its normal redirect-after-write
behavior, consistent with every other write in the plugin.

**8. One new file, one new class: `includes/class-fleet-transfer.php`,
`Fleet_Transfer`.** Houses `export()`, `decode()` (shared JSON/size-limit
parsing for the two raw-text input paths), and the preview/apply pair. Export
and import are two sides of the same "definitions transfer" concept and
share the envelope shape (`format_version`, `plugin_version`,
`exported_at`, `definitions`), so one file is more cohesive than two; this
follows the existing convention of one class per concern file
(`class-fleet-jobs.php`, `class-fleet-clone.php`).

**9. Ability input carries a parsed `document` object, not raw file bytes.**
MCP/API callers pass structured JSON, not a file upload — `document` is
typed `object` in the ability's `input_schema`, same shape `Fleet_Transfer`
expects after `Fleet_Admin`'s `decode()` step. No ability-side size limit is
needed beyond what the Abilities API / REST stack already enforces on
request bodies; the 1 MB cap in the issue is specifically about the UI's
paste/upload inputs.

### Named gap: none identified

Everything this feature touches (`Fleet::definitions()`,
`Fleet::save_definition()`, the abilities registration helper, the
admin-post dispatch pattern, `Fleet::locked()`, `Fleet::hash()`,
`Fleet::audit()`) is first-party code already read in full above. There's no
external API or private dependency this plan has to guess at.

## Implementation units

### Unit 1 — Extract `Fleet::validate_definition()`

**Goal:** isolate the pure field-validation rules so both the existing save
path and the new import path call the identical logic.

**Files:** `includes/class-fleet.php`

**Approach:**
- Add `private static function validate_definition( array $defs, string $key, array $input ): array` containing everything from the current `write_definition()` body between the capability check and the `update_network_option()` call: key-format regex + `master_site` guard, the 200-cap check, type allow-list + immutability check, scope/access allow-list check, building `$d`, the per-site `Fleet::site()` loop, `Fleet::clean()` for the default, and the required-with-default check. Returns the finished `$d`.
- `write_definition()` keeps: the `$expected` hash check, the `Fleet::network_admin()` check, calls `validate_definition( self::definitions(), $key, $input )`, then `$defs[$key] = $d; update_network_option(...); self::audit(...); Fleet_Cache::schedule();`.
- No behavior change for the existing single-definition save path.

**Test scenarios (manual, per `CLAUDE.md` — no WP test harness in CI):**
- Add a new variable from Network Admin → Variable definitions; confirm it saves as before.
- Edit an existing variable's label/default; confirm it saves.
- Try to change an existing key's type; confirm the same "Existing types are immutable" error appears.
- `bash scripts/lint.sh` prints `Lint OK`.

### Unit 2 — `Fleet_Transfer` class: export

**Goal:** produce the export envelope as a plain PHP array, reusable by both the download handler and the `export-definitions` ability.

**Files:** `includes/class-fleet-transfer.php` (new)

**Approach:**
```php
const FORMAT_VERSION = 1;
public static function export(): array {
    $definitions = array();
    foreach ( Fleet::definitions() as $key => $d ) {
        unset( $d['sites'] );
        if ( 'selected' === $d['access'] ) { $d['access'] = 'none'; }
        $definitions[ $key ] = $d;
    }
    return array(
        'format_version' => self::FORMAT_VERSION,
        'plugin_version' => BRAND_FLEET_VERSION,
        'exported_at'    => gmdate( 'c' ),
        'definitions'    => $definitions,
    );
}
```

**Test scenarios:**
- Call via the ability (see Unit 5) against a network with a mix of `access: all`, `selected` (with sites), and `none` definitions; confirm `sites` is absent everywhere and no `access: selected` survives.
- Confirm `format_version`, `plugin_version`, `exported_at` are present and `exported_at` parses as UTC.

### Unit 3 — `Fleet_Transfer`: decode + classify + preview/apply

**Goal:** shared JSON parsing, and the preview/apply pair described in decisions 2–5.

**Files:** `includes/class-fleet-transfer.php`

**Approach:**
- `public static function decode( string $raw ): array` — reject if `strlen( $raw ) > 1_048_576` ("File is larger than the 1 MB limit."), `json_decode( $raw, true )` with `JSON_THROW_ON_ERROR` in a try/catch → "That doesn't look like valid JSON. Check the file and try again.", require the result to be an array with an integer `format_version` matching `self::FORMAT_VERSION` ("This file uses a newer or unrecognized export format.") and an array `definitions` key ("This doesn't look like a Brand Fleet export file.").
- `private static function classify( array $document ): array` — walks `$document['definitions']` in file order against a running `$defs` copy seeded from `Fleet::definitions()`. For each key: try `Fleet::validate_definition( $defs, $key, $input )`; on success, compare the cleaned result to `$defs[$key] ?? null` across `label, type, default, scope, access, required, clone` (explicitly excluding `sites`, per decision 4) to mark `new` / `changed` (with a `diff` of only the differing fields, each `['before' => ..., 'after' => ...]`) / `unchanged`; fold accepted new/changed rows into the running `$defs` copy so the next key's 200-cap check sees them (decision 5). On a thrown exception, mark `rejected` with the exception message as the reason and do *not* fold anything in. Returns `['rows' => [key => row...], 'accepted' => [key => cleaned_d...]]` (accepted = new ∪ changed, keyed by the cleaned definition to write).
- `public static function preview_import( array $document ): array` — calls `classify()`, returns `['rows' => ..., 'schema_hash' => Fleet::hash( Fleet::definitions() )]`.
- `public static function apply_import( array $document, string $expected ): array` — `Fleet::locked( 'schema', function () use (...) { ... } )`: inside, re-check `Fleet::network_admin()`, `hash_equals( $expected, Fleet::hash( Fleet::definitions() ) )` or throw `RuntimeException( 'Definitions changed; reload and preview again.' )`; call `classify( $document )` again for the authoritative accepted set; if empty, return `[]` without writing; otherwise take a fresh `Fleet::definitions()`, for each accepted key carry over `$fresh[$key]['sites'] ?? []` onto the cleaned `$d` before assigning (decision 4), `update_network_option(...)`, `Fleet::audit( 'import', 0, array_keys( $accepted ) )`, `Fleet_Cache::schedule()`, return `array_keys( $accepted )`.

**Test scenarios:**
- Import a file with one brand-new key, one key identical to an existing one, one key with a changed `label`, and one key with an invalid type: preview shows new/unchanged/changed/rejected correctly, with `diff` on the changed row showing only `label`.
- Import a file that changes an existing `access: selected` key's `label` only: after apply, confirm the target's `sites` list is untouched.
- On a network near the 200-definition cap, import a file with more new keys than remaining headroom: preview accepts keys up to the cap in file order and rejects the rest with the cap message.
- Edit a definition in another browser tab between preview and apply, then apply the original preview: confirm it's refused with "Definitions changed; reload and preview again." and nothing is written.
- Apply a preview with only `unchanged`/`rejected` rows (nothing new/changed): confirm no write and no audit entry.

### Unit 4 — Admin UI: export

**Goal:** the "Export definitions" action and its admin-post handler.

**Files:** `includes/class-fleet-admin.php`

**Approach:**
- In `init()`, register `add_action( 'admin_post_brand_fleet_export', array( $this, 'export' ) );`.
- In the definitions list view (inside `definitions()`, the `! $edit && ! $new` branch, next to the existing "Add new variable" link), add an "Export definitions" link built with `wp_nonce_url( admin_url( 'admin-post.php?action=brand_fleet_export' ), 'brand_fleet_export' )`.
- New `public function export(): void`: `check_admin_referer( 'brand_fleet_export' )`; `if ( ! Fleet::network_admin() ) { wp_die(...) }`; `$document = Fleet_Transfer::export();`; send `header( 'Content-Type: application/json; charset=utf-8' )`, `header( 'Content-Disposition: attachment; filename="brand-fleet-definitions.json"' )`, `header( 'X-Content-Type-Options: nosniff' )`; `echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );`; `exit;`.

**Test scenarios:**
- As a network admin, click "Export definitions"; confirm a `brand-fleet-definitions.json` file downloads with the expected shape.
- Hit the same URL with a stale/missing nonce; confirm it's rejected (`check_admin_referer` behavior — `wp_die` with a 403-style failure).
- Hit it as a site admin (not network admin); confirm it's rejected.

### Unit 5 — Admin UI: import form, preview page, apply

**Goal:** the paste/upload screen, the preview-then-apply flow, and the Recent activity notice.

**Files:** `includes/class-fleet-admin.php`

**Approach:**
- In the definitions list view, add an "Import definitions" link to `self::url( array( 'tab' => 'definitions', 'view' => 'import' ) )`.
- In `definitions()`, read `$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''` (nonce-exempt, read-only navigation, same pattern as `$tab`/`$edit`); if `'import' === $view`, call `$this->import_form(); return;` before the existing `$new`/`$edit` branch.
- New `private function import_form(): void` — a plain-language intro paragraph, a form (`enctype="multipart/form-data"`, posts to `admin-post.php`, `action=brand_fleet_import_preview`, nonce field via `wp_nonce_field( 'brand_fleet_import_preview' )`) with a file input (`name="import_file"`, `accept=".json"`) and a textarea (`name="document"`) for pasting, plus a submit button "Preview import". A "← All variables" back-link.
- In `init()`, register `add_action( 'admin_post_brand_fleet_import_preview', array( $this, 'import_preview' ) );`.
- New `public function import_preview(): void`: `check_admin_referer( 'brand_fleet_import_preview' )`; capability check; read raw JSON from `$_FILES['import_file']` (validate `is_uploaded_file()`, size ≤ 1 MB) when present, else `wp_unslash( $_POST['document'] ?? '' )`; wrap the rest in try/catch like `save()` does, calling `wp_die( esc_html( $e->getMessage() ), 'Brand Fleet', array( 'response' => 400, 'back_link' => true ) )` on failure; on success, `Fleet_Transfer::decode()` then `Fleet_Transfer::preview_import()`, and render a full `<div class="wrap brand-fleet">` page: a summary count per status, a table of rows (key, label, status, diff-or-reason — reuse the existing before/after `<code>`/`wp_json_encode` display style from the bulk-update results table for the `changed` diff), a form posting to `admin-post.php?action=brand_fleet_save` with `task=import-apply`, a hidden `document` field (`<textarea style="display:none" name="document">` with `esc_textarea( $raw )`, to safely carry arbitrary JSON text), a hidden `expected` field (the `schema_hash` from the preview result), the standard nonce (`Fleet_Admin::begin( 'import-apply' )` in place of a bespoke nonce so it reuses the existing `brand_fleet_save` action), and a submit button "Apply import" — disabled-looking / absent if there are zero new/changed rows, with a note "Nothing to import — all keys are unchanged or rejected." instead. A "Cancel" link back to the definitions list.
- In `save()`, add an `elseif ( 'import-apply' === $task )` branch (inside the existing `Fleet::network_admin()`-gated `else`): `$document = Fleet_Transfer::decode( (string) ( $in['document'] ?? '' ) ); $written = Fleet_Transfer::apply_import( $document, (string) ( $in['expected'] ?? '' ) ); $url = self::url( array( 'tab' => 'definitions', 'imported' => count( $written ) ) );`.
- In the definitions list view, extend the existing `if ( isset( $_GET['saved'] ) )` notice block with an `elseif ( isset( $_GET['imported'] ) )` branch: "Imported N definition(s)." when count > 0, else "Nothing to import — all keys were unchanged or rejected."

**Test scenarios:**
- Paste a valid exported document from the same network unchanged; preview shows all rows `unchanged`; Apply form shows "Nothing to import."
- Upload a `.json` file with one new key and one key with a bad type; preview shows one `new` and one `rejected` with the "Unsupported variable type." reason; Apply writes only the new key; Recent activity shows one "Definitions imported" row listing that key.
- Paste malformed JSON; confirm `wp_die` shows "That doesn't look like valid JSON. Check the file and try again." with a back link, not a PHP warning.
- Upload a file over 1 MB; confirm the size-limit message.
- Open two browser tabs, preview an import in both, apply the first (writing a change), then try to apply the second; confirm "Definitions changed; reload and preview again." and no double-write.

### Unit 6 — Recent activity: label the new action

**Goal:** `import` audit entries render with clear copy instead of falling through to "Site updated".

**Files:** `includes/class-fleet-admin.php`

**Approach:**
- In `activity()`, extend the `$action` ternary (around line 112) to check `'import' === $row['action']` → `'Definitions imported'`, before the existing `count( $row['sites'] ) > 1` fallback.
- Extend the search-text ternary (around line 101) the same way, so searching "import" or "Definitions imported" finds these rows.

**Test scenarios:**
- After an import that writes at least one key, open Recent activity; confirm one row labeled "Definitions imported" listing the written variable labels, and that searching "import" finds it.

### Unit 7 — Abilities: `export-definitions` and `import-definitions`

**Goal:** the two abilities described in the acceptance criteria.

**Files:** `includes/class-fleet-abilities.php`

**Approach:**
- `export-definitions`: `$this->ability( 'export-definitions', array(), array(), static fn( $in ) => Fleet_Transfer::export(), true );` (read-only, empty schema, no input needed).
- `import-definitions`: properties `array( 'document' => $object, 'preview' => array( 'type' => 'boolean', 'default' => true ), 'confirm' => array( 'type' => 'boolean', 'default' => false ), 'expected' => array( 'type' => 'string' ) )`, required `array( 'document' )`, execute callback:
  ```php
  static function ( $in ) {
      if ( ! empty( $in['confirm'] ) ) {
          if ( empty( $in['expected'] ) ) { throw new \InvalidArgumentException( 'Preview first and pass back its expected hash.' ); }
          return array( 'written' => Fleet_Transfer::apply_import( $in['document'], $in['expected'] ) );
      }
      return Fleet_Transfer::preview_import( $in['document'] );
  }
  ```
  Read flag `false` (can write), default description is fine (the shared helper's default already says "Inspect first; ... writes require completed preview and confirm=true").

**Test scenarios:**
- Call `export-definitions` via an MCP client or `wp_execute_ability()`; confirm it returns the same shape as the download.
- Call `import-definitions` with `preview` (default) on a document with a mix of statuses; confirm no write occurs and the response matches the UI preview's rows/`schema_hash`.
- Call it again with `confirm: true` and the returned hash; confirm it writes and returns the written keys.
- Call it with `confirm: true` and a stale/wrong `expected`; confirm it errors without writing.

### Unit 8 — README

**Goal:** document the feature and the two abilities, per `CLAUDE.md`'s "update README when you add a user-facing feature" rule.

**Files:** `README.md`

**Approach:**
- Add a `Features` bullet describing export/import: what's exported (definitions only, no site data), the preview-then-apply requirement, and that it's for moving a fleet setup between networks (demo → customer, staging → production).
- Extend the existing abilities bullet (`## Features` → "Abilities API" line) to list `export-definitions` and `import-definitions` alongside the existing five.

**Test scenarios:** N/A (documentation); proofread against the shipped UI copy for consistency.

## Verification for the PR

- `bash scripts/lint.sh` must print `Lint OK`.
- No `.github/` changes in this feature, so `scripts/validate-workflows.sh` doesn't apply.
- `tests/clone-site.php` is unrelated and unaffected; not run (no multisite harness in CI, per `CLAUDE.md`).
- Manual steps to list in the PR (no automated multisite harness available):
  1. Export definitions from one network.
  2. Import that file into a fresh network; preview, then apply; confirm Recent activity shows one entry.
  3. Try one rejected case: edit the exported file to change an existing key's `type`, import, and confirm it's rejected with "Existing types are immutable" while the rest of the file still applies.

## Reviewer notes (apply during implementation)

- `Fleet::validate_definition()` is called from `Fleet_Transfer`, so it must be `public static`, not `private`. A private method here is a runtime fatal that `scripts/lint.sh` will not catch.
- Round trip of `access`: export turns `selected` into `none`. Importing onto a network where that key is already `selected` would switch it to `none` and silently end per-site editing for those sites. When the existing key is `selected` and the incoming value is `none`, keep the existing `access` (and `sites`), and don't count it as a change. Mention this in the preview intro copy in one short sentence.
- Keep UI copy plain for non-technical network admins (for example "Preview import", "Apply import", "Nothing to import"); the plan's wording is good, keep it.
