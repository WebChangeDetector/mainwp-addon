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
│   ├── class-wcd-mainwp-bootstrap.php      # Wires MainWP hooks, enqueues assets, injects entry points, extension-page tab router (render_admin_page + tab_url)
│   ├── class-wcd-mainwp-options.php        # Network-aware option/transient storage
│   ├── class-wcd-mainwp-api.php            # WCD API v2 HTTP client (normalized results)
│   ├── class-wcd-mainwp-site-map.php       # MainWP site <-> WCD website/group mapping + domain normalization
│   ├── class-wcd-mainwp-site-settings.php  # API token, verification, account cache, settings page, auto-enable on add
│   ├── class-wcd-mainwp-url-sync.php       # Fetch child URLs (posts hook + pages guarded) -> two-step sync
│   ├── class-wcd-mainwp-cache-purge.php    # Child-site cache purge via mainwp_fetchurlauthed + cache_purge_action
│   ├── class-wcd-mainwp-update-flow.php    # Update trigger (guarded) + after-update hook safety-net + pending-update counts
│   ├── class-wcd-mainwp-ajax.php           # AJAX endpoints (settings + safe-update orchestration + runs overview)
│   ├── class-wcd-mainwp-runs-view.php      # Renders the Run + Checks tab bodies, the extension-page tab switcher (render_tabs), filters + batch/list rendering
│   └── class-wcd-mainwp-widget.php         # The Safe Update dashboard widget (entry point) + its Visual Checks "Run" tab panel
├── templates/
│   ├── admin-page.php             # Extension page shell: resolves ?tab=, renders the tab switcher + the active tab body
│   ├── settings-page.php          # Account tab body: token + auto-enable toggle + account/credits card
│   ├── site-tab.php               # Per-site tab: status + safe-update entry (disabled when no updates)
│   ├── widget-safe-update.php     # Safe-update dashboard widget body (native MainWP chrome) -> safe-update flow
│   ├── entry-banner.php           # Hero banner (native Updates page only) -> safe-update flow
│   ├── runs-view.php              # Checks tab body (Fomantic filter bar; chrome + tabs from the page shell)
│   ├── run-view.php               # Run tab body -> safe-update widget body (full-page panel)
│   └── sites-settings-page.php    # Settings tab body: enable sites + URL selection
├── assets/css/wcd-mainwp.css      # All component styles (+ .wcd-w-* width steps + the Checks-tab styles scoped to .wcd-runs, no inline CSS)
└── assets/js/wcd-mainwp.js        # Settings + safe-update orchestrator + results + runs overview
```

## MainWP Integration Points (hooks)

See `.docs/MAINWP-HOOKS.md` for the full table. Key ones:

| Hook | Purpose |
|------|---------|
| `mainwp_getextensions` | Register extension + the extension page (Run/Checks/Settings/Account tabs) + icon |
| `mainwp_getsubpages_sites` | Per-site tab `WcdVisualRegressionTesting` only (menu_hidden). Run/Checks/Settings/Account are tabs on the extension page, not Sites subpages |
| `mainwp_getmetaboxes` | The Safe Update dashboard widget `wcd-safe-update-widget` (safe-update entry point, full-width, draggable/hideable). Account data (plan, credits, renewal, active sites) lives on the extension page's Account tab, not in a dashboard widget |
| `mainwp_widgets_screen_options` | Adds the Safe Update widget (`advanced-wcd-safe-update-widget`) to MainWP's "Page Settings" show/hide list so the user can hide it (default shown) |
| `mainwp_getdbsites` / `mainwp_extension_enabled_check` | List managed sites + the extension key |
| `mainwp_site_synced` | After a child syncs -> sync its URLs to the WCD group |
| `mainwp_added_new_site` | Auto-enable a newly added child site for WCD (opt-out via the settings toggle) |
| `mainwp_getallposts` | Fetch a child's posts (pages use the guarded call) |
| `mainwp_updates_before_plugin_updates` | Render the WCD hero banner above the native Updates page's plugin list (only when updates are available; the Updates page is not a widget grid, so a banner is the closest fit there) |
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
- Reset connection: the Account tab "Reset connection" button (`admin_post_wcd_reset_token`, own
  nonce) calls `WCD_MainWP_Site_Settings::reset_connection()`, which clears the token, the account
  cache + verify/error transients, the whole site map (`reset_all()`) and any in-flight run
  (`clear_run()`). It is **local only**: WCD websites/groups/comparisons are left intact, so
  re-entering the same token re-links to them idempotently (`find_existing_website`). The auto-enable
  preference is intentionally kept (it is a UI setting, not account data).

## API Communication

Client: `WCD_MainWP_API` (`includes/class-wcd-mainwp-api.php`), all static. Base URL
`https://api.webchangedetector.com/api/v2`, overridable via the `WCD_API_URL` **or** `WCD_API_URL_V2`
constant. Auth: `Authorization: Bearer {token}`; also sends `x-wcd-plugin`. Every method returns
`['ok'=>bool,'status'=>int,'data'=>mixed,'error'=>string]`.

