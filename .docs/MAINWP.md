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

## wordpress.org release

The plugin is prepared for the wordpress.org directory: slug + folder + main file + text domain are
all **`webchangedetector-for-mainwp`** ("X for MainWP" naming; a slug starting with `mainwp` would
violate trademark guideline 17). `readme.txt` carries the required external-service disclosure
(api.webchangedetector.com + ToS/privacy links), the header declares GPLv2-or-later (LICENSE file
matches), `Requires Plugins: mainwp`, and `.distignore` excludes the dev files from the
distribution. No API call is ever made before the user has configured a token (guideline 7).

## Plugin Structure

```
webchangedetector-for-mainwp/
├── webchangedetector-for-mainwp.php  # Entry: full wp.org header + constants (WCD_MAINWP_VERSION, *_FILE/_PATH/_URL)
├── uninstall.php                  # Cleans options + transients (token, site map, auto-enable, caches)
├── readme.txt                     # wordpress.org readme (external-service disclosure, FAQ, changelog)
├── .distignore                    # Files excluded from the wp.org distribution
│   # Class files follow the WordPress convention class-{lowercased-class-name}.php.
├── includes/
│   ├── class-wcd-mainwp-bootstrap.php      # Wires MainWP hooks, enqueues assets, injects entry points, left-menu item
│   ├── class-wcd-mainwp-options.php        # Network-aware option/transient storage
│   ├── class-wcd-mainwp-api.php            # WCD API v2 HTTP client (normalized results)
│   ├── class-wcd-mainwp-site-map.php       # MainWP site <-> WCD website/group mapping + domain normalization
│   ├── class-wcd-mainwp-site-settings.php  # API token, verification, account cache, settings page, auto-enable on add
│   ├── class-wcd-mainwp-url-sync.php       # Fetch child URLs (posts hook + pages guarded) -> two-step sync
│   ├── class-wcd-mainwp-update-flow.php    # Update trigger (guarded) + after-update hook safety-net + pending-update counts
│   ├── class-wcd-mainwp-ajax.php           # AJAX endpoints (settings + safe-update orchestration + runs overview)
│   ├── class-wcd-mainwp-runs-view.php      # "Visual Checks" overview page (filters + batch/list rendering)
│   └── class-wcd-mainwp-widget.php         # Dashboard widget (plan/credits/renewal/active sites)
├── templates/
│   ├── admin-page.php             # Extension settings page shell
│   ├── settings-page.php          # Token + auto-enable toggle + account/credits card (sites/URLs moved to the Settings tab)
│   ├── site-tab.php               # Per-site tab: status + safe-update entry (disabled when no updates)
│   ├── entry-banner.php           # Hero banner (dashboard + child-site overview + Updates page) -> safe-update flow
│   ├── runs-view.php              # Visual Checks overview shell (native MainWP chrome + Fomantic filter bar)
│   ├── sites-settings-page.php    # Visual Checks "Settings" tab: enable sites + URL selection
│   └── widget.php                 # Dashboard widget body
├── assets/css/wcd-mainwp.css      # Component styles (+ .wcd-w-* width steps, no inline CSS)
├── assets/css/wcd-runs.css        # Visual Checks overview styles (scoped to .wcd-runs)
└── assets/js/wcd-mainwp.js        # Settings + safe-update orchestrator + results + runs overview
```

## MainWP Integration Points (hooks)

See `.docs/MAINWP-HOOKS.md` for the full table. Key ones:

| Hook | Purpose |
|------|---------|
| `mainwp_getextensions` | Register extension + settings page + icon |
| `mainwp_getsubpages_sites` | Per-site tab `WcdVisualRegressionTesting` + the "Visual Checks" page `WcdVisualChecks` + its "Settings" tab `WcdVisualChecksSettings` (both menu_hidden) |
| `mainwp_menu_extensions_left_menu` | Places the "Visual Checks" entry inside the left menu's Monitoring category group |
| `mainwp_getmetaboxes` | Dashboard widget `wcd-checks-widget` (plan, credits bar, renewal countdown, active sites, quick links) |
| `mainwp_getdbsites` / `mainwp_extension_enabled_check` | List managed sites + the extension key |
| `mainwp_site_synced` | After a child syncs -> sync its URLs to the WCD group |
| `mainwp_added_new_site` | Auto-enable a newly added child site for WCD (opt-out via the settings toggle) |
| `mainwp_getallposts` | Fetch a child's posts (pages use the guarded call) |
| `mainwp_before_overview_widgets` | Render the WCD hero banner (Operations dashboard = bulk; child-site overview = single site) |
| `mainwp_updates_before_plugin_updates` | Render the same hero banner above the native Updates page's plugin list (only when updates are available) |
| `mainwp_after_wp_update` / `mainwp_after_plugin_theme_translation_update` | Post screenshots (recovery + non-card coverage) |

