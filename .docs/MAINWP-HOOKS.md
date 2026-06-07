# MainWP Hook Reference (for the WebChange Detector add-on)

Source of truth: https://mainwp.dev/hooks/ (visit for full per-hook detail). The hooks below were
also verified against the vendored MainWP core (v6.1.1) under
`mainwp/app/public/wp-content/plugins/mainwp/`.

## Integration principle: hooks first

This add-on integrates with MainWP through **documented hooks/filters only**, so that MainWP core
updates cannot silently break us by changing internal classes/methods. Internal MainWP classes
(`MainWP_Connect`, `MainWP_Abilities_*`, `MainWP_DB`, ...) are NOT part of the public contract and
must not be called, with the two sanctioned exceptions below (each has no hook equivalent and is
wrapped in `class_exists`/`method_exists` guards that degrade to a clear admin notice instead of a
fatal):

1. **Fetch a child site's pages** — `MainWP_Connect::fetch_urls_authed($websites, 'get_all_pages', ...)`.
   Posts have a hook (`mainwp_getallposts`); pages do not (`mainwp_getallpages` does not exist).
2. **Trigger an update for one site** — `MainWP_Abilities_Updates::execute_update_site_{core|plugins|themes|translations}()`.
   No hook can start an update.

If a future MainWP release removes/changes either, the guard fires and the feature degrades (we ask
the user to run the native update; the after-update hook still drives the post screenshots).

## Hooks we use

| Hook | Type | Args | Used for |
|------|------|------|----------|
| `mainwp_getextensions` | filter | `$extensions` | Register the extension + settings page callback + icon |
| `mainwp_getsubpages_sites` | filter | `$subPages` | Per-site tab (`WcdVisualRegressionTesting`) + the dashboard "Change Detections" page (`WcdChangeDetections`, `sitetab => false`) |
| `mainwp_getmetaboxes` | filter | `$metaboxes` | Dashboard widget (account/credits) |
| `mainwp_getdbsites` | filter | `$pluginFile, $key, $sites, $groups, $options, $clients` | List managed child sites (id, url, name) |
| `mainwp_site_synced` | action | `$pWebsite, $information` | After a child site syncs -> sync its URLs to the WCD group |
| `mainwp_added_new_site` | action | `$id, $website` | Auto-enable a newly added child site for WCD (provision website/groups + URL sync), when the "Auto-enable new sites" setting is on. Best-effort: errors are swallowed so MainWP's add-site never breaks |
| `mainwp_getallposts` | filter/hook | `$data` (search params) | Fetch a child's posts (hooks-first; pages use the sanctioned call) |
| `mainwp_before_overview_widgets` | action | `$context` | Inject the WCD hero banner at the top of the dashboard body. Fires with `'dashboard'` on BOTH the Operations dashboard and an individual child-site overview (both go through `MainWP_Overview::render_dashboard_body`); scope is detected via `MainWP_System_Utility::get_current_wpid()` (a site id => single-site, else bulk) |
| `mainwp_updates_before_plugin_updates` | action | `$websites, $total_plugin_upgrades, ...` | Render the same hero banner above the native Updates page's plugin list, only when `$total_plugin_upgrades > 0`. Same scope detection as the dashboard banner |
| `mainwp_after_wp_update` | action | `$information, $site` | Once per site after a core update -> post screenshots (recovery / non-card coverage) |
| `mainwp_after_plugin_theme_translation_update` | action | `$information, $type, $slugs, $site` | Once per type after plugin/theme/translation update -> post screenshots |
| `mainwp_pageheader_extensions` / `mainwp_pagefooter_extensions` | action | plugin file | MainWP chrome around the settings page |
| `mainwp_pageheader_sites` / `mainwp_pagefooter_sites` | action | tab slug | MainWP chrome around the per-site tab |

### Notes on the update hooks (verified)

- `mainwp_after_wp_update` and `mainwp_after_plugin_theme_translation_update` are the genuinely
  once-per-site (core) / once-per-type (plugins, themes, translations) after-hooks. They fire from
  both the abilities path and the legacy updates handler, and the translation hook also fires from
  cron. Therefore the add-on **dedupes on a run-id transient**, not just `site->id`.
- `mainwp_website_before_updated` / `mainwp_website_updated` fire per low-level fetch (per update
  type), so they are noisier; we do not rely on them.
- There is **no** hook to trigger updates and **no** hook that exposes a child's full page list.

## Sanctioned internal calls (guarded)

- `MainWP_Connect::fetch_urls_authed(&$websites, $what, $params, $handler, &$output, ...)` with
  `$what = 'get_all_pages'`. Handler reuse: `MainWP_REST_Controller::posts_pages_search_handler`.
  `fetch_urls_authed` has no client paging; results are capped by `maxRecords` (pass a high value).
- `MainWP_Abilities_Updates::execute_update_site_core|plugins|themes|translations(['site_id_or_domain' => $id, 'slugs' => [...]])`.
  These run synchronously for a single site and return `['updated' => [...], 'errors' => [...], 'summary' => ...]`.
  An empty `updated[]` means "nothing pending" (success, no post needed), not a failure. Offline sites
  appear in `errors[]` (`mainwp_site_offline`). Do NOT use `execute_run_updates` for a single site:
  above `BATCH_THRESHOLD` (200) it queues and returns an async job instead of results.

## Reads (not "calls"): MainWP DB upgrade columns

`MainWP_DB::instance()->get_website_by_id($id)` is read (never written) to get a site's pending-update
**count** (banner copy) and **item list** (the preflight "what gets updated" panel), parsing the
`wp_upgrades` / `plugin_upgrades` / `theme_upgrades` / `translation_upgrades` JSON columns. Best-effort:
degrades to `null` / `[]` when the DB layer is unavailable. This is read-only and not an update trigger,
so it is not part of the two sanctioned write-side internal calls above.

## Note: the AJAX `poll` aggregates multiple batches

The unified in-card run runs all of a phase's sites at once, so `poll` accepts `batches[]` (single
`batch` still supported) and sums the queues endpoint's `meta.status_counts_by_batch` into one
aggregate, plus a `by_batch` breakdown for the per-site counters. No new MainWP hook is involved.