Methods: `get_account`, `list_groups`, `create_group`, `get_group_urls`, `update_url_in_group`,
`update_urls_in_group`, `select_all_urls_in_group` (toggles one device for ALL group urls in one
`PUT /groups/{id}/urls/select-all` call, a single SQL UPDATE server-side — used by the "select all"
toggles so large sites stay fast), `create_website`, `sync_urls` + `start_url_sync` (two-step), `take_screenshot`
(supports `batch_per_group`: one batch per group + a group->batch map in the response), `get_queues`,
`get_comparisons`, `get_batch`, `list_batches`, `update_comparison`.

Vocabulary: data-model terms (`manual`/`monitoring`, `source=manual`) in API calls; UI copy says
"On-Demand Check". Never expose AI model names (the API strips them server-side).

## Safe-Update Flow (confirm popup + unified in-card run, phased)

Entry: the **"Safe Update" dashboard widget** (`mainwp_getmetaboxes`, callback
`WCD_MainWP_Widget::render_safe_update_metabox`), a draggable/hideable full-width metabox on the
Operations dashboard (bulk: all enabled sites) and an individual child-site overview (single site).
The same flow also launches from the hero banner above the native Updates page's plugin list
(`mainwp_updates_before_plugin_updates`; the Updates page is not a widget grid). We drive the flow
from our own button because no hook can trigger updates and the before-update hook can't await async
screenshots. Both surfaces share the same JS contract (`.wcd-safe-update` CTA, `.wcd-run-host`,
`[data-stats-scope]` + `[data-role=pages|checks]`); their Pages/Checks load progressively via the
`banner_stats` AJAX so the dashboard never blocks on WCD API calls. Scope (bulk vs. single site) is
resolved by `WCD_MainWP_Bootstrap::resolve_banner_scope()`, shared by the widget and the banner.

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

Because the entry point is already "Run visual checks & updates", there is no with/without choice: the
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

On confirm the preflight is replaced **in the same modal** by the **unified run card**: the run plays
out **in a popup** (the run card is mounted into the Fomantic modal body, not inline in the page).
While the run is in flight the popup cannot be closed (the close is vetoed in `mayCloseModal` and a
**keep-open warning** is shown), so the orchestrating tab is not closed by accident; navigating away
cannot be prevented but is recovered by the resume path. The native Fomantic close icon is hidden in
the run popup at all times; the card's OWN dismiss is the single close and only appears once the run
finishes (so the done modal never shows two close icons). A **"Updates running" button** next to the
widget heading (`.wcd-run-reopen-slot`; on the Updates banner it falls back into the `.wcd-run-host`)
**reopens** the popup, which is the only way back in, so a resumed run (which does NOT auto-open) is
reached through it. When the run finishes the warning clears, the card's own dismiss appears (clears
the run), and the reopen button shows the verdict. The
browser is the scheduler and orchestrates the phases as **barriers** so every site advances together:

1. **PRE**: ONE `take_pre` call with all check-enabled sites -> **purge each site's child cache**
   (synchronous, best effort) -> dispatch the pre batches -> poll all batches aggregated until done.
