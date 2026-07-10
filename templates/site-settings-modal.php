<?php
/**
 * Per-site On-Demand check settings modal (native Fomantic `ui modal`).
 *
 * One server-rendered modal instance, reused for every site: the JS fills its fields on open
 * (get_site_settings) and saves them (save_site_settings). It mirrors the webapp's On-Demand
 * (manual) website settings, scoped to the fields that apply to MainWP-managed sites (no website
 * name, no monitoring/schedule fields, no WP-plugin settings).
 *
 * Included once by WCD_MainWP_Site_Settings::render_sites_settings_page(), after the sites list.
 * All copy is translated and all output escaped. No inline CSS/JS: behavior + values come from
 * wcd-mainwp.js, surfaces from Fomantic + the MainWP theme.
 *
 * @package WebChangeDetector_MainWP
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ui modal wcd-settings-modal" id="wcd-site-settings-modal" data-site-id="">
	<i class="close icon"></i>
	<div class="header"><?php esc_html_e( 'On-Demand check settings', 'webchangedetector-for-mainwp' ); ?></div>
	<div class="scrolling content">
		<div class="wcd-settings-loading" data-role="loading">
			<div class="ui active inline loader"></div>
		</div>

		<form class="ui form wcd-settings-form" data-role="form" hidden>
			<p class="wcd-muted"><?php esc_html_e( 'These settings apply to the On-Demand checks run when you update this site.', 'webchangedetector-for-mainwp' ); ?></p>

			<div class="field">
				<label for="wcd-set-region"><?php esc_html_e( 'Screenshot region', 'webchangedetector-for-mainwp' ); ?></label>
				<select class="ui dropdown" id="wcd-set-region" name="screenshot_region">
					<option value="auto"><?php esc_html_e( 'Auto-Detect', 'webchangedetector-for-mainwp' ); ?></option>
					<option value="us"><?php esc_html_e( 'United States (US)', 'webchangedetector-for-mainwp' ); ?></option>
					<option value="eu"><?php esc_html_e( 'Europe (EU)', 'webchangedetector-for-mainwp' ); ?></option>
				</select>
				<small class="wcd-muted"><?php esc_html_e( 'Region the screenshots are taken from. Auto-Detect picks the closest region, then locks to the resolved US or EU.', 'webchangedetector-for-mainwp' ); ?></small>
			</div>

			<div class="field">
				<label><?php esc_html_e( 'Activate newly synced URLs by default', 'webchangedetector-for-mainwp' ); ?></label>
				<div class="ui checkbox">
					<input type="checkbox" id="wcd-set-default-desktop" name="default_desktop" />
					<label for="wcd-set-default-desktop"><?php esc_html_e( 'Desktop', 'webchangedetector-for-mainwp' ); ?></label>
				</div>
				<div class="ui checkbox">
					<input type="checkbox" id="wcd-set-default-mobile" name="default_mobile" />
					<label for="wcd-set-default-mobile"><?php esc_html_e( 'Mobile', 'webchangedetector-for-mainwp' ); ?></label>
				</div>
			</div>

			<div class="field">
				<label for="wcd-set-threshold"><?php esc_html_e( 'Difference threshold', 'webchangedetector-for-mainwp' ); ?></label>
				<div class="ui right labeled input">
					<input type="number" id="wcd-set-threshold" name="threshold" step="0.01" min="0" max="100" />
					<div class="ui basic label">%</div>
				</div>
				<small class="wcd-muted"><?php esc_html_e( 'Differences below this percentage are not flagged.', 'webchangedetector-for-mainwp' ); ?></small>
			</div>

			<div class="ui accordion wcd-settings-advanced">
				<div class="title">
					<i class="dropdown icon"></i>
					<?php esc_html_e( 'Advanced settings', 'webchangedetector-for-mainwp' ); ?>
				</div>
				<div class="content">
					<div class="field">
						<label for="wcd-set-auth-user"><?php esc_html_e( 'Basic Auth username', 'webchangedetector-for-mainwp' ); ?></label>
						<input type="text" id="wcd-set-auth-user" name="basic_auth_user" autocomplete="off" />
						<small class="wcd-muted"><?php esc_html_e( 'Credentials for password-protected pages that require HTTP authentication.', 'webchangedetector-for-mainwp' ); ?></small>
					</div>

					<div class="field">
						<label for="wcd-set-auth-pass"><?php esc_html_e( 'Basic Auth password', 'webchangedetector-for-mainwp' ); ?></label>
						<input type="password" id="wcd-set-auth-pass" name="basic_auth_password" autocomplete="new-password" />
						<small class="wcd-muted wcd-auth-pass-hint" data-role="passwordset" hidden><?php esc_html_e( 'A password is stored. Clear this field to remove it, or type a new one to replace it.', 'webchangedetector-for-mainwp' ); ?></small>
					</div>

					<div class="field">
						<label><?php esc_html_e( 'Static IP proxy', 'webchangedetector-for-mainwp' ); ?></label>
						<div class="ui toggle checkbox">
							<input type="checkbox" id="wcd-set-proxy" name="proxy_on" />
							<label for="wcd-set-proxy"><?php esc_html_e( 'Use static IP proxy', 'webchangedetector-for-mainwp' ); ?></label>
						</div>
						<small class="wcd-muted"><?php esc_html_e( 'Take screenshots through a static IP so the site can allowlist it.', 'webchangedetector-for-mainwp' ); ?></small>
					</div>

					<div class="field">
						<label for="wcd-set-delay"><?php esc_html_e( 'Screenshot delay (seconds)', 'webchangedetector-for-mainwp' ); ?></label>
						<input type="number" id="wcd-set-delay" name="screenshot_delay" min="7" max="60" />
						<small class="wcd-muted"><?php esc_html_e( 'Extra wait before each screenshot (7 to 60 seconds). Leave blank for the default.', 'webchangedetector-for-mainwp' ); ?></small>
					</div>

					<div class="field">
						<label for="wcd-set-css"><?php esc_html_e( 'CSS injection', 'webchangedetector-for-mainwp' ); ?></label>
						<textarea id="wcd-set-css" name="css" rows="4" spellcheck="false"></textarea>
						<small class="wcd-muted"><?php esc_html_e( 'CSS applied before each screenshot (e.g. to hide dynamic elements).', 'webchangedetector-for-mainwp' ); ?></small>
					</div>

					<div class="field">
						<label for="wcd-set-js"><?php esc_html_e( 'JavaScript injection', 'webchangedetector-for-mainwp' ); ?></label>
						<textarea id="wcd-set-js" name="js" rows="4" spellcheck="false"></textarea>
						<small class="wcd-muted"><?php esc_html_e( 'JavaScript run before each screenshot.', 'webchangedetector-for-mainwp' ); ?></small>
					</div>
				</div>
			</div>

			<p class="wcd-error" data-role="error" hidden></p>
		</form>
	</div>
	<div class="actions">
		<button type="button" class="ui button wcd-settings-cancel"><?php esc_html_e( 'Cancel', 'webchangedetector-for-mainwp' ); ?></button>
		<button type="button" class="ui blue button wcd-settings-save"><?php esc_html_e( 'Save settings', 'webchangedetector-for-mainwp' ); ?></button>
	</div>
</div>
