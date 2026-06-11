# WebChange Detector MainWP Add-on - Complete Guide

## Overview

This plugin is a **MainWP extension** (plugin header name: "WebChange Detector"). It lives **inside a
MainWP dashboard** and integrates WebChange Detector (WCD) by talking **directly to the WCD API**
(`/api/v2`) with one dashboard-level API token, on behalf of the verified account.

How it differs from the customer plugin (`wcd-plugin`, see `PLUGIN.md`):

- It does **not** install anything on child sites.
- All work happens **dashboard-side**: the agency stores one WCD API token and the extension calls
  the WCD API on its behalf (account, groups, websites, screenshots, comparisons).
- Each managed MainWP child site maps to a real WCD **website** (cms = wordpress) with a manual
  (on-demand) group and an auto (monitoring) group.

> Server-side behaviour of the endpoints is owned by the API. See `api/.docs/API.md`.

## Integration principle: hooks first

We integrate through **documented MainWP hooks/filters only**, so MainWP core updates cannot break us
by changing internals. Two operations have no hook and use guarded internal calls (see
`.docs/MAINWP-HOOKS.md`):

1. **Fetch a child site's pages** - `MainWP_Connect::fetch_urls_authed('get_all_pages')` (posts use
   the `mainwp_getallposts` hook).
2. **Trigger a single site's update** - `MainWP_Abilities_Updates::execute_update_site_*`.

Both are wrapped in `class_exists`/`method_exists` guards; if a future MainWP release changes them, the
feature degrades to a notice (and the after-update hook still drives post screenshots) instead of a
fatal.

## Plugin Structure

```
mainwp-addon/
├── webchangedetector-mainwp.php   # Entry: header + constants (WCD_MAINWP_VERSION, *_FILE/_PATH/_URL)
├── uninstall.php                  # Cleans options + cached account
│   # Class files follow the WordPress convention class-{lowercased-class-name}.php.
├── includes/
│   ├── class-wcd-mainwp-bootstrap.php      # Wires MainWP hooks, enqueues assets, injects entry points
│   ├── class-wcd-mainwp-options.php        # Network-aware option/transient storage
│   ├── class-wcd-mainwp-api.php            # WCD API v2 HTTP client (normalized results)
│   ├── class-wcd-mainwp-site-map.php       # MainWP site <-> WCD website/group mapping + domain normalization
│   ├── class-wcd-mainwp-site-settings.php  # API token, verification, account cache, settings page, auto-enable on add
│   ├── class-wcd-mainwp-url-sync.php       # Fetch child URLs (posts hook + pages guarded) -> two-step sync
│   ├── class-wcd-mainwp-update-flow.php    # Update trigger (guarded) + after-update hook safety-net + pending-update counts
│   ├── class-wcd-mainwp-ajax.php           # AJAX endpoints (settings + safe-update orchestration + runs overview)
│   ├── class-wcd-mainwp-runs-view.php      # "Change Detections" overview page (filters + batch/list rendering)
│   └── class-wcd-mainwp-widget.php         # Dashboard widget (account/credits)
├── templates/
│   ├── admin-page.php             # Extension settings page shell
│   ├── settings-page.php          # Token + auto-enable toggle + account/credits card + per-site URL config
│   ├── site-tab.php               # Per-site tab: status + safe-update entry (disabled when no updates)
│   ├── entry-banner.php           # Hero banner (dashboard + child-site overview + Updates page) -> safe-update flow
│   ├── runs-view.php              # Change Detections overview shell (filter bar + results container)
│   └── widget.php                 # Dashboard widget body
├── assets/css/wcd-mainwp.css      # Component styles (+ .wcd-w-* width steps, no inline CSS)
├── assets/css/wcd-runs.css        # Change Detections overview styles (scoped to .wcd-runs)
└── assets/js/wcd-mainwp.js        # Settings + safe-update orchestrator + results + runs overview
```

## MainWP Integration Points (hooks)

See `.docs/MAINWP-HOOKS.md` for the full table. Key ones:

| Hook | Purpose |
|------|---------|
| `mainwp_getextensions` | Register extension + settings page + icon |
| `mainwp_getsubpages_sites` | Per-site tab `WcdVisualRegressionTesting` + dashboard "Change Detections" page `WcdChangeDetections` |
| `mainwp_getmetaboxes` | Dashboard widget `wcd-checks-widget` |
| `mainwp_getdbsites` / `mainwp_extension_enabled_check` | List managed sites + the extension key |
| `mainwp_site_synced` | After a child syncs -> sync its URLs to the WCD group |
| `mainwp_added_new_site` | Auto-enable a newly added child site for WCD (opt-out via the settings toggle) |
| `mainwp_getallposts` | Fetch a child's posts (pages use the guarded call) |
| `mainwp_before_overview_widgets` | Render the WCD hero banner (Operations dashboard = bulk; child-site overview = single site) |
| `mainwp_updates_before_plugin_updates` | Render the same hero banner above the native Updates page's plugin list (only when updates are available) |
| `mainwp_after_wp_update` / `mainwp_after_plugin_theme_translation_update` | Post screenshots (recovery + non-card coverage) |