2. **UPDATES**: `run_update` per site (sequential) -> `execute_update_site_*` (guarded, synchronous;
   suppresses the after-update hook so post is not double-fired) -> **purge the site's child cache**
   when anything was updated. A per-site failure (e.g. offline) is tolerated; "nothing to update" is
   success-no-post.
3. **POST**: ONE `take_post` call with all sites -> poll aggregated -> `get comparisons` per batch.

**Cache purging** (`WCD_MainWP_Cache_Purge`): both purges go through the documented
`mainwp_fetchurlauthed` filter with the `cache_purge_action` child callable (ships with MainWP Child
core; auto-detects 20+ cache plugins — see MAINWP-HOOKS.md). Purging before PRE and after the
updates means pre and post screenshots both capture freshly generated pages: a stale cached pre
diffed against a fresh post would produce false positives, and a cached post would hide the update's
changes entirely. Failures are WP_DEBUG-logged and never block the run; the resume path re-purges
before dispatching late post batches (belt and braces).

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
Post-only (no reliable pre in a synchronous before-hook). Purges the site's child cache (behind the
same dedupe) right before enqueuing the post screenshots.

**Run-state persistence + auto-resume (any phase)**: because the browser orchestrates the run, every
phase transition is persisted server-side in the `wcd_mainwp_active_run` option
(`WCD_MainWP_Update_Flow` run-state section): `run_start` (sites + per-site name/checks),
`take_pre`/`take_post` record their batches, `run_update` records completed sites, `poll` bumps the
activity timestamp. In addition the driving tab sends a short, **unthrottled heartbeat**
(`run_heartbeat`, ~7s, carrying a per-tab **driver id** kept in `sessionStorage`) so even a
multi-minute synchronous update never makes the run look abandoned, and the run records which tab
drives it. On any page that hosts the entry point, `checkResume` calls `run_status` on load and takes
over **instantly** when the run's `driver` matches this tab's id (a same-tab reload or navigation
reclaiming its OWN run; no other tab can be driving it) or when the heartbeat has been silent for
`RESUME_AFTER` (20s). The run is then **resumed in the background** (it continues at its persisted
`phase` but the popup does NOT auto-open; the user opens it via the "Updates running" button: PRE
re-takes only the still-missing pre screenshots then polls; UPDATES finishes the not-yet-updated sites;
POST uses `run_resume_post`, which re-purges and dispatches the missing post batches). If neither holds
(a foreign, still-fresh driver, so another tab is likely driving it), the page shows the "Updates
running" button + locks the CTA **immediately** so the user cannot start a second run, and re-checks
after a short window (`RESUME_RECHECK`) until it may take over; clicking the button takes over there and
then. Once every run site has a post batch the state clears (the rest finishes server-side); a
long-idle run that never took a pre screenshot and never installed anything is dropped as leftover
(`RUN_STALE_AFTER`). So navigating away from the Operations page (or a closed tab) no longer loses the
run.

While a run is in flight the popup cannot be closed (close vetoed + keep-open warning), so it stays the
visible scheduler. Navigating away cannot be prevented, but is recoverable (the run resumes on return,
opened via the "Updates running" button); `beforeunload` still warns before a tab close while a run is
active because navigating away mid-UPDATES can interrupt a non-transactional update in flight.

## Entry points

