=== WebChange Detector for MainWP ===
Contributors: wpmike
Tags: mainwp, visual regression testing, screenshots, updates, monitoring
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual checks for your MainWP updates: before/after screenshots of all child sites in one run, compared automatically.

== Description ==

WebChange Detector for MainWP adds a visual safety net to the updates you already run from your MainWP Dashboard. Before the updates, it captures screenshots of the pages you selected on every child site. It then installs the updates and captures the same pages again. The screenshots are compared automatically, and you instantly see which pages changed, with the visual difference highlighted.

Everything runs from your MainWP Dashboard. Nothing is installed on your child sites.

= How it works =

1. Connect your WebChange Detector account with your API token.
2. Enable the child sites you want to check and pick the pages (desktop and/or mobile).
3. Click "Run visual check & update" on your MainWP dashboard or Updates page.
4. The plugin captures pre-update screenshots, installs all pending updates, captures post-update screenshots and compares them.
5. Review the results on the Visual Checks page: every page with a visual change is flagged, including an AI summary of what changed.

= Features =

* One-click safe updates: pre-update screenshots, updates, post-update screenshots, comparison: all in one run across all child sites.
* Bulk or single site: run the flow for every enabled child site at once or for one site from its overview.
* Page selection per site: choose which URLs are checked, separately for desktop and mobile.
* Visual Checks overview: all runs across your sites with filters, change percentages and side-by-side comparisons.
* Dashboard widget: your remaining checks, plan usage and latest run at a glance.
* Auto-enable new sites: optionally provision every newly added MainWP child site for visual checks automatically.

= External service: WebChange Detector =

This plugin relies on the WebChange Detector service ([https://www.webchangedetector.com](https://www.webchangedetector.com)) as a third-party service to capture, store and compare the screenshots. A WebChange Detector account is required (free plan available).

The plugin sends data to `api.webchangedetector.com` only after you have entered your API token, and only in these circumstances: when you connect or verify your account, when you enable a child site or sync its page URLs (the public URLs and page titles of that site are transmitted), when you start a visual check (screenshots are taken of the selected public URLs), and when you review or update results. Your API token is sent with each request to authenticate your account. No data about your WordPress users or any non-selected content is transmitted.

* Service: [https://www.webchangedetector.com](https://www.webchangedetector.com)
* Terms of use: [https://www.webchangedetector.com/terms-of-use/](https://www.webchangedetector.com/terms-of-use/)
* Privacy policy: [https://www.webchangedetector.com/privacy-statement/](https://www.webchangedetector.com/privacy-statement/)

= Requirements =

* A MainWP Dashboard (the free [MainWP plugin](https://wordpress.org/plugins/mainwp/)) with at least one connected child site.
* A WebChange Detector account and API token. The free plan includes monthly checks; see [plans](https://www.webchangedetector.com/pricing/).

== Installation ==

1. Install and activate the plugin on the WordPress site running your MainWP Dashboard (not on the child sites).
2. Go to MainWP > Extensions > WebChange Detector.
3. Enter your WebChange Detector API token. You find it in your account at [www.webchangedetector.com](https://www.webchangedetector.com).
4. Enable the child sites you want to check and select the pages for desktop and mobile.
5. Run your next update from the MainWP dashboard with "Run visual check & update".

== Frequently Asked Questions ==

= Do I need to install anything on my child sites? =

No. The plugin runs entirely on your MainWP Dashboard and talks to the WebChange Detector service. Child sites are captured through their public URLs.

= Do I need a WebChange Detector account? =

Yes. The screenshots are captured and compared by the WebChange Detector service, so you need an account and API token. A free plan is available.

= Which pages are checked? =

You choose. After enabling a child site, its published pages and posts are synced and you select which URLs are checked, separately for desktop and mobile. Every selected URL and device counts as one check per capture.

= What happens if a site is offline during an update run? =

The run tolerates it: the offline site is skipped for updates and the other sites continue. The post-update screenshots still run for the sites that were updated.

= Does this work with MainWP updates started elsewhere (e.g. scheduled)? =

Updates you start outside the plugin's own flow still trigger post-update screenshots as a safety net, so you can compare against the last baseline. For the full pre/post comparison, start the run from the "Run visual check & update" button.

== Screenshots ==

1. The safe-update banner on the MainWP dashboard.
2. Pre-flight summary: sites, pages, checks and credit coverage before the run.
3. The unified run card: pre-update screenshots, updates, post-update screenshots.
4. Visual Checks overview with filters and change detections.
5. Per-site page selection for desktop and mobile.

== Changelog ==

= 1.0.0 =
* Initial release: safe-update flow (pre/post screenshots around MainWP updates), Visual Checks overview, dashboard widget, per-site URL selection, auto-enable for new sites.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