## Settings & Storage

- API token option: **`wcd_api_token`** (network-aware via `WCD_MainWP_Options`). Verified on save by
  calling `/account`; the account is cached in the `wcd_account_details` transient (5 min).
- Site map option: **`wcd_site_map`** = `{ site_id: { website_uuid, manual_group_uuid,
  auto_group_uuid, domain, enabled } }`. The `domain` is normalized once (scheme + trailing slash
  stripped, www + path kept) and reused verbatim for every WCD call (the API resolves websites by
  exact domain match).
- Auto-enable option: **`wcd_auto_enable_sites`** (`'1'`/`'0'`, default ON). When on, the
  `mainwp_added_new_site` hook auto-provisions + enables each newly added child site (and syncs its
  URLs). Each enabled site provisions WCD resources that count against the plan, hence the opt-out.

## API Communication

Client: `WCD_MainWP_API` (`includes/class-wcd-mainwp-api.php`), all static. Base URL
`https://api.webchangedetector.com/api/v2`, overridable via the `WCD_API_URL` **or** `WCD_API_URL_V2`
constant. Auth: `Authorization: Bearer {token}`; also sends `x-wcd-plugin`. Every method returns
`['ok'=>bool,'status'=>int,'data'=>mixed,'error'=>string]`.

Methods: `get_account`, `list_groups`, `create_group`, `get_group_urls`, `update_url_in_group`,
`update_urls_in_group`, `create_website`, `sync_urls` + `start_url_sync` (two-step), `take_screenshot`,
`get_queues`, `get_comparisons`, `get_batch`, `list_batches`, `update_comparison`.

Vocabulary: data-model terms (`manual`/`monitoring`, `source=manual`) in API calls; UI copy says
"On-Demand Check". Never expose AI model names (the API strips them server-side).

## Safe-Update Flow (confirm popup + unified in-card run, phased)

Entry: the WCD hero banner (`mainwp_before_overview_widgets`), shown at the top of the Operations
dashboard (bulk: all enabled sites) and an individual child-site overview (single site). We drive the
flow from our own button because no hook can trigger updates and the before-update hook can't await
async screenshots. The banner's Pages/Checks load progressively via the `banner_stats` AJAX so the
dashboard never blocks on WCD API calls.

Because the entry point is already "Run visual check & update", there is no with/without choice: the
button opens the **preflight popup, which is confirm-only**. The preflight renders the full run
overview from the enriched `preflight` AJAX payload: a Sites / Pages / Screenshots / Checks summary
strip, a **credit-coverage** bar (this run uses N checks · M of L available; "Enough credits" /
"N short" + "Upgrade plan" when short, which also disables Confirm), an expandable **"what gets
updated"** list (per-site core/plugins/themes items, read from MainWP's own DB upgrade columns), and
the **per-site URL list** (Desktop/Mobile chips). The API still enforces credit limits server-side
(returns 402).

On confirm the popup closes and the **whole run plays out in ONE unified card rendered inline on the
page** (the `.wcd-run-host` container right below the launch band; no running modal). The browser is
the scheduler and orchestrates the phases as **barriers** so every site advances together:

1. **PRE**: `take_pre` for every check-enabled site -> poll all batches aggregated until done.
2. **UPDATES**: `run_update` per site (sequential) -> `execute_update_site_*` (guarded, synchronous;
   suppresses the after-update hook so post is not double-fired). A per-site failure (e.g. offline) is
   tolerated; "nothing to update" is success-no-post.
3. **POST**: `take_post` for every site -> poll aggregated -> `get comparisons` per batch.

The unified card shows a **PRE -> UPDATES -> POST -> DONE** timeline (a fill track + 4 nodes), three
side-by-side panels (**Pre-update screenshots** and **Post-update screenshots** each with an aggregate
**Queue / Processing / Done / Failed** grid, and **Installing updates** in the middle: processing only,
core · plugins · themes) plus a **per-site list** (site · `done/total` · status pill, no
per-site bars). When the run finishes the footer flips to **"All good" / "N pages to review"** with a
**Re-check** button (re-runs POST + compare only) and a **View results** link to the embedded Change
Detections page. The detailed comparison table is no longer rendered in the card; it lives on the
Change Detections page.