- **Safe Update widget** (`templates/widget-safe-update.php`, callback
  `WCD_MainWP_Widget::render_safe_update_metabox`): the primary safe-update entry, a native MainWP
  dashboard widget registered via `mainwp_getmetaboxes` with `layout => [0,20,12,10]` (full width, y=20
  so it defaults directly below MainWP's Updates Overview widget, which occupies `[0,0,12,20]`; on
  surfaces without that widget MainWP's grid compacts it upward). Its markup mirrors MainWP's own
  Recent Activity widget so the footer actions stay visible at ANY widget height: a **title-only**
  `mainwp-widget-header` (a flex row whose `.wcd-run-reopen-slot` holds the "Updates running" reopen
  button while a run popup is closed), then the three `mainwp-cards` stats inside the flex-grow
  `mainwp-scrolly-overflow` middle (the stats sit in the `[data-stats-scope]` container the
  `banner_stats` AJAX reads), then a native two-column `mainwp-widget-footer` with the Settings link + the green
  Run CTA (`ui green button`, matching MainWP's own update buttons). Keeping the bulky cards out of the
  fixed header is what lets the scroll area shrink/scroll (and the footer stay pinned) when the widget
  is resized short, instead of the footer being clipped by the `overflow:hidden` widget. It is
  **draggable** (the `handle-drag`
  title) and **hideable** (registered in `mainwp_widgets_screen_options`). MainWP renders our
  metaboxes on both the Operations dashboard (bulk) and the individual child-site overview (single
  site, scope-aware via `get_current_wpid()`). The no-token state reuses
  `templates/entry-banner-no-token.php`.
- **Run tab** (`templates/run-view.php`, callback `WCD_MainWP_Widget::render_safe_update_panel`): the
  same widget body rendered as a full-page panel on the extension page's **Run** tab (the default tab
  once a token is configured). On this account-wide page `get_current_wpid()` is 0, so the
  scope resolves to **bulk** over all enabled sites. The panel method owns the branching the metabox
  chrome does not: no token -> an info notice linking to the Account tab; no enabled sites ->
  an info notice linking to the Settings tab; otherwise the shared `widget-safe-update.php` body. It
  is wrapped in a `ui bottom attached segment` (not a `.mainwp-widget`), so MainWP's
  `.mainwp-widget`-scoped paddings do not apply; a small `.wcd-run-tab` rule restores the spacing.
- **Hero banner** (`templates/entry-banner.php`, shared `render_scoped_banner()`): the same flow above
  the native Updates page's plugin list (`mainwp_updates_before_plugin_updates`, only when updates are
  available). The Updates page is not a widget grid, so a banner is the closest fit there.
- The CTA is disabled when there are no pending updates (the per-site tab button too); the unknown
  count (MainWP DB unavailable) leaves it enabled.

## Extension page tabs (Run / Checks / Settings / Account)

The whole UI lives on the add-on's own **extension page** (MainWP > Extensions > WebChange Detector).
`templates/admin-page.php` is the shell: it resolves the active tab from the `?tab=` query arg
(`run` / `checks` / `settings` / `account`; default = **Account** when no token is configured, else
**Run**), renders the MainWP extension chrome once (`mainwp_pageheader_extensions` /
`mainwp_pagefooter_extensions`), draws the tab switcher (`WCD_MainWP_Runs_View::render_tabs()`), and
dispatches to the active tab body. Tabs switch via a **full page reload** (`?tab=...`); URLs are built
by `WCD_MainWP_Bootstrap::tab_url()`. The full reload keeps the Run-tab safe-update resume/heartbeat
contract working on a fresh load.

The four tab bodies:
- **Run** (`render_run_page` -> `run-view.php`): the safe-update entry-point panel (bulk scope).
- **Checks** (`render_page` -> `runs-view.php`): the On-Demand runs list (see below).
- **Settings** (`WCD_MainWP_Site_Settings::render_sites_settings_page` -> `sites-settings-page.php`):
  per-site enable toggles + URL selection.
- **Account** (`WCD_MainWP_Site_Settings::render_settings_form` -> `settings-page.php`): API token,
  auto-enable toggle, plan/credits card. Saving the token redirects back to this tab.

