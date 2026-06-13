# MainWP Add-on Review Conventions

Rules for implementing and reviewing the WebChange Detector MainWP add-on. Architecture, data flow, the safe-update pipeline, setup and troubleshooting: see `MAINWP.md`. Hook/filter details and the two guarded internal calls: see `MAINWP-HOOKS.md`.

## Paths & deployment boundary

- **Working copy (edit here):** `/Users/mike/htdocs/wcd/mainwp/app/public/wp-content/plugins/webchangedetector-for-mainwp/`.
- **NEVER edit** `/Users/mike/htdocs/wcd/wp-repo-mainwp/`: it is the generated wordpress.org distribution, produced by `scripts/build-release.sh` (rsync minus `.distignore`). Any change there is overwritten on the next build.

## Integration principle

- Integrate through **documented MainWP hooks/filters only**. The only exceptions are the two operations with no hook (fetch a child site's pages; trigger a single site's update), which use `class_exists`/`method_exists`-guarded internal calls and must degrade to a notice (never a fatal) if MainWP changes them. Do not add new internal MainWP calls without the same guarding and a note in `MAINWP-HOOKS.md`.
- No WCD API call before a token is configured (wordpress.org guideline 7). Guard every API path on `WCD_MainWP_Site_Settings::get_global()` being non-empty.

## Naming

- Slug, folder, main file and text domain are all **`webchangedetector-for-mainwp`** ("X for MainWP" naming; a slug starting with `mainwp` violates trademark guideline 17). Never rename to start with `mainwp`.
- PHP: WordPress coding standards, classes `WCD_MainWP_*`, methods snake_case, constants UPPER_SNAKE_CASE.
- Local template variables echoed in templates carry a `wcd_mainwp_` prefix (avoids clashing with MainWP's own template scope).

## Mandatory Patterns

- **All user-facing copy** through `__()`/`esc_html__()`/`esc_attr__()`/`wp_kses_post()` with text domain `webchangedetector-for-mainwp`. No bare strings in markup.
- **Escape on output**: `esc_html`, `esc_attr`, `esc_url`; `wp_kses_post` only for strings that intentionally contain links/markup (e.g. `WCD_MainWP_Bootstrap::no_token_hint_html()`).
- **External CSS/JS only.** Styles live in `assets/css/`, scripts in `assets/js/`; never inline `<style>`/`<script>` or `style=`/`on*=` attributes. Use existing CSS custom properties (e.g. `--wcd-muted`) and Semantic/Fomantic UI classes already in the markup.
- **Strings to JS** go through `WCD_MainWP_Bootstrap::js_strings()` + `wp_localize_script`, read via the `t()` helper in `wcd-mainwp.js`. Do not hardcode copy in the JS file.
- **AJAX handlers** (`WCD_MainWP_Ajax`) verify the nonce (`check_ajax_referer`) AND `current_user_can('manage_options')` via `self::verify()` before any work. Sanitize every `$_POST` field.
- **Browser is the scheduler.** Operations that fan out over many sites (bulk sync, the safe-update pipeline) iterate **one site per AJAX request from JS**, not a server-side loop over all sites. A single request must never stack many slow WCD API calls (PHP timeout + credit-burn risk). Reuse existing per-site endpoints (`toggle_site`, `sync_urls`) rather than adding bulk server endpoints.
- **Cost transparency.** Enabling/activating a site provisions WCD websites and groups (billing is per check; websites are unlimited on every plan). Never enable/activate sites silently or in bulk without explicit user action (the per-site toggle, or a `window.confirm` before "Activate checks for all websites"). User-facing copy frames this as *activating visual checks*, never as *syncing* (the URL sync runs in the background; show a spinner and raise the `beforeunload` guard while it runs).
- **Account-bound provisioning.** The site map (`wcd_site_map`) stores website/group UUIDs that only resolve for the account behind the current API token. When the token changes, the stored UUIDs belong to the previous account and every group call 404s. So: on a token change in `handle_save_settings()`, call `WCD_MainWP_Site_Map::reset_all()`; and when a group call returns HTTP 404, self-heal with `WCD_MainWP_Site_Map::reset_site()` and surface a "re-sync this site" message instead of the raw API error (see `WCD_MainWP_Ajax::reject_if_group_gone()`). Never trust stored UUIDs across an account switch.
- **Separate website per integration (`managed_by`).** MainWP websites carry `managed_by=mainwp` on the API so their `?p=ID` URLs never mix with the first-party clean permalinks (the API keeps one website per `(user, domain, managed_by)`). Always send `managed_by` (`WCD_MainWP_API::MANAGED_BY`) when creating a website, and the `x-wcd-managed-by` header on every sync call (`sync_urls`/`start_url_sync`). Before creating a website, look it up first (`get_websites()` + `find_existing_website()`): if the account already has the MainWP website for that domain, re-link to its UUIDs instead of provisioning duplicates (this, with the account reset above, makes token switching idempotent).

## Text rules

- **Never use double-hyphens or em-dashes** in any copy, comment, or doc. Use commas, colons, semicolons, or restructure.
- English only for code, comments, and docs.

## Backward compatibility

- AJAX endpoints accept both the legacy single-value shape (`site_id`, `batch`) and the newer array shape (`site_ids[]`, `batches[]`); keep both when touching a handler.
- Additive response fields only; do not remove or rename existing keys consumers (the JS) read.

## Deprecated / Forbidden

- No edits to the `wp-repo-mainwp` deployment folder (see Paths).
- No AI model names or internal crop URLs in any user-facing surface (the API strips these server-side; keep them out of templates/JS too).
- No new dependency on undocumented MainWP internals without a guarded fallback.
