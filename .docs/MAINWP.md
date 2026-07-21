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
│   ├── class-wcd-mainwp-ajax.php           # AJAX endpoints (settings + safe-update orchestration + runs overview + flows)
│   ├── class-wcd-mainwp-runs-view.php      # Renders the Run + Checks tab bodies, the extension-page tab switcher (render_tabs), filters + batch/list rendering
│   ├── class-wcd-mainwp-interaction-flows.php # Interaction Flows fragments for the per-site tab (list, steps, runs, run detail)
│   └── class-wcd-mainwp-widget.php         # The Safe Update dashboard widget (entry point) + its Visual Checks "Run" tab panel
├── templates/
│   ├── admin-page.php             # Extension page shell: resolves ?tab=, renders the tab switcher + the active tab body
│   ├── settings-page.php          # Account tab body: token + auto-enable toggle + account/credits card
│   ├── site-tab.php               # Per-site tab: status + safe-update entry (disabled when no updates) + the Interaction Flows section
│   ├── widget-safe-update.php     # Safe-update dashboard widget body (native MainWP chrome) -> safe-update flow
│   ├── entry-banner.php           # Hero banner (native Updates page only) -> safe-update flow
│   ├── runs-view.php              # Checks tab body (Fomantic filter bar; chrome + tabs from the page shell)
│   ├── run-view.php               # Run tab body -> safe-update widget body (full-page panel)
│   ├── sites-settings-page.php    # Settings tab body: enable sites + URL selection + per-site Settings button
│   └── site-settings-modal.php    # Per-site On-Demand check settings modal (one reused Fomantic modal)
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
  auto_group_uuid, domain, enabled, screenshot_region } }`. The `domain` is normalized once (scheme +
  trailing slash stripped, www + path kept) and reused verbatim for every WCD call (the API resolves
  websites by exact domain match). `screenshot_region` is the user's per-site choice (`us`, `eu` or
  `auto`; default `auto`), stored so it survives re-provisioning; the value sent on group create and
  via the `save_site_settings` AJAX action (the per-site On-Demand settings modal).
- Active-run state: **`wcd_mainwp_active_run`** = the tracked safe-update run's STRUCTURAL state
  (sites, per-site pre/post batches, updated sites; the `phase` field is legacy/informational),
  used for the resume flow; cleared once every run site has a post batch recorded. Only the
  JS-serialized mutating endpoints write it (single writer). **`wcd_mainwp_run_activity`** =
  the liveness signal ({ last_activity, driver }), written ONLY by the heartbeat and the poll's
  throttled touch, so activity bumps can never clobber a structural record.
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

Methods: `get_account`, `list_groups`, `create_group`, `update_group`, `get_group` (single group,
on-demand settings prefill; the password is never returned, `has_basic_auth` signals it is set),
`get_group_urls`, `update_url_in_group`,
`update_urls_in_group`, `select_all_urls_in_group` (toggles one device for ALL group urls in one
`PUT /groups/{id}/urls/select-all` call, a single SQL UPDATE server-side — used by the "select all"
toggles so large sites stay fast), `create_website`, `sync_urls` + `start_url_sync` (two-step), `take_screenshot`
(supports `batch_per_group`: one batch per group + a group->batch map in the response), `get_queues`,
`get_comparisons`, `get_batch`, `list_batches`, `update_comparison`, and the Interaction Flows set:
`list_flows` (scoped by `website_id`), `get_flow`, `update_flow_toggles` (allow-list
`FLOW_TOGGLE_FIELDS`), `list_flow_runs`, `get_flow_run` (the run-detail poll endpoint).

Vocabulary: data-model terms (`manual`/`monitoring`, `source=manual`) in API calls; UI copy says
"On-Demand Check". Never expose AI model names (the API strips them server-side).

## Safe-Update Flow (confirm popup + unified in-card run, per-site pipeline)

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
browser is the scheduler, but every site runs its OWN pipeline
(`pre -> update_queued -> updating -> post_dispatch -> post -> done | failed`), so sites advance
independently and results stream in per site:

1. **PRE (bundled)**: ONE `take_pre` call with all check-enabled sites -> **purge each site's
   child cache** (synchronous, best effort) -> dispatch the pre batches. From here each site is
   on its own: as soon as ITS pre batch completes, the site enters the update FIFO.
2. **UPDATES (single-file FIFO)**: max ONE `run_update` in flight at any time, ordered by pre
   completion (the synchronous multi-minute call binds a dashboard PHP worker per site, and
   MainWP's update abilities are not documented for parallel calls). `run_update` ->
   `execute_update_site_*` (guarded, synchronous; suppresses the after-update hook so post is not
   double-fired) -> **purge the site's child cache** when anything was updated. A per-site failure
   (e.g. offline) is tolerated; "nothing to update" is success-no-post. Sites without selected
   checks skip the screenshot phases and only pass through this FIFO.
3. **POST (per site)**: right after a site's update returns, ITS `take_post {site_ids:[id]}` is
   dispatched and its post batch joins the poll set; the comparisons are created server-side as
   the post screenshots finish. When the site's post batch completes, its comparisons are fetched
   (`results {batch}`) and its row settles (clean / N to review), while other sites may still be
   in PRE or UPDATES.

All mutating AJAX (`take_pre` / `run_update` / `take_post` / `run_resume_post`) is funneled
through ONE shared promise lane in the JS (mutation lane), so the structural run option only ever
has a single writer; read-only calls (`poll`, `results`, heartbeat) run freely beside it.

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

The unified card shows a **PRE -> UPDATES -> POST -> DONE** timeline (a fill track + 4 nodes; a
node is done only when EVERY site passed that phase, the earliest per-site phase is the active
node, and the fill is the MEAN per-site progress), three side-by-side aggregate panels
(**Pre-update screenshots** and **Post-update screenshots** each summing every site's
**Queue / Processing / Done / Failed** bucket, and **Installing updates** in the middle counting
the pending update items of the queued + updating sites) plus the **per-site rows**: each row has
a `done/total` counter, a **phase pill** (Capturing pre / Waiting for update / Updating /
Capturing post / Comparing / Done / Failed, then the verdict Clean / "N to review") and its OWN
**Queue / Processing / Done / Failed mini-grid** (webapp-style), fed from the poll's `by_batch`
breakdown; the site's phase decides whether the pre or the post bucket is shown. When the run
finishes the footer flips to **"All good" / "N pages to review"** with a **Re-check** button
(re-runs POST + compare only, through the same pipeline) and a **View results** link to the
embedded Change Detections page. The detailed comparison table is no longer rendered in the card;
it lives on the Change Detections page.

**ONE bundled poll loop** drives the whole run (never per-site polling): every ~3s tick sends ALL
currently interesting batches in one `poll` call: the pre batches of sites still in PRE and the
post batches of sites in POST (as `batches[]`, single `batch` still supported), plus the already
completed pre batches as `pre_batches[]`. `poll()` fetches the queues endpoint's pre-aggregated
`meta.status_counts_by_batch` (one API call for everything) and returns the summed aggregate plus
a `by_batch` breakdown that also includes the `pre_batches` (raw buckets); the JS keeps the
site->batch maps and computes per site: complete = `queue+processing === 0 && done+failed > 0`
(same rule as the global `complete`) and the per-site pre-fail shift
`min(preBucket.failed, postBucket.processing)` from processing to failed, because a check whose
pre screenshot failed never gets a comparison (the server applies the same shift to the summed
aggregate; `run_resume_post` returns the pre batches for the resume path). A POST batch holds TWO
queue rows per check (post screenshot + comparison, spawned async per finished screenshot), so raw
counts would double-count the run; `batch_bucket()` therefore derives check-level counts from the
per-batch `by_type` breakdown (total = post rows, done = `compare.done`, processing = remainder,
mirroring the webapp's on-demand cards). The stall counter only advances while nothing progresses
(no finished-count change, no per-site transition). Dynamic widths (timeline fill, credit bar) use
the `.wcd-w-*` step utilities, never inline styles.

**After-update hook safety-net** (`mainwp_after_*`): for updates started outside our card
(cron/native). Deduped per site via a short transient; suppressed while the card flow owns the run.
Post-only (no reliable pre in a synchronous before-hook). Purges the site's child cache (behind the
same dedupe) right before enqueuing the post screenshots.

**Run-state persistence + auto-resume (per site)**: because the browser orchestrates the run, every
per-site transition is persisted server-side (`WCD_MainWP_Update_Flow` run-state section) across
TWO options with strictly separate writers. The **structural** option `wcd_mainwp_active_run` is
only written by the mutating endpoints, which the JS serializes through the mutation lane:
`run_start` (sites + per-site name/checks), `take_pre`/`take_post` record their batches per site,
`run_update` records the updated site, `run_resume_post` records the batches it dispatches. The
**activity** option `wcd_mainwp_run_activity` ({ last_activity, driver }) is only written by
`run_heartbeat` (unthrottled, ~7s, carrying a per-tab **driver id** kept in `sessionStorage`) and
by `poll`/`run_update`'s throttled touch, so even a multi-minute synchronous update never makes
the run look abandoned AND an activity bump can never clobber a concurrent structural record.
`idle_seconds()` prefers the activity option and falls back to the structural `last_activity`
(written once at `run_start`) for a legacy in-flight run. On any page that hosts the entry point,
`checkResume` calls `run_status` on load and takes over **instantly** when the run's `driver`
matches this tab's id (a same-tab reload or navigation reclaiming its OWN run; no other tab can be
driving it) or when the heartbeat has been silent for `RESUME_AFTER` (20s). The run is then
**resumed in the background** (the popup does NOT auto-open; the user opens it via the "Updates
running" button) with the SAME per-site state machine, each site's seed derived from ITS persisted
fields (the legacy global `phase` is ignored): a recorded post batch -> poll it; in
`updated_sites` -> ONE bundled `run_resume_post {site_ids}` re-purges and dispatches the missing
post batches; a recorded pre batch -> poll it (NEVER re-take, credit safety); nothing recorded ->
ONE bundled `take_pre`. If neither takeover condition holds (a foreign, still-fresh driver, so
another tab is likely driving it), the page shows the "Updates running" button + locks the CTA
**immediately** so the user cannot start a second run, and re-checks after a short window
(`RESUME_RECHECK`) until it may take over; clicking the button takes over there and then. End of
tracking: once every run site has a post batch the structural state self-clears via
`record_post_batch` (the rest finishes server-side), and a run that FINISHES with a failed site
(which by definition never gets its post batch) is discarded by `finishRun` instead (best effort;
the user has seen the verdict, so it must not auto-resume forever). Only an ABORTED run keeps its
state (failRun/navigation), so the resume path can retry it; a long-idle run that never took a
pre screenshot and never installed anything is dropped as leftover (`RUN_STALE_AFTER`). So
navigating away from the Operations page (or a closed tab) no longer loses the run.

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
  per-site enable toggles + URL selection + a per-site **Settings** button (disabled until the site is
  enabled; mirrors the Configure URLs button). The button opens the per-site **On-Demand check settings
  modal** (`templates/site-settings-modal.php`), a single server-rendered native Fomantic `ui modal`
  reused for every site (the JS fills its values on open). It mirrors the webapp's On-Demand (manual)
  website settings, scoped to the fields that apply to MainWP sites:
  - Top: **Screenshot region** (`screenshot_region` auto/us/eu), **Activate newly synced URLs by
    default** Desktop (`default_desktop`) + Mobile (`default_mobile`), **Difference threshold**
    (`threshold`).
  - Advanced (a collapsible Fomantic `ui accordion`): **Basic Auth** username (`basic_auth_user`) +
    password (`basic_auth_password`), **Static IP proxy** toggle (`proxy_type`), **Screenshot delay**
    (`screenshot_delay`, 7 to 60 seconds, empty allowed), **CSS injection** (`css`), **JS injection**
    (`js`).
  - Excluded on purpose: website name, all monitoring/schedule fields, all WP-plugin settings.

  The modal loads via the `get_site_settings` AJAX action (one `GET /groups/{id}` on the site's manual
  group) and saves via `save_site_settings`. `save_site_settings` writes the capture settings to the
  **manual** group via `update_group`, writes `screenshot_region` to **both** groups (manual + auto)
  and persists it via `WCD_MainWP_Site_Map::set_region()`. The API owns sibling-sync and resolving
  `auto` to a concrete region, so the addon does not loop or poll. Per-field contract (the API writes
  a field only when present in the request): `proxy_type` is `static` when on / `none` when off (never
  `''`); `screenshot_delay` is clamped 7 to 60, or the key is omitted when the field is empty;
  `basic_auth_password` is **present => write it** (non-empty SETs, empty string CLEARs) or **absent
  => leave unchanged** (there is no `basic_auth_password_action` field on the API). The password is
  never returned by the API; the modal uses the `has_basic_auth` boolean and the **webapp dots
  convention**: when a password is stored the field is prefilled with a bullet sentinel (`••••••••`)
  plus the hint "A password is stored. Clear this field to remove it, or type a new one to replace
  it." The sentinel logic lives **only in the JS**, which sends `basic_auth_password` only when it
  wants a change (unchanged dots => key omitted; cleared field => `''`; a new value => that value),
  keeping the endpoint contract-simple. `css`/`js` are stored verbatim (only unslashed). The region
  value is sanitized against `WCD_MainWP_Site_Map::REGIONS` (`us`/`eu`/`auto`).
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

## Interaction Flows (read-only + On-Demand toggle)

Flows (FEAT-61) are recorded with the WebChange Detector browser extension and managed in the
WebChange Detector account; the add-on only **views and toggles** them. Surface: an "Interaction
Flows" section on the **per-site tab** (`templates/site-tab.php`, rendered only when the site is
enabled), lazy-loaded via the `flow_list` AJAX action. Fragments come from
`WCD_MainWP_Interaction_Flows` (naming rule: `Interaction_Flows` is this customer-facing feature,
`WCD_MainWP_Update_Flow` is the unrelated safe-update orchestration).

- **Website binding:** the list is scoped to the site's mapped `website_uuid`
  (`WCD_MainWP_Site_Map::get_website_uuid()`), so it shows exactly the flows that run in
  MainWP-triggered checks. An unmapped/not-enabled site gets a clean setup hint, never a fatal.
- **Only the On-Demand toggle** (`enabled_manual`, honored by safe-update pre/post checks) is
  writable; `enabled_monitoring` renders as a read-only badge (the add-on hides monitoring
  settings everywhere). The only writable flow fields live in the
  `WCD_MainWP_API::FLOW_TOGGLE_FIELDS` allow-list; `name`/`steps` are never sent. Enabling asks
  for a `window.confirm` (cost transparency: checkpoints run as billable checks).
- **Plan gate:** the toggle is disabled + an upsell message shown when
  `plan_features.interaction_flows` is missing from the cached account
  (`WCD_MainWP_Site_Settings::get_account()`, 0 extra API calls). Writes are additionally gated
  server-side: a **403 on the toggle is detected status-based** (never by matching the message
  string), invalidates the account cache (stale after a downgrade) and re-renders the gated list.
- **AJAX actions** (`flow_list`, `flow_steps`, `flow_runs`, `flow_run_view`, `flow_toggle`):
  additive, each with the standard inline nonce + capability check; they return rendered HTML
  fragments. A flow/run **404 is NOT a stale site mapping** (the flow was deleted in the account
  meanwhile): these handlers never call `reject_if_group_gone()`/`reset_site()`.
- **Run detail polling:** the per-step run detail re-fetches `flow_run_view` every **10 s**
  (webapp parity) ONLY while the detail view is open AND the run's `status` is `processing`;
  the loop stops on close, accordion collapse, status settle and errors, and skips ticks while
  the browser tab is hidden. Everything else is lazy per drill-in (list = 1 GET per tab view).
- **Public comparison links** for checkpoint results are built from the run payload's comparison
  `token` + `WCD_MainWP_Interaction_Flows::PUBLIC_COMPARISON_URL` (the payload carries no
  `public_link`). Sensitive step values arrive redacted (`value = null`, `value_set = true`) and
  render as a neutral "Value stored" hint. Checkpoint comparisons also appear in the Checks tab
  drill-in, where `comparison_row()` adds a small `flow name : checkpoint label` line (additive
  markup from the API's `flow_checkpoint_label`/`flow_name` fields).

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