## Settings & Storage

- API token option: **`wcd_api_token`** (network-aware via `WCD_MainWP_Options`). Verified on save by
  calling `/account`; the account is cached in the `wcd_mainwp_account_details` transient (5 min; own prefix so it never collides with the customer plugin's `wcd_account_details` on the same site).
- Site map option: **`wcd_site_map`** = `{ site_id: { website_uuid, manual_group_uuid,
  auto_group_uuid, domain, enabled } }`. The `domain` is normalized once (scheme + trailing slash
  stripped, www + path kept) and reused verbatim for every WCD call (the API resolves websites by
  exact domain match).
- Active-run state: **`wcd_mainwp_active_run`** = the tracked safe-update run (sites, phase,
  pre/post batches, updated sites, last_activity) used for the resume flow; cleared when the run's
  post phase is fully dispatched.
- Auto-enable option: **`wcd_auto_enable_sites`** (`'1'`/`'0'`, default ON). When on, the
  `mainwp_added_new_site` hook auto-provisions + enables each newly added child site (and syncs its
  URLs). Each enabled site provisions WCD resources that count against the plan, hence the opt-out.

## API Communication

Client: `WCD_MainWP_API` (`includes/class-wcd-mainwp-api.php`), all static. Base URL
`https://api.webchangedetector.com/api/v2`, overridable via the `WCD_API_URL` **or** `WCD_API_URL_V2`
constant. Auth: `Authorization: Bearer {token}`; also sends `x-wcd-plugin`. Every method returns
`['ok'=>bool,'status'=>int,'data'=>mixed,'error'=>string]`.

Methods: `get_account`, `list_groups`, `create_group`, `get_group_urls`, `update_url_in_group`,
`update_urls_in_group`, `create_website`, `sync_urls` + `start_url_sync` (two-step), `take_screenshot`
(supports `batch_per_group`: one batch per group + a group->batch map in the response), `get_queues`,
`get_comparisons`, `get_batch`, `list_batches`, `update_comparison`.

Vocabulary: data-model terms (`manual`/`monitoring`, `source=manual`) in API calls; UI copy says
"On-Demand Check". Never expose AI model names (the API strips them server-side).

## Safe-Update Flow (confirm popup + unified in-card run, phased)

Entry: the WCD hero banner (`mainwp_before_overview_widgets`), shown at the top of the Operations
dashboard (bulk: all enabled sites) and an individual child-site overview (single site). We drive the
flow from our own button because no hook can trigger updates and the before-update hook can't await
async screenshots. The banner's Pages/Checks load progressively via the `banner_stats` AJAX so the
dashboard never blocks on WCD API calls.

**The run only covers sites with pending updates.** The `preflight` AJAX flags every scope site
with `has_updates` (additive field; read from MainWP's own DB upgrade columns) and computes ALL
aggregates (pages, checks, screenshots, credit math) over the eligible sites only; ineligible
sites skip the group-urls meta fetch entirely. The JS builds the run set from the flag, so
pre/post screenshots and the update trigger never touch a site without updates (no wasted check
credits). Sites without updates still appear in the preflight, greyed out with a "No updates"
badge. Failure semantics: when MainWP's DB layer is unavailable
(`WCD_MainWP_Update_Flow::updates_info_available()` is false) the update info is unknown and every
site counts as eligible (fail open: exactly the old unfiltered behavior); a site whose MainWP
website row cannot be resolved counts as "no updates" (fail closed: MainWP cannot update it
either). The same filter drives the numbers: the hero banner's Sites stat shows **"X / Y" +
"Sites with updates"** (plain total + "Site(s)" when unknown) and `banner_stats` aggregates
Pages/Checks over the eligible sites only. `pending_updates_by_site()` is the per-site source for
the banner + `banner_stats`; `pending_updates_count()` derives its total from it; `preflight()`
reads the same upgrade columns via `update_items_for_site()` (it needs the item list anyway).

Because the entry point is already "Run visual check & update", there is no with/without choice: the
button opens the **preflight popup, which is confirm-only**. The preflight renders the full run
overview from the enriched `preflight` AJAX payload: a Sites / Pages / Screenshots / Checks summary
strip, a **credit-coverage** bar (this run uses N checks · M of L available; "Enough credits" /
"N short" + "Upgrade plan" when short, which also disables Confirm), an expandable **"what gets
updated"** list (per-site core/plugins/themes items, read from MainWP's own DB upgrade columns), and
the **per-site URL list** as a lazy accordion: the `preflight` AJAX only fetches the per-site
selected counts from the group-urls `meta` (per_page=1, like `banner_stats`), and a site's URLs
(Desktop/Mobile chips) load on first expand via `get_site_urls` — so the popup opens fast even with
many websites (mirrors the webapp's lazy website accordion). The API still enforces credit limits
server-side (returns 402).

On confirm the popup closes and the **whole run plays out in ONE unified card rendered inline on the
page** (the `.wcd-run-host` container right below the launch band; no running modal). The browser is
the scheduler and orchestrates the phases as **barriers** so every site advances together:

1. **PRE**: ONE `take_pre` call with all check-enabled sites -> poll all batches aggregated until done.
2. **UPDATES**: `run_update` per site (sequential) -> `execute_update_site_*` (guarded, synchronous;
   suppresses the after-update hook so post is not double-fired). A per-site failure (e.g. offline) is
   tolerated; "nothing to update" is success-no-post.
3. **POST**: ONE `take_post` call with all sites -> poll aggregated -> `get comparisons` per batch.

`take_pre`/`take_post` accept `site_ids[]` (single `site_id` still supported) and deliberately do
NOT re-apply the pending-updates filter (stale MainWP sync data must never block an explicit run;
the resume + re-check paths reuse these endpoints). They start everything
server-side via **chunked batch-per-group take calls** (`TAKE_CHUNK` = 10 sites per API call, like
the webapp's bulk on-demand start): one API call creates one batch per group and returns the
group->batch map, instead of one take call per site. Fallbacks per chunk: an older API that ignores
`batch_per_group` returns one shared batch (all chunk sites map to it; never re-request, that would
take everything twice); a group missing from the map was skipped by the API (no credits / nothing
selected) and fails the request AFTER the successful batches were recorded in the run state, so the
run stays resumable.

The unified card shows a **PRE -> UPDATES -> POST -> DONE** timeline (a fill track + 4 nodes), three
side-by-side panels (**Pre-update screenshots** and **Post-update screenshots** each with an aggregate
**Queue / Processing / Done / Failed** grid, and **Installing updates** in the middle: processing only,
core · plugins · themes) plus a **per-site list** (site · `done/total` · status pill, no
per-site bars). When the run finishes the footer flips to **"All good" / "N pages to review"** with a
**Re-check** button (re-runs POST + compare only) and a **View results** link to the embedded Change
Detections page. The detailed comparison table is no longer rendered in the card; it lives on the
Change Detections page.

`poll()` accepts **multiple batches** (`batches[]`, single `batch` still supported): it fetches the
queues endpoint's pre-aggregated `meta.status_counts_by_batch` (one call for all batches) and sums
the per-batch buckets into one aggregate (feeds the Pre/Post panels) plus a `by_batch` breakdown
(feeds each site's `done/total` counter). A POST batch holds TWO queue rows per check (post
screenshot + comparison, spawned async per finished screenshot), so raw counts would double-count
the run; `batch_bucket()` therefore derives check-level counts from the per-batch `by_type`
breakdown (total = post rows, done = `compare.done`, processing = remainder, mirroring the webapp's
on-demand cards). POST-phase polls also send the run's PRE batches (`pre_batches[]`): their failed
count is shifted from processing to failed, because a check whose pre screenshot failed never gets
a comparison (`run_resume_post` returns them for the resume path). Dynamic widths (timeline fill,
credit bar) use the `.wcd-w-*` step utilities, never inline styles.

**After-update hook safety-net** (`mainwp_after_*`): for updates started outside our card
(cron/native). Deduped per site via a short transient; suppressed while the card flow owns the run.
Post-only (no reliable pre in a synchronous before-hook).

**Run-state persistence + resume**: because the browser orchestrates the run, every phase transition
is also persisted server-side in the `wcd_mainwp_active_run` option (`WCD_MainWP_Update_Flow`
run-state section): `run_start` (sites + per-site name/checks), `take_pre`/`take_post` record their
batches, `run_update` records completed sites, `poll` bumps the activity timestamp. Once every run
site has a post batch the state clears (the rest finishes server-side). If a page later loads while
a run has installed updates but misses post batches AND has been inactive for 10+ minutes
(RUN_STALE_AFTER), the JS (`checkResume`) shows a warning message in the run host with two actions:
**Take post-update screenshots** (`run_resume_post` dispatches the missing post batches server-side,
clears the state, and the unified card resumes at the POST phase) or **Discard** (`run_discard`).
So a closed tab between updates and post screenshots no longer loses the run.

Closing the page does NOT abort the flow (the AJAX chains/updates keep running server-side);
`beforeunload` warns before a tab close while a run is active, the card's footer says to keep the
tab open, and the card's dismiss (X) is disabled until the run finishes.

## Entry points

- **Hero banner** (`templates/entry-banner.php`, shared `renderScopedBanner()`): the safe-update entry,
  rendered on the Operations dashboard (bulk), an individual child-site overview (single site), AND
  above the native Updates page's plugin list (`mainwp_updates_before_plugin_updates`, only when
  updates are available). The CTA is disabled when there are no pending updates (the per-site tab
  button too); the unknown count (MainWP DB unavailable) leaves it enabled.

## Visual Checks overview

`class-wcd-mainwp-runs-view.php` + `templates/runs-view.php` + `assets/css/wcd-runs.css`: a
dashboard-wide list of On-Demand runs (batches). Registered as a Sites subpage (slug
`WcdVisualChecks`, `sitetab => false`, `menu_hidden => true`) and linked from the left menu's
**Monitoring** category group via `mainwp_menu_extensions_left_menu` (bootstrap). The template wraps
itself in the native MainWP chrome (`mainwp_pageheader_sites` / `mainwp_pagefooter_sites`).

The Visual Checks area has TWO tabs: **Visual Checks** (runs) and **Settings** (slug
`WcdVisualChecksSettings`, registered by `WCD_MainWP_Site_Settings`, template
`sites-settings-page.php`) with the per-site enable toggles + URL selection that used to live on
the extension settings page. The visible switcher is a native Fomantic **`ui top attached tabular
menu`** (`WCD_MainWP_Runs_View::render_tabs()`, the same element MainWP's own modules use for
in-page tabs) with the content in a `bottom attached segment`. NOTE: MainWP 6's Sites
page-navigation column (`#mainwp-page-navigation-wrapper`) is `display:none` on non-per-site pages
(only `.mainwp-individual-site-view` shows it), so it CANNOT serve as the switcher here;
`WCD_MainWP_Runs_View::filter_navigation_items` (`mainwp_manage_sites_navigation_items`) still
runs to keep our entries out of the per-site navigation, where the column IS visible. The
extension page (MainWP > Extensions > WebChange Detector) keeps the account-level settings (API
token, auto-enable, credits card) and links to the Settings tab.

The **source is fixed to `manual` server-side** (`build_api_filters`): only On-Demand Checks appear;
the account's monitoring/auto-update runs made elsewhere (webapp) are out of scope, so there is no
type filter and no per-row "On-Demand Check" label. Filter bar = native Fomantic `ui mini form`
with `ui selection (multiple) dropdown`s (period presets + custom range, status, website, visual) +
an explicit **Filter** button (MainWP's native apply pattern: control changes only take effect on
Filter/Reset; pagination + the Runs/List view toggle reuse the applied snapshot) all on one flex
line; dropdowns are initialized by `wcd-mainwp.js` via MainWP's own Fomantic JS.
The batch list renders as MainWP's accordion-table pattern (one `tbody` per run: `tr.title` with
caret / websites / status labels / tooltip timestamp / AI summary + a hidden `tr` whose comparisons
lazy-load on first open). Data via `WCD_MainWP_API::list_batches` + `get_comparisons`, scoped to the
account (the website filter narrows to a managed site's manual + auto groups). AJAX `runs_render`
(list) + `runs_comparisons` (drill-in) return rendered HTML fragments; JS is guarded by `#wcd-runs`.
Timestamps render with `wp_date()` (dashboard timezone), relative labels with native
`data-tooltip` attributes. The CSS is layout glue only: surfaces/colors come from Fomantic + the
MainWP theme, so light/dark both work.

## Security

- Every AJAX handler calls `check_ajax_referer( self::NONCE, 'nonce', false )` **inline** (nonce
  `wcd_mainwp_ajax`) and passes the result to `self::verify()`, which also enforces
  `current_user_can('manage_options')` and sends a 403 on failure. The check is inline (not hidden in a
  helper) so static analysis sees it in each handler's scope — no `phpcs:ignore` is used anywhere.
- The two request-reading helpers (`site_id()`, `scope_site_ids()`) re-check the nonce before touching
  `$_POST`, so the read genuinely cannot happen without a valid nonce (defense in depth). Handlers that
  read `$_POST` directly (e.g. `take_screenshot()`'s `site_ids[]`, `poll()`'s batches) do so only after
  their inline `check_ajax_referer` call.
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
