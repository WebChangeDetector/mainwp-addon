=== WebChange Detector for MainWP ===
Contributors: Mike.Miler
Tags: mainwp, visual regression testing, screenshots, updates, monitoring
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual checks for your MainWP updates: before/after screenshots of all child sites in one run, compared automatically.

== Description ==

WebChange Detector for MainWP adds a visual safety net to the updates you already run from your MainWP Dashboard. Before the updates, it captures screenshots of the pages you selected on every child site. It then installs the updates and captures the same pages again. The screenshots are compared automatically, and you instantly see which pages changed, with the visual difference highlighted.

Everything runs from your MainWP Dashboard. Nothing is installed on your child sites.

= What is an on-demand check? =

An on-demand check is a visual diff you run around a change you make yourself, like installing updates. WebChange Detector takes a screenshot of each selected page **before** the change, applies the change, takes another screenshot **after**, and compares the two automatically. The comparison runs on desktop and mobile, and the AI ignores moving parts like sliders, carousels, ads and animations, so you only get flagged on changes that actually matter. In MainWP, this on-demand check is wrapped directly around the updates you run from your dashboard: pre-update screenshots, the updates, post-update screenshots, and the comparison all happen in one run across all your child sites.

= How it works =

1. Create a free WebChange Detector account at [webchangedetector.com](https://www.webchangedetector.com) if you do not have one yet, then copy your API token from your account and enter it in MainWP.
2. Enable the child sites you want to check.
3. Sync each enabled child site's pages with "Sync WP URLs" so its pages become available, then select the pages to check (desktop and/or mobile).
4. Click "Run visual checks & updates" on your MainWP dashboard or Updates page.
5. The plugin captures pre-update screenshots, installs all pending updates, captures post-update screenshots and compares them. Before the pre-update screenshots and after the updates, the cache on each child site is cleared automatically, so the screenshots always show the real, freshly generated state of the site.
6. Review the results on the Checks tab: every page with a visual change is flagged, including an AI summary of what changed.

= Features =

* One-click safe updates: pre-update screenshots, updates, post-update screenshots, comparison: all in one run across all child sites.
* Automatic cache clearing: the child site's cache is cleared before the pre-update screenshots and after the updates, so screenshots never show a stale cached page. Works with 20+ caching plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and more) through the MainWP Child plugin, with nothing extra to install.
* Bulk or single site: run the flow for every enabled child site at once or for one site from its overview.
* Page selection per site: choose which URLs are checked, separately for desktop and mobile.
* Checks overview: all runs across your sites with filters, change percentages and side-by-side comparisons.
* Safe Update widget: start a run from a draggable, hideable widget on your Operations dashboard or a single child site's overview.
* Live run popup: a Pre-update, Updates, Post-update and Done timeline with per-site progress, and automatic resume if you navigate away mid-run.
* Account overview: your plan, remaining checks and renewal date on the Account tab.
* Auto-enable new sites: optionally provision every newly added MainWP child site for visual checks automatically.

= External service: WebChange Detector =

This plugin relies on the WebChange Detector service ([https://www.webchangedetector.com](https://www.webchangedetector.com)) as a third-party service to capture, store and compare the screenshots. A WebChange Detector account is required (free plan available).

The plugin sends data to `api.webchangedetector.com` only after you have entered your API token, and only in these circumstances: when you connect or verify your account, when you enable a child site or sync its page URLs (the public URLs and page titles of that site are transmitted), when you start a visual check (screenshots are taken of the selected public URLs), and when you review or update results. Your API token is sent with each request to authenticate your account. No data about your WordPress users or any non-selected content is transmitted.

* Service: [https://www.webchangedetector.com](https://www.webchangedetector.com)
* Terms of use: [https://www.webchangedetector.com/terms-of-use/](https://www.webchangedetector.com/terms-of-use/)
* Privacy policy: [https://www.webchangedetector.com/privacy-statement/](https://www.webchangedetector.com/privacy-statement/)

= Requirements =

* A MainWP Dashboard (the free [MainWP plugin](https://wordpress.org/plugins/mainwp/)) with at least one connected child site.
* A WebChange Detector account and API token. If you do not have an account yet, create a free one at [webchangedetector.com](https://www.webchangedetector.com); the free plan includes monthly checks. See [plans](https://www.webchangedetector.com/pricing/) for paid options.

== Installation ==

1. Install and activate the plugin on the WordPress site running your MainWP Dashboard (not on the child sites).
2. Create a free WebChange Detector account at [www.webchangedetector.com](https://www.webchangedetector.com) if you do not have one yet.
3. Copy your API token from your WebChange Detector account.
4. Go to MainWP > Extensions > WebChange Detector and paste the API token into the "WebChange Detector API Token" field.
5. Enable the child sites you want to check.
6. Sync each enabled child site's pages with "Sync WP URLs" so its pages become available.
7. Select the pages to check for desktop and/or mobile.
8. Run your next update from the MainWP dashboard with "Run visual checks & updates".

== Frequently Asked Questions ==

= What is an on-demand check? =

An on-demand check is a visual diff you run around a change you make yourself. WebChange Detector takes a screenshot of each selected page before the change, you apply the change, it takes another screenshot after, and the two are compared automatically on desktop and mobile. In MainWP this is wrapped around the updates you run: the plugin captures the pre-update screenshots, installs the updates, captures the post-update screenshots, and shows you every page that changed, with an AI summary. The AI ignores moving parts like sliders, carousels and ads, so you only get flagged on real changes.

= Do I need to install anything on my child sites? =

No. The plugin runs entirely on your MainWP Dashboard and talks to the WebChange Detector service. Child sites are captured through their public URLs.

= Do I need a WebChange Detector account? =

Yes. The screenshots are captured and compared by the WebChange Detector service, so you need an account and API token. A free plan is available.

= Which pages are checked? =

You choose. After enabling a child site, its published pages and posts are synced and you select which URLs are checked, separately for desktop and mobile. Every selected URL and device counts as one check per capture.

= Why do I need to sync the child site's pages? =

Before it can check a site, the plugin needs the list of public URLs on that site. Syncing fetches the published pages and posts from the child and makes them available for selection. It runs automatically when you enable a site and again after MainWP syncs the child. Until a site is synced, there are no pages to select and no checks can run.

= What happens if a site is offline during an update run? =

The run tolerates it: the offline site is skipped for updates and the other sites continue. The post-update screenshots still run for the sites that were updated.

= Do I need to clear the cache on my child sites after updates? =

No, the plugin does that for you. It clears each child site's cache before the pre-update screenshots and again after the updates, using the cache clearing built into the MainWP Child plugin (supports 20+ caching plugins, detected automatically). This makes sure the comparison shows the actual state of the site after the updates instead of a cached old version. If no supported caching plugin is active, this step is simply skipped.

= Does this work with MainWP updates started elsewhere (e.g. scheduled)? =

Updates you start outside the plugin's own flow still trigger post-update screenshots as a safety net, so you can compare against the last baseline. For the full pre/post comparison, start the run from the "Run visual checks & updates" button.

== Screenshots ==

1. The Safe Update widget on your MainWP Operations dashboard: how many sites have updates, how many pages and checks the next run will cover, and one button to start it.
2. Pre-flight summary before the run: sites, pages, checks and credit coverage, plus the exact updates that will be installed, per site.
3. The live run popup: a Pre-update, Updates, Post-update and Done timeline with per-site progress. When it finishes you see "All good" or the number of pages to review, with Re-check and View results.
4. Side-by-side before/after comparison with the AI change analysis: real changes flagged, dynamic content auto-ignored, with the visual difference percentage and a browser-console check.
5. The Run tab on the WebChange Detector extension page: start a safe-update run across all your enabled sites.
6. The Checks tab: every run across your sites with filters for date, status, website and detection, plus an AI summary per run.
7. The Settings tab: activate a website and choose which URLs are checked, separately for desktop and mobile. One click activates checks for all your managed websites.
8. The Account tab: your plan, remaining checks and active sites, the API token, auto-enable for new sites, and the reset-connection option.

== Changelog ==

= 1.0.2 =
* New: One unified extension page with Run, Checks, Settings and Account tabs. 
* New: Safe Update dashboard widget. 
* New: The update run moved to a live popup.
* New: "Reset connection" button on the Account tab disconnects this dashboard from your WebChange Detector account locally.
* Improvement: A run now covers only the sites that actually have updates.
* Improvement: The pre-flight summary now shows credit coverage and the exact pages each site will check.
* Fix: Long page titles in the Checks list now wrap to a new line instead of pushing the "View" button out of sight.

= 1.0.1 =
* Improvement: "Select all" pages is now instant, even on sites with thousands of URLs.
* Improvement: new "Activate checks for all websites" button activates every managed site at once
* Improvement: the dashboard now shows a short setup hint when no API token is connected yet.
* Fix: switching your API token now re-links your existing websites under the new account instead of creating duplicates, and repairs stale links automatically.

= 1.0.0 =
* Initial release: safe-update flow (pre/post screenshots around MainWP updates), Visual Checks overview, dashboard widget, per-site URL selection, auto-enable for new sites, automatic cache clearing on child sites before pre-update screenshots and after updates.

== Upgrade Notice ==

= 1.0.2 =
A redesigned dashboard: one extension page with Run, Checks, Settings and Account tabs, a Safe Update widget, a live run popup, and automatic resume of interrupted runs so no checks are wasted.

= 1.0.1 =
Maintenance release: faster "Select all", a one-click "Activate checks for all websites" button, automatic page syncing, and safer API-token switching.

= 1.0.0 =
Initial release.