`poll()` accepts **multiple batches** (`batches[]`, single `batch` still supported): it sums the
queues endpoint's pre-aggregated `meta.status_counts_by_batch` into one aggregate (feeds the Pre/Post
panels) and also returns a `by_batch` breakdown (feeds each site's `done/total` counter). Dynamic
widths (timeline fill, credit bar) use the `.wcd-w-*` step utilities, never inline styles.

**After-update hook safety-net** (`mainwp_after_*`): for updates started outside our card (cron/native),
or to recover post when the browser closes mid-run. Deduped per site via a short transient; suppressed
while the card flow owns the run. Post-only (no reliable pre in a synchronous before-hook).

Closing the page does NOT abort the flow (the AJAX chains/updates keep running server-side);
`beforeunload` warns before a tab close while a run is active, and the card's dismiss (X) is disabled
until the run finishes.

## Entry points

- **Hero banner** (`templates/entry-banner.php`, shared `renderScopedBanner()`): the safe-update entry,
  rendered on the Operations dashboard (bulk), an individual child-site overview (single site), AND
  above the native Updates page's plugin list (`mainwp_updates_before_plugin_updates`, only when
  updates are available). The CTA is disabled when there are no pending updates (the per-site tab
  button too); the unknown count (MainWP DB unavailable) leaves it enabled.

## Change Detections overview

`class-wcd-mainwp-runs-view.php` + `templates/runs-view.php` + `assets/css/wcd-runs.css`: a dashboard-wide
list of runs (batches) under the **Sites** menu (slug `WcdChangeDetections`, `sitetab => false`), modeled on
the webapp's Change Detections view. Filter bar (period / status / type / website / visual), batch +
flat list views, pagination, and an inline comparison table per run. Data via
`WCD_MainWP_API::list_batches` + `get_comparisons`, scoped to the account (the website filter narrows to a
managed site's manual + auto groups). AJAX `runs_render` (list) + `runs_comparisons` (drill-in) return
rendered HTML fragments; the filter/accordion JS lives in `wcd-mainwp.js`, guarded by `#wcd-runs`. UI
copy uses "On-Demand Checks / Monitoring / Auto-Update Checks"; the data model stays
`manual`/`monitoring`/`auto_update`. The view re-scopes the webapp's CSS tokens locally (no dependency
on the webapp stylesheet).

## Security

- Every AJAX handler calls `check_ajax_referer( self::NONCE, 'nonce', false )` **inline** (nonce
  `wcd_mainwp_ajax`) and passes the result to `self::verify()`, which also enforces
  `current_user_can('manage_options')` and sends a 403 on failure. The check is inline (not hidden in a
  helper) so static analysis sees it in each handler's scope — no `phpcs:ignore` is used anywhere.
- The two request-reading helpers (`site_id()`, `scope_site_ids()`) re-check the nonce before touching
  `$_POST`, so the read genuinely cannot happen without a valid nonce (defense in depth).
- `admin_post_wcd_save_settings` verifies its nonce + capability.
- Input sanitized; output escaped; URLs via `esc_url`.

## Coding standards

The add-on ships a `phpcs.xml.dist` (WordPress standard, text domain `webchangedetector`, prefixes
`WCD_MainWP` / `webchangedetector`) and a `composer.json` with the WPCS dev dependency. Run
`composer install` then `composer lint` (or `composer lint:fix`). The tree is clean: **0 phpcs errors/warnings,
0 ignores**. All identifiers are snake_case; class files follow `class-{name}.php`.

## Local Dev

`.wp-env.json` boots WordPress (port 8081) with this plugin + MainWP. The **committed** config carries
**no** API override, so the plugin defaults to the production API (`api.webchangedetector.com`).

For local development, point at the local API via the **gitignored** `.wp-env.override.json` (already
set to `http://api.webchangedetector.test/api/v2/`):

```json
{ "config": { "WCD_API_URL_V2": "http://api.webchangedetector.test/api/v2/" } }
```

The client reads `WCD_API_URL` first, then `WCD_API_URL_V2`, then the production default. Run
`wp-env start` after changing the override. Note: wp-env runs in Docker, so the override host must be
resolvable + reachable from inside the container.

## Related Documentation

- Hook reference + principle: `.docs/MAINWP-HOOKS.md`
- API (server side): `api/.docs/API.md`
- Customer WP plugin (shared API vocabulary): `wcd-plugin/.claude/.docs/PLUGIN.md`