The switcher is MainWP's **native sub-navigation bar** rendered as a Fomantic
**`ui labeled icon inverted menu mainwp-sub-submenu`** (the exact class list the SeoPress MainWP
add-on uses), so each tab shows its **icon over the label** (Run / Checks / Settings / Account). The
**`inverted` class is mandatory**: MainWP's theme scopes the readable white labels/icons and the
accent-colored active-tab highlight to `.ui.inverted.menu.mainwp-sub-submenu`, whereas the bare
`.mainwp-sub-submenu` only paints the dark background (dropping `inverted` gives dark-on-dark,
unreadable labels and no visible active state). `labeled icon` stacks the icon over the label
natively, so the switcher carries **no custom tab CSS** and none should be added (it would fight the
theme). Because the bar is free-standing (not `top attached`), each tab body is a plain
**`ui padded segment`** (not `bottom attached`). There is
**no** left-menu entry and there are **no** account-wide Sites subpages: everything is reached
through the Extensions menu. (Only the per-site `WcdVisualRegressionTesting` tab remains a Sites
subpage; it links back to the Account/Settings tabs.)

### Checks tab (runs list)

`class-wcd-mainwp-runs-view.php` + `templates/runs-view.php` + the `.wcd-runs`-scoped styles in
`assets/css/wcd-mainwp.css`: the **Checks** tab is a dashboard-wide list of On-Demand runs (batches).

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
- `admin_post_wcd_save_settings` and `admin_post_wcd_reset_token` each verify their own nonce + capability.
- Input sanitized; output escaped; URLs via `esc_url`.

## Coding standards

The add-on ships a `phpcs.xml.dist` (WordPress standard, text domain `webchangedetector`, prefixes
`WCD_MainWP` / `webchangedetector`) and a `composer.json` with the WPCS dev dependency. Run
`composer install` then `composer lint` (or `composer lint:fix`). The tree is clean: **0 phpcs errors/warnings**;
the only inline ignore is the documented WP_DEBUG `error_log` in the cache-purge logger
(`class-wcd-mainwp-cache-purge.php`). All identifiers are snake_case; class files follow `class-{name}.php`.

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

## Release / Build

The version lives in **four** places that must always match (the build script enforces this):
the `Version:` plugin header and the `WCD_MAINWP_VERSION` constant (both in
`webchangedetector-for-mainwp.php`), the `Stable tag:` in `readme.txt`, and the latest
`= X.Y.Z =` changelog entry in `readme.txt`. NEVER change version numbers without asking first.

`scripts/build-release.sh` (modeled on `wcd-plugin/scripts/deploy-to-wp-svn.sh`) validates the
versions and the Git status, then rsyncs a clean copy (excludes from `.distignore` + junk like
`.DS_Store`) to `/Users/mike/htdocs/wcd/wp-repo-mainwp/trunk/` (same layout as `wp-repo-plugin`:
trunk/tags/assets/branches). It then asks interactively whether to create the upload zip
(`wp-repo-mainwp/webchangedetector-for-mainwp-X.Y.Z.zip`, top-level folder = plugin slug),
whether to create the Git tag `vX.Y.Z`, and whether to deploy to WordPress.org SVN.

The plugin is approved and published; the SVN repo is
`https://plugins.svn.wordpress.org/webchangedetector-for-mainwp/`. The optional **SVN deploy**
step (default No, so a plain local build is unaffected when declined) checks the repo out in
place as `wp-repo-mainwp` if `.svn` is missing (`svn checkout --force`, so svn adopts the
already-built `trunk/` and the scaffold dirs instead of tree-conflicting on them), runs
`svn update`, stages adds/deletes scoped to `trunk/` (so the release zip and the empty
tags/branches/assets scaffold at the repo root are never committed), copies `trunk` to
`tags/X.Y.Z` (skipped if the tag already exists), shows the status, and commits
`Deploying version X.Y.Z` after a final confirmation. Auth: the wp.org
username is prompted (or passed via `--svn-user <name>`); under `--force` with no `--svn-user`
SVN's cached credentials are used. The zip can still be uploaded manually as a fallback.

**Claude Code restrictions:** NEVER run `scripts/build-release.sh` for a real build or SVN
deploy (deploy blacklist; `--dry-run` for verification is OK when asked). NEVER create or push
Git tags or run `svn commit`.

## Related Documentation

- Hook reference + principle: `.docs/MAINWP-HOOKS.md`
- API (server side): `api/.docs/API.md`
- Customer WP plugin (shared API vocabulary): `wcd-plugin/.claude/.docs/PLUGIN.md`
