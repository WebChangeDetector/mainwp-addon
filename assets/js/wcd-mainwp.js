/**
 * WebChange Detector MainWP add-on.
 *
 * Settings interactions (enable site, configure URLs, sync) and the single-click, per-site
 * pipelined safe-update flow (decision -> preflight -> running -> streaming results). The browser
 * is the scheduler: each site advances its own state machine via discrete AJAX calls.
 */
(function () {
    'use strict';

    var cfg = window.wcdMainWP || {};
    var S = cfg.strings || {};
    var POLL_INTERVAL = 3000;
    var POLL_MAX_TRIES = 80; // ~4 minutes per batch.
    var HEARTBEAT_INTERVAL = 7000;   // keep the run's server-side activity fresh while a tab drives it.
    var RESUME_RECHECK = 8000;       // re-poll run_status while another tab still looks alive.

    function t(key) {
        return S[key] || key;
    }

    /** Promise-based AJAX wrapper. Rejects with a real message on failure. */
    function api(action, data) {
        var body = new URLSearchParams();
        body.set('action', 'wcd_mainwp_' + action);
        body.set('nonce', cfg.nonce);
        data = data || {};
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { body.append(k + '[]', item); });
            } else if (v !== null && typeof v === 'object') {
                // One nesting level for maps of lists (the Updates-page selection map):
                // key[<sub>][]=item, which PHP parses natively. An empty list still appends one
                // empty item so the sub-key survives the round trip (core rows have no slugs;
                // the server drops empty slug entries but keeps the site key).
                Object.keys(v).forEach(function (sub) {
                    var list = Array.isArray(v[sub]) ? v[sub] : [];
                    if (!list.length) { body.append(k + '[' + sub + '][]', ''); return; }
                    list.forEach(function (item) { body.append(k + '[' + sub + '][]', item); });
                });
            } else if (v !== undefined && v !== null) {
                body.set(k, v);
            }
        });

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, data: { message: t('genericError') } }; });
        }).then(function (json) {
            if (!json || !json.success) {
                var err = new Error((json && json.data && json.data.message) || t('genericError'));
                // Keep the structured payload (e.g. the `unlinked` flag) on the error for callers.
                err.data = (json && json.data) ? json.data : {};
                throw err;
            }
            return json.data;
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    // Stable per-tab id, kept in sessionStorage so it survives a reload / same-tab navigation. The
    // run records its driver, so when this tab comes back it recognises its OWN run and reclaims it
    // instantly instead of waiting out the two-tab guard. Falls back to a page-local id if storage
    // is unavailable (then a reload simply uses the heartbeat gate, like before).
    var cachedDriverId = null;
    function driverId() {
        if (cachedDriverId) { return cachedDriverId; }
        var key = 'wcd_mainwp_driver', id = null;
        try { id = window.sessionStorage.getItem(key); } catch (e) { id = null; }
        if (!id) {
            id = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
            try { window.sessionStorage.setItem(key, id); } catch (e2) { /* storage off: page-local only */ }
        }
        cachedDriverId = id;
        return id;
    }

    /* ─────────────────────────────── DOM helpers ───────────────────────── */

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'class') { node.className = attrs[k]; }
            else if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'html') { node.innerHTML = attrs[k]; }
            else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) {
            if (typeof c === 'string') { node.appendChild(document.createTextNode(c)); }
            else if (c) { node.appendChild(c); }
        });
        return node;
    }

    /* ───────────────────────────────── Modal ───────────────────────────── */
    // One reused MainWP-native Fomantic `ui modal`, shown/hidden via the jQuery modal API. We swap
    // its .header/.content/.actions between flow steps (decision -> preflight -> running) instead of
    // recreating it, so there is no hide/show flicker or dimmer race. The Fomantic close icon (added
    // once, kept across content swaps) stays bound.

    var modalNode = null;
    var closeIconNode = null;   // the original Fomantic-bound close icon, kept across content swaps
    var fallbackDimmer = null;  // only used when Fomantic's modal plugin is unavailable
    var activeRun = false;      // true while a safe-update pipeline is in flight (drives the leave-page guard)
    var activeRunRef = null;    // the current run object whose card lives in the modal (running OR finished-but-not-dismissed)
    var syncInFlight = 0;       // count of in-flight site activations (drives the leave-page guard)
    var heartbeatTimer = null;  // keeps the run's server-side activity fresh while THIS tab drives it
    var resumeRecheckTimer = null;

    function hasModalPlugin() {
        return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function');
    }

    // While a run is in flight the popup must stay open for a smooth flow, so the close is vetoed
    // (the Fomantic close icon is also hidden in the run popup via CSS; the card's own dismiss is the
    // only close, shown once the run finishes). For a preflight / other content, closing is allowed.
    function mayCloseModal() {
        return !activeRun;
    }

    function buildModalNode() {
        var modal = el('div', { class: 'ui modal' });
        closeIconNode = el('i', { class: 'close icon' });
        modal.appendChild(closeIconNode);
        document.body.appendChild(modal);
        if (hasModalPlugin()) {
            window.jQuery(modal).modal({
                closable: true,
                observeChanges: true,
                onHide: mayCloseModal,
                // Keep the run card alive while it owns the modal, so reopening shows its live state.
                // Only clear when no run card is present (e.g. after a preflight is dismissed).
                onHidden: function () { if (!activeRunRef) { clearModalContent(); } }
            });
        }
        return modal;
    }

    // Remove everything except the original (Fomantic-bound) close icon.
    function clearModalContent() {
        if (!modalNode) { return; }
        Array.prototype.slice.call(modalNode.children).forEach(function (child) {
            if (child !== closeIconNode) {
                modalNode.removeChild(child);
            }
        });
    }

    function openModal(narrow) {
        if (!modalNode || !modalNode.parentNode) {
            modalNode = buildModalNode();
        }
        clearModalContent();
        modalNode.classList.remove('wcd-run-modal');   // reset; mountRun re-adds it for the run card
        modalNode.classList.toggle('small', !!narrow);
        if (hasModalPlugin()) {
            var $m = window.jQuery(modalNode);
            if ($m.modal('is active')) { $m.modal('refresh'); }
            else { $m.modal('show'); }
        } else {
            showFallback();
        }
        return modalNode;
    }

    function closeModal() {
        if (!modalNode) { return; }
        if (hasModalPlugin()) {
            window.jQuery(modalNode).modal('hide');
        } else {
            modalNode.classList.remove('active', 'visible');
            if (fallbackDimmer) { fallbackDimmer.classList.remove('active', 'visible'); }
            clearModalContent();
        }
    }

    // Re-show the modal WITHOUT swapping its content. Used to reopen the running/finished run card
    // from the widget's mini-indicator: the card already lives in the modal, we just bring it back.
    function reopenModal() {
        if (!modalNode) { return; }
        if (hasModalPlugin()) {
            var $m = window.jQuery(modalNode);
            if ($m.modal('is active')) { $m.modal('refresh'); } else { $m.modal('show'); }
        } else {
            showFallback();
        }
    }

    // Degraded path when Fomantic's modal JS is missing (it is always present on MainWP admin pages,
    // so this is a safety net). Emulate the dimmer with Fomantic's OWN classes so the modal is still
    // centered and dismissable, without reintroducing any custom modal CSS.
    function showFallback() {
        if (!fallbackDimmer) {
            fallbackDimmer = el('div', { class: 'ui dimmer modals page' });
            fallbackDimmer.addEventListener('click', function (e) {
                if (e.target === fallbackDimmer && mayCloseModal()) { closeModal(); }
            });
            document.body.appendChild(fallbackDimmer);
        }
        fallbackDimmer.appendChild(modalNode);
        fallbackDimmer.classList.add('active', 'visible');
        modalNode.classList.add('active', 'visible');
        if (closeIconNode && !closeIconNode.getAttribute('data-wcd-bound')) {
            closeIconNode.setAttribute('data-wcd-bound', '1');
            closeIconNode.addEventListener('click', function () { if (mayCloseModal()) { closeModal(); } });
        }
    }

    function modalHead(modal, title) {
        var head = el('div', { class: 'header', text: title });
        modal.appendChild(head);
        return head;
    }

    /* ───────────────────────────── Settings page ───────────────────────── */

    function cardOf(node) { return node.closest('.wcd-site'); }
    function siteIdOf(card) { return parseInt(card.getAttribute('data-site-id'), 10); }

    // Flip a Fomantic checkbox from JS: keep the wrapper's `.checked` class in sync with the input,
    // because MainWP's theme paints the toggle track from that class, not from input:checked.
    function setToggleChecked(input, checked) {
        input.checked = checked;
        var wrap = input.closest('.ui.checkbox');
        if (wrap) { wrap.classList.toggle('checked', checked); }
    }

    // Reflect an enable/disable response in the card's DOM (toggle state, row buttons, URL count).
    // Returns a promise that resolves once any queued URL sync has been polled in, so bulk callers
    // can await the full sync before advancing to the next site.
    function applyEnabledState(card, enabled, data) {
        card.classList.toggle('wcd-on', enabled);
        var toggle = card.querySelector('.wcd-site-toggle');
        if (toggle) { setToggleChecked(toggle, enabled); }
        card.querySelectorAll('.wcd-configure-urls, .wcd-site-settings').forEach(function (b) { b.disabled = !enabled; });
        var count = card.querySelector('[data-role="urlcount"]');
        if (enabled) {
            if (data && data.synced) {
                // URLs were sent to sync (queued); poll until they appear, then render.
                return pollUrls(card, 0);
            }
            if (count) { count.textContent = (data && data.sync_message) ? data.sync_message : ''; }
            return Promise.resolve();
        }
        if (count) { count.textContent = (S.disabled || 'Inactive'); }
        var cfgBox = card.querySelector('[data-role="urlconfig"]');
        if (cfgBox) { cfgBox.hidden = true; cfgBox.innerHTML = ''; }
        return Promise.resolve();
    }

    // Server self-heal: a 404 group call means this site's stored mapping is stale (e.g. after an
    // API token switch). The server already forgot its provisioning, so reflect the disabled state in
    // the row and tell the user to re-sync. Returns true when it handled the error.
    function handleUnlinked(card, e) {
        if (card && e && e.data && e.data.unlinked) {
            applyEnabledState(card, false);
            window.alert(e.message);
            return true;
        }
        return false;
    }

    // Swap the card's URL-count cell to an inline spinner while its activation runs. On success the
    // count is rendered by applyEnabledState/pollUrls; on failure the caller restores it via on=false.
    function setSiteSyncing(card, on) {
        var count = card.querySelector('[data-role="urlcount"]');
        if (!count) { return; }
        count.innerHTML = '';
        if (on) {
            count.appendChild(el('div', { class: 'ui active mini inline loader' }));
        } else {
            count.textContent = (S.disabled || 'Inactive');
        }
    }

    function onToggleSite(checkbox) {
        var card = cardOf(checkbox);
        var siteId = siteIdOf(card);
        var enabled = checkbox.checked;
        checkbox.disabled = true;
        // Enabling activates the site and syncs its URLs in the background: show a spinner and
        // raise the leave-page guard until the poll settles. Return the promise so .finally waits.
        if (enabled) { setSiteSyncing(card, true); syncInFlight += 1; }

        api('toggle_site', { site_id: siteId, enabled: enabled ? 1 : 0 }).then(function (data) {
            // toggle_site succeeded: reflect the state and (when enabling) poll URLs in the
            // background. A later poll failure must NOT revert the toggle — the site is enabled
            // server-side — so surface it in the count cell instead (same as the bulk path).
            return applyEnabledState(card, enabled, data).catch(function (e) {
                var count = card.querySelector('[data-role="urlcount"]');
                if (count) { count.textContent = e.message; }
            });
        }).catch(function (e) {
            setToggleChecked(checkbox, !enabled);
            if (enabled) { setSiteSyncing(card, false); }
            window.alert(e.message);
        }).finally(function () {
            checkbox.disabled = false;
            if (enabled) { syncInFlight -= 1; }
        });
    }

    // Build the URL panel skeleton (toolbar + list + pager) once per open. The toolbar persists
    // across page loads so the search input keeps its value and focus. Pagination state lives on
    // the card DOM (data-url-page / data-url-search).
    function ensureUrlPanel(card) {
        var box = card.querySelector('[data-role="urlconfig"]');
        if (box.querySelector('[data-role="urllist"]')) { return box; }
        box.innerHTML = '';

        // Search submits explicitly (Enter / icon / native clear) — no debounce, to keep the
        // requests rare and the flow simple (rapid reloads self-heal: the last response wins).
        var searchInput = el('input', { type: 'search', placeholder: t('searchUrls') });
        searchInput.value = card.getAttribute('data-url-search') || '';
        function submitSearch() {
            card.setAttribute('data-url-search', searchInput.value.trim());
            card.setAttribute('data-url-page', '1');
            loadUrls(card);
        }
        searchInput.addEventListener('keydown', function (e) {
            if ('Enter' === e.key) { e.preventDefault(); submitSearch(); }
        });
        searchInput.addEventListener('search', function () {
            // Native clear (×) only; the guard also stops WebKit's extra `search` event on Enter
            // from double-submitting.
            if ('' === searchInput.value && '' !== (card.getAttribute('data-url-search') || '')) { submitSearch(); }
        });
        var searchIcon = el('i', { class: 'search link icon' });
        searchIcon.addEventListener('click', submitSearch);

        var selectAll = el('div', { class: 'wcd-url-selectall' }, [
            el('span', { class: 'wcd-muted', text: t('selectAll') })
        ]);
        ['desktop', 'mobile'].forEach(function (kind) {
            var input = el('input', { type: 'checkbox' });
            input.addEventListener('change', function () { onSelectAll(card, kind, input); });
            selectAll.appendChild(el('label', {}, [input, ' ' + t(kind)]));
        });

        box.appendChild(el('div', { class: 'wcd-url-toolbar' }, [
            el('div', { class: 'ui mini icon input' }, [searchInput, searchIcon]),
            selectAll
        ]));

        box.appendChild(el('div', { 'data-role': 'urllist' }));
        box.appendChild(el('div', { class: 'wcd-url-pager', 'data-role': 'urlpager' }));
        return box;
    }

    function updateUrlCount(card, active, total) {
        var count = card.querySelector('[data-role="urlcount"]');
        if (count) { count.textContent = active + ' / ' + total; }
    }

    function renderUrlRows(card, urls) {
        var list = card.querySelector('[data-role="urllist"]');
        list.innerHTML = '';
        if (!urls.length) {
            var searching = '' !== (card.getAttribute('data-url-search') || '');
            list.appendChild(el('p', { class: 'wcd-muted', text: t(searching ? 'noResults' : 'noChecks') }));
            return;
        }
        urls.forEach(function (u) {
            var info = el('div', { class: 'wcd-url-info' }, [
                el('div', { class: 'wcd-url-title', text: u.title || u.url }),
                el('div', { class: 'wcd-url-path', text: u.url })
            ]);
            var vp = el('div', { class: 'wcd-vp' });
            ['desktop', 'mobile'].forEach(function (kind) {
                var input = el('input', { type: 'checkbox' });
                input.checked = !!u[kind];
                input.addEventListener('change', function () {
                    var desktop = vp.querySelectorAll('input')[0].checked;
                    var mobile = vp.querySelectorAll('input')[1].checked;
                    api('update_url', { site_id: siteIdOf(card), url_id: u.id, desktop: desktop ? 1 : 0, mobile: mobile ? 1 : 0 })
                        .catch(function (e) {
                            if (handleUnlinked(card, e)) { return; }
                            input.checked = !input.checked; window.alert(e.message);
                        });
                });
                vp.appendChild(el('label', {}, [input, document.createTextNode(' ' + t(kind))]));
            });
            list.appendChild(el('div', { class: 'wcd-url-row' }, [info, vp]));
        });
    }

    // Numbered pages with ellipsis gaps (webapp parity): 1 … c-2 c-1 [c] c+1 c+2 … last.
    // A gap of exactly one page shows that page instead of an ellipsis.
    function urlPageItems(current, last) {
        var items = [];
        var prev = 0;
        for (var p = 1; p <= last; p++) {
            if (1 !== p && last !== p && Math.abs(p - current) > 2) { continue; }
            if (p - prev === 2) { items.push(p - 1); }
            else if (p - prev > 2) { items.push('…'); }
            items.push(p);
            prev = p;
        }
        return items;
    }

    function renderUrlPager(card, data) {
        var pager = card.querySelector('[data-role="urlpager"]');
        pager.innerHTML = '';
        var meta = data.meta || {};
        var current = meta.current_page || 1;
        var lastPage = meta.last_page || 1;
        if (lastPage <= 1) { return; }

        function pageButton(label, page, isCurrent, isDisabled) {
            var btn = el('button', { type: 'button', class: 'ui mini basic button' + (isCurrent ? ' active' : ''), text: label });
            btn.disabled = isCurrent || isDisabled;
            btn.addEventListener('click', function () { gotoUrlPage(card, page); });
            return btn;
        }

        pager.appendChild(pageButton(t('prev'), current - 1, false, current <= 1));
        urlPageItems(current, lastPage).forEach(function (p) {
            if ('…' === p) {
                pager.appendChild(el('span', { class: 'wcd-url-pagegap', text: '…' }));
                return;
            }
            pager.appendChild(pageButton(String(p), p, p === current, false));
        });
        pager.appendChild(pageButton(t('next'), current + 1, false, current >= lastPage));
        pager.appendChild(el('span', {
            class: 'wcd-url-pageinfo',
            text: fmt(t('urlsTotal'), { '%s': data.total })
        }));
    }

    function gotoUrlPage(card, page) {
        card.setAttribute('data-url-page', page);
        loadUrls(card);
    }

    // Shared render path for loadUrls/pollUrls. The header count only updates without a search
    // (a filtered total would misrepresent the site); the unfiltered total is cached on the card
    // so the select-all handler can refresh the count while a search is active.
    function renderUrlPanel(card, data) {
        var box = ensureUrlPanel(card);
        box.hidden = false;
        var search = card.getAttribute('data-url-search') || '';
        var meta = data.meta || {};
        if (!data.urls.length && (meta.current_page || 1) > (meta.last_page || 1)) {
            // The list shrank (e.g. re-sync) while the user sat on a now-out-of-range page.
            card.setAttribute('data-url-page', meta.last_page || 1);
            loadUrls(card);
            return;
        }
        box.querySelector('.wcd-url-toolbar').hidden = ('' === search && 0 === data.total);
        // Select-all targets the WHOLE group, so hide it while a search filters the list — a user
        // looking at 3 matches must not silently enable checks for every URL of the site.
        box.querySelector('.wcd-url-selectall').hidden = ('' !== search);
        renderUrlRows(card, data.urls);
        renderUrlPager(card, data);
        if ('' === search) {
            card.setAttribute('data-url-total', data.total);
            updateUrlCount(card, data.active, data.total);
        }
    }

    // Select-all is a stateless action control: the API has no per-device selected counts, so a
    // derived checked-state would require fetching every URL — exactly what pagination removes.
    // No optimistic UI; the reload after the bulk call shows server truth (also settling any race
    // with an in-flight single toggle: last write wins server-side).
    function onSelectAll(card, kind, toggle) {
        var enabled = toggle.checked;
        if (!enabled && !window.confirm(fmt(t('confirmDisableAll'), { '%s': t(kind) }))) {
            toggle.checked = true;
            return;
        }
        var box = card.querySelector('[data-role="urlconfig"]');
        var controls = box.querySelectorAll('input, button');
        controls.forEach(function (c) { c.disabled = true; });
        var spinner = el('div', { class: 'ui active mini inline loader' });
        toggle.parentNode.appendChild(spinner);
        api('update_all_urls', { site_id: siteIdOf(card), device: kind, enabled: enabled ? 1 : 0 }).then(function (data) {
            if (data && 'undefined' !== typeof data.active) {
                updateUrlCount(card, data.active, card.getAttribute('data-url-total') || data.active);
            }
        }).catch(function (e) {
            if (handleUnlinked(card, e)) { return; }
            toggle.checked = !enabled;
            window.alert(e.message);
        }).finally(function () {
            spinner.remove();
            controls.forEach(function (c) { c.disabled = false; });
            loadUrls(card);
        });
    }

    function loadUrls(card) {
        var box = ensureUrlPanel(card);
        box.hidden = false;
        var list = box.querySelector('[data-role="urllist"]');
        list.innerHTML = '';
        list.appendChild(el('div', { class: 'ui active inline loader' }));
        return api('get_site_urls', {
            site_id: siteIdOf(card),
            page: parseInt(card.getAttribute('data-url-page'), 10) || 1,
            search: card.getAttribute('data-url-search') || ''
        }).then(function (data) {
            renderUrlPanel(card, data);
        }).catch(function (e) {
            if (handleUnlinked(card, e)) { return; }
            list.innerHTML = '';
            list.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        });
    }

    function onConfigureUrls(button) {
        var card = cardOf(button);
        var box = card.querySelector('[data-role="urlconfig"]');
        if (!box.hidden) { box.hidden = true; return; }
        loadUrls(card);
    }

    /* ───────────────────── Per-site On-Demand settings ──────────────────── */
    // One reused server-rendered Fomantic modal (#wcd-site-settings-modal). It is freely closable
    // (its own close icon + Cancel button), so it does NOT go through the run-only modal infra. The
    // JS fills the fields from get_site_settings on open and writes them via save_site_settings.

    // Placeholder shown in the password field when a password is already stored (the API never
    // returns the real one). Mirrors the webapp's dots convention: leave the dots to keep the stored
    // password, clear the field to remove it, type a new value to replace it. The sentinel logic
    // lives ONLY here (in JS); the save endpoint stays contract-simple (set / clear / omit).
    var PWD_SENTINEL = '••••••••';

    function settingsModal() { return document.getElementById('wcd-site-settings-modal'); }

    function showSettingsModal(modal) {
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(modal).modal({ closable: true, observeChanges: true }).modal('show');
        } else {
            modal.classList.add('active', 'visible');
        }
    }

    function hideSettingsModal(modal) {
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(modal).modal('hide');
        } else {
            modal.classList.remove('active', 'visible');
        }
    }

    function settingsField(modal, name) {
        return modal.querySelector('[name="' + name + '"]');
    }

    function setCheckbox(input, checked) {
        if (!input) { return; }
        input.checked = !!checked;
        var wrap = input.closest('.ui.checkbox');
        if (wrap) { wrap.classList.toggle('checked', !!checked); }
    }

    // Fill the modal fields from the server payload. has_basic_auth drives the "password is stored"
    // hint + the sentinel dots in the password field (the password itself is never returned). The
    // prior "was set" state is recorded on the modal for the save decision.
    function fillSettings(modal, data) {
        var region = settingsField(modal, 'screenshot_region');
        if (region) { region.value = data.screenshot_region || 'auto'; }
        setCheckbox(settingsField(modal, 'default_desktop'), data.default_desktop);
        setCheckbox(settingsField(modal, 'default_mobile'), data.default_mobile);
        var threshold = settingsField(modal, 'threshold');
        if (threshold) { threshold.value = (data.threshold !== undefined && data.threshold !== null) ? data.threshold : ''; }
        var alertEmails = settingsField(modal, 'alert_emails');
        if (alertEmails) { alertEmails.value = data.alert_emails || ''; }
        var authUser = settingsField(modal, 'basic_auth_user');
        if (authUser) { authUser.value = data.basic_auth_user || ''; }
        // A stored password shows the sentinel dots (leave to keep, clear to remove, type to replace).
        var authPass = settingsField(modal, 'basic_auth_password');
        if (authPass) { authPass.value = data.has_basic_auth ? PWD_SENTINEL : ''; }
        modal.setAttribute('data-password-set', data.has_basic_auth ? '1' : '0');
        setPasswordSetState(modal, !!data.has_basic_auth);
        setCheckbox(settingsField(modal, 'proxy_on'), data.proxy_on);
        var delay = settingsField(modal, 'screenshot_delay');
        if (delay) { delay.value = (data.screenshot_delay !== undefined && data.screenshot_delay !== null) ? data.screenshot_delay : ''; }
        var css = settingsField(modal, 'css');
        if (css) { css.value = data.css || ''; }
        var js = settingsField(modal, 'js');
        if (js) { js.value = data.js || ''; }
    }

    function setPasswordSetState(modal, isSet) {
        var hint = modal.querySelector('[data-role="passwordset"]');
        if (hint) { hint.hidden = !isSet; }
    }

    function onSiteSettings(button) {
        var card = cardOf(button);
        var siteId = siteIdOf(card);
        var modal = settingsModal();
        if (!modal) { return; }

        modal.setAttribute('data-site-id', String(siteId));
        // The modal element is reused for every site, so the previous site's values are still in
        // the fields until fillSettings() overwrites them. data-loaded marks "these fields belong
        // to data-site-id"; it is cleared on every open and set only after a successful fill, and
        // onSaveSiteSettings() refuses to save without it. Hiding the form alone would not do:
        // the Save button lives in the modal's .actions block, outside the form.
        modal.removeAttribute('data-loaded');
        var loading = modal.querySelector('[data-role="loading"]');
        var form = modal.querySelector('[data-role="form"]');
        var error = modal.querySelector('[data-role="error"]');
        var save = modal.querySelector('.wcd-settings-save');
        // The Save control is type="button" and the JS reads fields by name (it never submits the
        // form), so block any real form submission once: Enter must never trigger a full page reload,
        // even if the field count ever drops below the browser's implicit-submit suppression.
        if (form && !form.getAttribute('data-wcd-submit-guard')) {
            form.setAttribute('data-wcd-submit-guard', '1');
            form.addEventListener('submit', function (ev) { ev.preventDefault(); });
        }
        if (error) { error.hidden = true; error.textContent = ''; }
        if (loading) { loading.hidden = false; }
        if (form) { form.hidden = true; }
        if (save) { save.disabled = true; }
        showSettingsModal(modal);

        api('get_site_settings', { site_id: siteId }).then(function (data) {
            // Ignore a response that no longer belongs to the site the modal shows: the user can
            // close this modal mid-load and reopen it for another site, and filling site A's values
            // into site B's form (data-loaded included) would silently save them onto B's group.
            if (String(siteId) !== modal.getAttribute('data-site-id')) { return; }
            fillSettings(modal, data);
            modal.setAttribute('data-loaded', '1');
            if (loading) { loading.hidden = true; }
            if (form) { form.hidden = false; }
            if (save) { save.disabled = false; }
            // Init the accordion (collapsed) once the form is visible.
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.accordion === 'function') {
                window.jQuery(modal).find('.wcd-settings-advanced').accordion({ exclusive: false });
            }
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdown === 'function') {
                window.jQuery(modal).find('.ui.dropdown').dropdown();
            }
        }).catch(function (e) {
            // Same stale-response guard as above: a late failure for a previous site must not close
            // the modal or show its error over the form another site has already loaded.
            if (String(siteId) !== modal.getAttribute('data-site-id')) { return; }
            if (handleUnlinked(card, e)) { hideSettingsModal(modal); return; }
            // Form and Save stay locked: the fields were never filled for this site, so saving
            // them would write empty or previous-site values (an empty alert_emails clears the
            // recipient list, an empty threshold/basic auth overwrites the stored ones).
            if (loading) { loading.hidden = true; }
            if (error) { error.hidden = false; error.textContent = e.message; }
        });
    }

    function onSaveSiteSettings(button) {
        var modal = settingsModal();
        if (!modal) { return; }
        var siteId = parseInt(modal.getAttribute('data-site-id'), 10) || 0;
        if (!siteId) { return; }
        // Refuse to save fields that were never loaded for this site (see onSiteSettings).
        if ('1' !== modal.getAttribute('data-loaded')) { return; }
        var card = document.querySelector('.wcd-site[data-site-id="' + siteId + '"]');
        var error = modal.querySelector('[data-role="error"]');
        if (error) { error.hidden = true; error.textContent = ''; }

        var region = settingsField(modal, 'screenshot_region');
        var threshold = settingsField(modal, 'threshold');
        var alertEmails = settingsField(modal, 'alert_emails');
        var authUser = settingsField(modal, 'basic_auth_user');
        var authPass = settingsField(modal, 'basic_auth_password');
        var delay = settingsField(modal, 'screenshot_delay');
        var css = settingsField(modal, 'css');
        var js = settingsField(modal, 'js');

        var payload = {
            site_id: siteId,
            screenshot_region: region ? region.value : 'auto',
            default_desktop: (settingsField(modal, 'default_desktop') || {}).checked ? 1 : 0,
            default_mobile: (settingsField(modal, 'default_mobile') || {}).checked ? 1 : 0,
            threshold: threshold ? threshold.value : '',
            // Always sent: an emptied field clears the recipient list server-side.
            alert_emails: alertEmails ? alertEmails.value : '',
            basic_auth_user: authUser ? authUser.value : '',
            proxy_on: (settingsField(modal, 'proxy_on') || {}).checked ? 1 : 0,
            screenshot_delay: delay ? delay.value : '',
            css: css ? css.value : '',
            js: js ? js.value : ''
        };
        // Password (dots convention; sentinel logic lives only here, the API stays set/clear/omit):
        //   was set + field still the sentinel  -> unchanged -> omit the key
        //   was set + field emptied             -> clear     -> send ''
        //   field holds a new value             -> set       -> send that value
        //   was not set + field empty           -> nothing   -> omit
        var wasSet = modal.getAttribute('data-password-set') === '1';
        var passValue = authPass ? authPass.value : '';
        if (wasSet) {
            if (passValue !== PWD_SENTINEL) { payload.basic_auth_password = passValue; }
        } else if (passValue !== '') {
            payload.basic_auth_password = passValue;
        }

        button.disabled = true;
        api('save_site_settings', payload).then(function () {
            // Same stale-response guard as the load path: the modal stays closable while the save
            // is in flight, so site A's late success would otherwise tear down the modal site B has
            // meanwhile opened.
            if (String(siteId) !== modal.getAttribute('data-site-id')) { return; }
            hideSettingsModal(modal);
        }).catch(function (e) {
            // Same reason: site A's failure must neither close site B's modal nor print site A's
            // error message over the form site B has loaded.
            if (String(siteId) !== modal.getAttribute('data-site-id')) { return; }
            if (card && handleUnlinked(card, e)) { hideSettingsModal(modal); return; }
            if (error) { error.hidden = false; error.textContent = e.message; }
        }).finally(function () {
            // Guarded too, because the Save button belongs to the one reused modal: re-enabling it
            // for site A would hand site B a second submit while B's own save is still running.
            // Reopening the modal always resets the button, so skipping this cannot strand it.
            if (String(siteId) !== modal.getAttribute('data-site-id')) { return; }
            button.disabled = false;
        });
    }

    // start-sync is queued server-side; poll the group URLs until they appear.
    function pollUrls(card, tries) {
        card.setAttribute('data-url-page', '1');
        card.setAttribute('data-url-search', '');
        var searchInput = card.querySelector('[data-role="urlconfig"] input[type="search"]');
        if (searchInput) { searchInput.value = ''; }
        return api('get_site_urls', { site_id: siteIdOf(card), page: 1 }).then(function (data) {
            if (data.total > 0 || tries >= 10) {
                renderUrlPanel(card, data);
                return;
            }
            return delay(2000).then(function () { return pollUrls(card, tries + 1); });
        });
    }

    /* ──────────────────────── Activate all websites ─────────────────────── */
    // Activating = make a site ready for checks (enable + sync its URLs). The browser drives one
    // site per request (the same pattern as the safe-update flow), so a large fleet never stacks
    // slow WCD API calls into a single PHP request, and the user sees live progress.

    function allSiteCards() {
        return Array.prototype.slice.call(document.querySelectorAll('.wcd-site'));
    }

    // Render the bulk bar status: an optional spinner (while running) plus a progress message.
    function setBulkProgress(text, busy) {
        var node = document.querySelector('[data-role="bulkprogress"]');
        if (!node) { return; }
        node.innerHTML = '';
        if (busy) { node.appendChild(el('div', { class: 'ui active inline loader mini' })); }
        if (text) { node.appendChild(document.createTextNode(' ' + text)); }
    }

    // Activate one site: enable it (auto-syncs its URLs) when off, otherwise re-sync its URLs.
    function activateOneSite(card) {
        var siteId = siteIdOf(card);
        // The URL poll is best-effort: once the site is enabled / the sync is dispatched
        // server-side the activation has succeeded, so a later poll failure is surfaced in the
        // count cell (and a 404 mapping self-heals) instead of being counted as a failed site —
        // same non-fatal semantics as onToggleSite.
        function pollSettled(p) {
            return p.catch(function (e) {
                if (handleUnlinked(card, e)) { return; }
                var count = card.querySelector('[data-role="urlcount"]');
                if (count) { count.textContent = e.message; }
            });
        }
        if (card.classList.contains('wcd-on')) {
            return api('sync_urls', { site_id: siteId }).then(function () { return pollSettled(pollUrls(card, 0)); });
        }
        return api('toggle_site', { site_id: siteId, enabled: 1 }).then(function (data) {
            return pollSettled(applyEnabledState(card, true, data));
        });
    }

    // Sequentially activate a list of cards, locking the controls and reporting progress + failures.
    function onActivateAll() {
        var cards = allSiteCards();
        if (!cards.length) { return; }
        if (!window.confirm(t('bulkSyncConfirm'))) { return; }

        var total = cards.length;
        var done = 0;
        var failed = 0;
        var controls = document.querySelectorAll('.wcd-activate-all, .wcd-site-toggle');
        controls.forEach(function (c) { c.disabled = true; });
        syncInFlight += 1;

        var chain = Promise.resolve();
        cards.forEach(function (card, i) {
            chain = chain.then(function () {
                setBulkProgress(t('bulkSyncProgress').replace('%1$d', i + 1).replace('%2$d', total), true);
                setSiteSyncing(card, true);
                return activateOneSite(card).then(function () {
                    done += 1;
                }).catch(function (e) {
                    failed += 1;
                    var count = card.querySelector('[data-role="urlcount"]');
                    if (count) { count.textContent = e.message; }
                });
            });
        });

        chain.finally(function () {
            var msg = t('bulkSyncDone').replace('%1$d', done).replace('%2$d', total);
            if (failed > 0) { msg += ' ' + t('bulkSyncFailed').replace('%d', failed); }
            setBulkProgress(msg, false);
            controls.forEach(function (c) { c.disabled = false; });
            syncInFlight -= 1;
        });
    }

    /* ─────────────────────────── Safe-update flow ──────────────────────── */
    // Entry is our own "Run visual check & update" button, so the run is always WITH WebChange
    // Detector (no with/without decision step). The button goes straight to the preflight.

    // updatesScope (optional) carries the Updates-page bar's scoping: { update_type, mode,
    // selection }. mode 'all' sends only the type (the server derives sites + items from the
    // pending-update columns); 'selected' sends the checkbox selection map.
    function preflightArgs(scope, siteId, updatesScope) {
        var args = scope === 'site' && siteId ? { site_id: siteId } : {};
        if (updatesScope && updatesScope.update_type) {
            args.update_type = updatesScope.update_type;
            if ('all' === updatesScope.mode) { args.mode = 'all'; }
            else if (updatesScope.selection) { args.selection = updatesScope.selection; }
        }
        return args;
    }

    // Locate the in-card run host (the reopen-button fallback slot). Overview pages can host
    // more than one (our own widget + the WCD Updates card in MainWP's native Updates Overview
    // widget); all hosts are equivalent fallbacks, the first one in the DOM wins.
    function runHostFor() {
        return document.querySelector('.wcd-run-host');
    }

    /* ── Updates-page selection reader (Update Selected with Checks) ──────── */
    // MainWP marks each selectable update row with updated="0" and a `.child.checkbox`; the site id
    // and the item slug live on the row itself or an ancestor (tr/tbody), which covers all three
    // view modes (Per Site / Per Group / Per Item) via closest(). plugin_slug/theme_slug are
    // rawurlencode()d by MainWP, so they MUST be decoded or the server-side slug filter matches
    // nothing; translation_slug is plain. Core rows carry no slug (site-level selection).

    var UPDATES_SLUG_ATTRS = { plugins: 'plugin_slug', themes: 'theme_slug', translations: 'translation_slug' };
    var UPDATES_SLUG_ENCODED = { plugins: true, themes: true, translations: false };

    // Site Updates subpage: native tab name (data-tab) -> our update type. Tabs we cannot
    // safe-update (abandoned plugins/themes, database updates) are absent on purpose.
    var SITE_TAB_TYPES = { wordpress: 'core', plugins: 'plugins', themes: 'themes', translations: 'translations' };

    // Returns the selection map { siteId: [slugs] } for one update type. Scope: the explicit
    // `root` element when given (site subpage: the ACTIVE tab, since all type tables are in the
    // DOM at once there), else the bar's enclosing tab (global Updates page: exactly one tab
    // renders per pageload). Duplicate rows (Per Group repeats a site's rows per group) are
    // deduped per site.
    function readUpdatesSelection(bar, updateType, root) {
        var tab = root || (bar && bar.closest && bar.closest('.ui.tab')) || document;
        var attr = UPDATES_SLUG_ATTRS[updateType] || '';
        var sites = {};
        tab.querySelectorAll('tr[updated="0"]').forEach(function (row) {
            var box = row.querySelector('.child.checkbox input[type="checkbox"]');
            if (!box || !box.checked) { return; }
            var siteEl = row.closest('[site_id]');
            var siteId = siteEl ? parseInt(siteEl.getAttribute('site_id'), 10) : 0;
            if (!siteId) { return; }
            if (!attr) {
                // core: the site itself is the selection (no slugs).
                if (!sites[siteId]) { sites[siteId] = []; }
                return;
            }
            var slugEl = row.closest('[' + attr + ']');
            var slug = slugEl ? (slugEl.getAttribute(attr) || '') : '';
            if (UPDATES_SLUG_ENCODED[updateType]) {
                try { slug = decodeURIComponent(slug); } catch (e) { /* keep the raw value */ }
            }
            // Only a resolved slug may create the site's bucket: an empty slugs list would mean
            // "all items of this type" server-side, silently escalating the selection.
            if (!slug) { return; }
            if (!sites[siteId]) { sites[siteId] = []; }
            if (sites[siteId].indexOf(slug) === -1) { sites[siteId].push(slug); }
        });
        return sites;
    }

    // The site Updates subpage's currently active tab name (data-tab). One resolution path for
    // both the click-time type lookup and the bar's initial visibility: the native tab menu item
    // first (scoped, immune to third-party .ui.tab elements), the active tab pane as fallback.
    function activeSiteTabName() {
        var active = document.querySelector('.select-individual-updates .item.active')
            || document.querySelector('.ui.tab.active[data-tab]');
        return active ? (active.getAttribute('data-tab') || '') : '';
    }

    // Site Updates subpage only (the inline bar marker exists there): keep the bar in sync with
    // the native client-side tab switcher. On tabs we cannot safe-update (abandoned plugins/
    // themes, database updates) the WHOLE bar hides; on updatable tabs "Update Selected with
    // Checks" only shows where a checkbox table exists (plugins/themes; core and translations
    // have none on this subpage, matching the native Selected buttons). Uses the `hidden` class
    // like the native buttons (a small own CSS rule makes it stick against Fomantic's .ui.button
    // display).
    function initSiteUpdatesBar() {
        var bar = document.querySelector('.wcd-updates-bar--inline');
        if (!bar) { return; }
        var selectedBtn = bar.querySelector('.wcd-updates-run[data-mode="selected"]');
        var allBtn = bar.querySelector('.wcd-updates-run[data-mode="all"]');

        function applyTab(tabName) {
            var type = SITE_TAB_TYPES[tabName] || '';
            var hasCheckboxes = 'plugins' === tabName || 'themes' === tabName;
            bar.classList.toggle('hidden', !type);
            if (selectedBtn) { selectedBtn.classList.toggle('hidden', !hasCheckboxes); }
            if (allBtn) { allBtn.classList.toggle('hidden', !type); }
        }

        // The native switcher is a Fomantic dropdown whose items carry data-tab; delegate so the
        // toggle also works after Fomantic re-renders the menu.
        document.addEventListener('click', function (e) {
            var item = e.target.closest && e.target.closest('.select-individual-updates .item');
            if (item) { applyTab(item.getAttribute('data-tab') || ''); }
        });

        applyTab(activeSiteTabName());
    }

    function plural(n, one, many) { return 1 === n ? one : many; }

    function initials(name) {
        var m = (name || '').replace(/^https?:\/\//, '').match(/[a-z0-9]/gi);
        return m ? (m[0] + (m[1] || '')).toUpperCase() : '?';
    }

    function fmt(str, map) {
        return Object.keys(map).reduce(function (s, k) { return s.split(k).join(map[k]); }, str);
    }

    // Confirm-only preflight: a summary strip, credit coverage, an expandable update list and the
    // per-site URL list. On confirm the modal closes and the run plays out in the in-card host.
    function runPreflight(trigger, scope, siteId, updatesScope) {
        var host  = runHostFor();
        var modal = openModal();
        modalHead(modal, t('preflightTitle'));
        var body = el('div', { class: 'scrolling content' });
        body.appendChild(el('div', { class: 'ui active inline loader' }));
        modal.appendChild(body);

        api('preflight', preflightArgs(scope, siteId, updatesScope)).then(function (data) {
            body.innerHTML = '';
            var sites = data.sites || [];
            // Only sites with pending updates participate in the run; a missing flag (older
            // server response) fails open so the site is still included.
            var runSites   = sites.filter(function (s) { return false !== s.has_updates; });
            var checkSites = runSites.filter(function (s) { return s.checks > 0; });
            if (sites.length && !runSites.length) {
                body.appendChild(el('p', { class: 'wcd-muted', text: t('noEligibleUpdates') }));
                return;
            }
            if (!checkSites.length) {
                body.appendChild(el('p', { class: 'wcd-muted', text: t('noSites') }));
                return;
            }
            buildPreflight(modal, body, data, checkSites, runSites, sites, host, trigger, updatesScope);
        }).catch(function (e) {
            body.innerHTML = '';
            body.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        });
    }

    /* ───────────────────────── Running + results ───────────────────────── */

    /* width helpers: dynamic widths use the .wcd-w-* step utilities (no inline styles) */
    function snapWidth(pct) { return Math.max(0, Math.min(100, Math.round((Number(pct) || 0) / 5) * 5)); }
    function setWidth(node, pct) {
        node.className = node.className.replace(/\bwcd-w-\d+\b/g, '').replace(/\s+/g, ' ').trim() + ' wcd-w-' + snapWidth(pct);
    }
    function widthEl(cls, pct) { return el('div', { class: cls + ' wcd-w-' + snapWidth(pct) }); }

    /* ─── Preflight content (summary · credits · updates · per-site URLs) ── */

    // runSites = sites with pending updates (the run set); allSites additionally holds the
    // skipped sites without updates, rendered greyed out for transparency (display only).
    // updatesScope (optional) is the Updates-page bar scoping, handed on to the run.
    function buildPreflight(modal, body, data, checkSites, runSites, allSites, host, trigger, updatesScope) {
        body.appendChild(el('p', { class: 'wcd-pf-lead', text: t('preflightLead') }));

        // summary strip
        var strip = el('div', { class: 'wcd-pf-summary' });
        // Sites = the run set (matches the confirm button); the "unchecked" note below explains
        // run sites that stay live without screenshots.
        [[t('sites'), runSites.length], [t('pages'), data.pages], [t('checks'), data.checks]].forEach(function (pair, i) {
            strip.appendChild(el('div', { class: 'wcd-pf-stat' + (2 === i ? ' is-accent' : '') }, [
                el('div', { class: 'wcd-pf-statnum', text: String(pair[1]) }),
                el('div', { class: 'wcd-pf-statlbl', text: pair[0] })
            ]));
        });
        body.appendChild(strip);

        // credit coverage
        var credits = data.credits || {};
        var enough  = !!data.enough;
        if (credits.checks_left !== null && credits.checks_left !== undefined) {
            var left  = Number(credits.checks_left) || 0;
            var limit = Number(credits.checks_limit) || 0;
            var used  = credits.checks_done !== null && credits.checks_done !== undefined ? Number(credits.checks_done) : Math.max(0, limit - left);
            var usedPct = limit ? (used / limit * 100) : 0;
            var runPct  = limit ? (Math.min(left, data.checks) / limit * 100) : 0;
            var bar = el('div', { class: 'wcd-pf-creditbar' }, [
                widthEl('wcd-pf-creditused', usedPct),
                widthEl('wcd-pf-creditrun' + (enough ? '' : ' is-low'), runPct)
            ]);
            var usage = fmt(t('creditUsage'), { '%1$d': data.checks, '%2$d': left, '%3$d': limit });
            var pill  = enough
                ? el('span', { class: 'wcd-pf-creditpill is-enough', text: t('enoughCredits') })
                : el('span', { class: 'wcd-pf-creditpill is-low', text: fmt(t('creditShort'), { '%d': Math.max(0, data.checks - left) }) });
            body.appendChild(el('div', { class: 'wcd-pf-credit' + (enough ? '' : ' is-low') }, [
                el('div', { class: 'wcd-pf-credittext' }, [
                    el('div', { text: usage }),
                    credits.plan_name ? el('div', { class: 'wcd-muted', text: 'Plan: ' + credits.plan_name }) : null
                ]),
                bar,
                pill
            ]));
        }

        // what gets updated (expandable)
        if (data.total_updates > 0) {
            var updBody = el('div', { class: 'wcd-pf-updatesbody' });
            updBody.hidden = true;
            allSites.filter(function (s) { return s.updates && s.updates.total > 0; }).forEach(function (s) {
                var row = el('div', { class: 'wcd-pf-updrow' }, [el('span', { class: 'wcd-pf-updsite', text: s.name })]);
                (s.updates.items || []).forEach(function (it) {
                    row.appendChild(el('span', { class: 'wcd-pf-chip' }, [
                        document.createTextNode(it.name),
                        it.version ? el('span', { class: 'wcd-pf-ver', text: it.version }) : null
                    ]));
                });
                updBody.appendChild(row);
            });
            var toggle = el('button', { class: 'wcd-pf-updtoggle', type: 'button' }, [
                el('i', { class: 'sync icon' }),
                el('span', { text: data.total_updates + ' ' + t('updatesToInstall') }),
                el('i', { class: 'chevron down icon wcd-pf-updchev' })
            ]);
            toggle.addEventListener('click', function () {
                updBody.hidden = !updBody.hidden;
                toggle.querySelector('.wcd-pf-updchev').className = 'chevron ' + (updBody.hidden ? 'down' : 'up') + ' icon wcd-pf-updchev';
            });
            body.appendChild(el('div', { class: 'wcd-pf-updates' }, [toggle, updBody]));
        }

        // Per-site URL list: lazy. The preflight only carries counts; each site's URLs load on
        // first expand (same pattern as the webapp's website accordion), so the popup opens fast
        // even with many websites.
        var list = el('div', { class: 'wcd-pf-urls' });
        checkSites.forEach(function (s) {
            list.appendChild(buildPreflightSite(s));
        });
        // Run sites without visual checks activated (wcd_enabled === false, Updates-page selection
        // flow): static badged rows. They ARE updated, but never get pre/post screenshots (their
        // checks are 0, so take_pre/take_post and the credit math exclude them automatically).
        var disabledSites = runSites.filter(function (s) { return false === s.wcd_enabled; });
        disabledSites.forEach(function (s) {
            list.appendChild(el('div', { class: 'wcd-pf-site' }, [
                el('div', { class: 'wcd-pf-urlshead is-skipped' }, [
                    el('span', { class: 'wcd-run__sitemark', text: initials(s.name) }),
                    el('span', { class: 'wcd-pf-urlname', text: s.name }),
                    el('span', { class: 'wcd-pf-urlcount', text: t('checksNotActivated') })
                ])
            ]));
        });
        // Skipped sites (no pending updates): static greyed rows without accordion behavior;
        // they are not part of the run, the badge explains why.
        allSites.filter(function (s) { return false === s.has_updates; }).forEach(function (s) {
            list.appendChild(el('div', { class: 'wcd-pf-site' }, [
                el('div', { class: 'wcd-pf-urlshead is-skipped' }, [
                    el('span', { class: 'wcd-run__sitemark', text: initials(s.name) }),
                    el('span', { class: 'wcd-pf-urlname', text: s.name }),
                    el('span', { class: 'wcd-pf-urlcount', text: t('noUpdatesBadge') })
                ])
            ]));
        });
        body.appendChild(list);

        // Sites whose check counts could not be loaded (API hiccup): they would be updated
        // WITHOUT visual checks, so say it loudly instead of hiding them among the unchecked.
        var metaErrors = runSites.filter(function (s) { return s.meta_error; }).length;
        if (metaErrors > 0) {
            body.appendChild(el('p', { class: 'wcd-error wcd-pf-note' }, [
                el('i', { class: 'exclamation triangle icon' }),
                document.createTextNode(' ' + fmt(1 === metaErrors ? t('metaErrorSingle') : t('metaErrorPlural'), { '%d': String(metaErrors) }))
            ]));
        }

        // unchecked note: ENABLED run sites with zero selected checks (they stay live without
        // screenshots). Sites without activated checks are excluded: they already carry their own
        // "Checks not activated" badge above, so counting them here would double-report them.
        var unchecked = runSites.length - checkSites.length - disabledSites.length;
        if (unchecked > 0) {
            body.appendChild(el('p', { class: 'wcd-muted wcd-pf-note' }, [
                el('i', { class: 'info circle icon' }),
                document.createTextNode(' ' + unchecked + ' ' + plural(unchecked, t('site'), t('sitesPlural')) + ' ' + t('liveNote'))
            ]));
        }

        // footer
        var foot = el('div', { class: 'actions' });
        foot.appendChild(el('span', { class: 'wcd-pf-footnote wcd-muted' }, [el('i', { class: 'info circle icon' }), document.createTextNode(' ' + t('liveNote'))]));
        var cancel = el('button', { class: 'ui button', type: 'button', text: t('cancel') });
        cancel.addEventListener('click', closeModal);
        foot.appendChild(cancel);
        if (!enough && cfg.upgradeUrl) {
            foot.appendChild(el('a', { class: 'ui button', href: cfg.upgradeUrl, target: '_blank', rel: 'noopener', text: t('upgradePlan') }));
        }
        var confirm = el('button', { class: 'ui blue button', type: 'button' }, [
            el('i', { class: 'play icon' }),
            document.createTextNode(t('confirmRun') + ' ' + runSites.length + ' ' + plural(runSites.length, t('site'), t('sitesPlural')))
        ]);
        confirm.disabled = !enough;
        // The run card replaces the preflight in the SAME (already open) modal; the modal stays open.
        confirm.addEventListener('click', function () { startRun(host, trigger, runSites, updatesScope); });
        foot.appendChild(confirm);
        modal.appendChild(foot);
    }

    // One collapsible preflight site row: header (mark, name, page count, chevron) + a body that
    // fetches the site's selected URLs once, on first expand.
    function buildPreflightSite(s) {
        var chev = el('i', { class: 'chevron down icon wcd-pf-urlchev' });
        var head = el('button', { class: 'wcd-pf-urlshead', type: 'button' }, [
            el('span', { class: 'wcd-run__sitemark', text: initials(s.name) }),
            el('span', { class: 'wcd-pf-urlname', text: s.name }),
            el('span', { class: 'wcd-pf-urlcount', text: s.pages + ' ' + plural(s.pages, t('page'), t('pagesPlural')) }),
            chev
        ]);
        var urlsBody = el('div', { class: 'wcd-pf-urlbody' });
        urlsBody.hidden = true;

        head.addEventListener('click', function () {
            urlsBody.hidden = !urlsBody.hidden;
            chev.className = 'chevron ' + (urlsBody.hidden ? 'down' : 'up') + ' icon wcd-pf-urlchev';
            if (urlsBody.hidden || urlsBody.getAttribute('data-loaded')) { return; }
            urlsBody.setAttribute('data-loaded', '1');
            urlsBody.appendChild(el('div', { class: 'ui active inline loader' }));
            api('get_site_urls', { site_id: s.site_id }).then(function (d) {
                urlsBody.innerHTML = '';
                (d.urls || []).filter(function (u) { return u.desktop || u.mobile; }).forEach(function (u) {
                    var vps = el('div', { class: 'wcd-pf-vps' });
                    if (u.desktop) { vps.appendChild(el('span', { class: 'wcd-pf-vp' }, [el('i', { class: 'desktop icon' }), document.createTextNode(t('desktop'))])); }
                    if (u.mobile) { vps.appendChild(el('span', { class: 'wcd-pf-vp' }, [el('i', { class: 'mobile icon' }), document.createTextNode(t('mobile'))])); }
                    urlsBody.appendChild(el('div', { class: 'wcd-url-row' }, [
                        el('div', { class: 'wcd-url-info' }, [
                            el('div', { class: 'wcd-url-title', text: u.title || u.url }),
                            el('div', { class: 'wcd-url-path', text: u.url })
                        ]),
                        vps
                    ]));
                });
                if (!urlsBody.children.length) { urlsBody.appendChild(el('p', { class: 'wcd-muted', text: t('noChecks') })); }
            }).catch(function (e) {
                // Allow a retry on the next expand.
                urlsBody.removeAttribute('data-loaded');
                urlsBody.innerHTML = '';
                urlsBody.appendChild(el('p', { class: 'wcd-error', text: e.message }));
            });
        });

        return el('div', { class: 'wcd-pf-site' }, [head, urlsBody]);
    }

    /* ─── Unified in-card run (PRE → UPDATES → POST → DONE, phased) ──────── */
    // One card for the whole run: a timeline, aggregate Pre/Post Queue/Processing/Done/Failed panels,
    // a processing-only Updates panel, a per-site list, and a results footer. The browser orchestrates
    // the phases as barriers so every site moves through PRE → UPDATES → POST together.

    var RUN_STEPS = [
        { id: 'pre',    lbl: 'phasePre',     verb: 'verbPre' },
        { id: 'update', lbl: 'phaseUpdates', verb: 'verbUpdate' },
        { id: 'post',   lbl: 'phasePost',    verb: 'verbPost' },
        { id: 'done',   lbl: 'phaseDone',    verb: 'verbDone' }
    ];
    var SITE_STATUS = ['statusPre', 'statusUpdating', 'statusPost', 'statusComparing'];

    function shotPanel(titleKey) {
        var pill = el('span', { class: 'wcd-run__statepill' });
        var cells = {};
        var grid = el('div', { class: 'wcd-run__cells' });
        [['queue', 'queue'], ['processing', 'processing'], ['done', 'doneCount'], ['failed', 'failed']].forEach(function (c) {
            var val = el('div', { class: 'wcd-run__cellval', text: '0' });
            cells[c[0]] = val;
            grid.appendChild(el('div', { class: 'wcd-run__cell' }, [el('div', { class: 'wcd-run__celllbl', text: t(c[1]) }), val]));
        });
        var node = el('div', { class: 'wcd-run__panel' }, [
            el('div', { class: 'wcd-run__panelhead' }, [
                el('i', { class: 'camera icon' }),
                el('span', { class: 'wcd-run__paneltitle', text: t(titleKey) }),
                pill
            ]),
            grid
        ]);
        return { node: node, pill: pill, cells: cells };
    }

    function updPanel() {
        var pill = el('span', { class: 'wcd-run__statepill' });
        var proc = el('div', { class: 'wcd-run__cellval', text: '0' });
        var node = el('div', { class: 'wcd-run__panel wcd-run__panel--upd' }, [
            el('div', { class: 'wcd-run__panelhead' }, [
                el('i', { class: 'sync icon' }),
                el('span', { class: 'wcd-run__paneltitle', text: t('panelUpdates') }),
                pill
            ]),
            el('div', { class: 'wcd-run__updbody' }, [
                el('div', { class: 'wcd-run__celllbl', text: t('processing') }),
                proc,
                el('div', { class: 'wcd-run__updsub', text: t('updatesUnit') })
            ])
        ]);
        return { node: node, pill: pill, proc: proc };
    }

    function setStatePill(pill, state, mode) {
        pill.className = 'wcd-run__statepill is-' + state;
        if ('upd' === mode) {
            pill.textContent = 'done' === state ? t('installed') : 'active' === state ? t('installing') : t('queued');
        } else {
            pill.textContent = 'done' === state ? t('captured') : 'active' === state ? t('capturing') : t('queued');
        }
    }

    function setShot(panel, c) {
        panel.cells.queue.textContent = String(c.queue || 0);
        panel.cells.processing.textContent = String(c.processing || 0);
        panel.cells.done.textContent = String(c.done || 0);
        panel.cells.failed.textContent = String(c.failed || 0);
    }

    function buildRun(host, sites, checkSites, single, total) {
        var pill = el('span', { class: 'wcd-run__pill is-running' }, [el('i', { class: 'sync loading icon' }), document.createTextNode(' ' + t('running'))]);
        var sub  = el('div', { class: 'wcd-run__sub' });
        var dismiss = el('button', { class: 'wcd-run__dismiss', type: 'button' }, [el('i', { class: 'times icon' })]);
        dismiss.disabled = true;
        var head = el('div', { class: 'wcd-run__head' }, [
            el('div', { class: 'wcd-run__icon' }, [el('i', { class: 'eye icon' })]),
            el('div', { class: 'wcd-run__headmain' }, [
                el('div', { class: 'wcd-run__titlerow' }, [
                    el('span', { class: 'wcd-run__title', text: single ? sites[0].name : t('runningTitle') }),
                    pill
                ]),
                sub
            ]),
            dismiss
        ]);

        var stepNodes = {};
        var stepsWrap = el('div', { class: 'wcd-run__steps' });
        RUN_STEPS.forEach(function (st) {
            var node = el('div', { class: 'wcd-run__step' }, [el('span', { class: 'wcd-run__node' }), el('span', { class: 'wcd-run__steplbl', text: t(st.lbl) })]);
            stepNodes[st.id] = node;
            stepsWrap.appendChild(node);
        });
        var fill = el('div', { class: 'wcd-run__fill wcd-w-0' });
        var timeline = el('div', { class: 'wcd-run__timeline' }, [el('div', { class: 'wcd-run__track' }, [fill]), stepsWrap]);

        var pre  = shotPanel('panelPre');
        var upd  = updPanel();
        var post = shotPanel('panelPost');
        var panels = el('div', { class: 'wcd-run__panels' }, [pre.node, upd.node, post.node]);

        var siteRefs = {};
        var sitesWrap = el('div', { class: 'wcd-run__sites' });
        checkSites.forEach(function (s) {
            var count = el('span', { class: 'wcd-run__sitecount', text: '0/' + s.checks });
            var spill = el('span', { class: 'wcd-run__sitepill', text: t(SITE_STATUS[0]) });
            siteRefs[s.site_id] = { count: count, pill: spill, total: s.checks };
            sitesWrap.appendChild(el('div', { class: 'wcd-run__site' }, [
                el('span', { class: 'wcd-run__sitemark', text: initials(s.name) }),
                el('span', { class: 'wcd-run__sitename', text: s.name }),
                count,
                spill
            ]));
        });

        var foot = el('div', { class: 'wcd-run__foot' }, [el('span', { class: 'wcd-muted', text: t('runFooterNote') })]);

        var card = el('div', { class: 'wcd-run' }, [head, timeline, panels, sitesWrap, foot]);
        card.setAttribute('data-state', 'running');
        host.appendChild(card);

        return {
            card: card, head: head, pill: pill, sub: sub, dismiss: dismiss, fill: fill, stepNodes: stepNodes,
            pre: pre, upd: upd, post: post, siteRefs: siteRefs, foot: foot,
            single: single, sites: sites, checkSites: checkSites, total: total
        };
    }

    function setFill(run, frac) {
        run.frac = Math.max(0, Math.min(1, frac));
        setWidth(run.fill, run.frac * 100);
    }

    function setPhase(run, idx) {
        run.phaseIdx = idx;
        RUN_STEPS.forEach(function (st, i) {
            run.stepNodes[st.id].className = 'wcd-run__step' + (i < idx ? ' is-done' : i === idx ? ' is-active' : '');
        });
        run.sub.textContent = t(RUN_STEPS[idx].verb) + ' · ' + run.checkSites.length + ' ' +
            plural(run.checkSites.length, t('site'), t('sitesPlural')) + ' · ' + t('dontClose');
        setStatePill(run.pre.pill, idx > 0 ? 'done' : 'active', 'shot');
        setStatePill(run.upd.pill, idx > 1 ? 'done' : 1 === idx ? 'active' : 'wait', 'upd');
        setStatePill(run.post.pill, idx > 2 ? 'done' : 2 === idx ? 'active' : 'wait', 'shot');
        run.upd.proc.textContent = 1 === idx
            ? String(run.sites.reduce(function (n, s) { return n + (s.updates ? s.updates.total : 0); }, 0))
            : '0';
        Object.keys(run.siteRefs).forEach(function (k) { run.siteRefs[k].pill.textContent = t(SITE_STATUS[idx]); });
    }

    function setSiteCounts(run, batchBySite, byBatch) {
        if (!byBatch) { return; }
        Object.keys(batchBySite).forEach(function (sid) {
            var ref = run.siteRefs[sid];
            var b   = byBatch[batchBySite[sid]];
            // Cap at the site's own total: against an older API several sites can share one
            // batch (transitional fallback), whose counts cover the whole batch.
            if (ref && b) { ref.count.textContent = Math.min(b.done || 0, ref.total) + '/' + ref.total; }
        });
    }

    // Poll all of a phase's batches at once, feeding aggregate + per-batch counts each tick.
    // POST-phase polls also pass the run's PRE batches: the server needs their failed count to
    // settle checks whose pre screenshot failed (their comparison is never created).
    // The try counter only advances while NOTHING completes, so a big run that is still making
    // progress never hits the stall timeout; only a genuinely stuck queue does.
    function pollBatches(batches, onTick, preBatches) {
        batches = (batches || []).filter(Boolean);
        if (!batches.length) { return Promise.resolve(); }
        preBatches = (preBatches || []).filter(Boolean);
        var payload = { batches: batches };
        if (preBatches.length) { payload.pre_batches = preBatches; }
        var tries = 0;
        var lastFinished = -1;
        function loop() {
            return api('poll', payload).then(function (data) {
                if (onTick) { onTick(data); }
                // Resolve with the final tick so callers can render the real done/failed counts.
                if (data.complete) { return data; }
                var finished = (data.done || 0) + (data.failed || 0);
                if (finished !== lastFinished) { lastFinished = finished; tries = 0; }
                tries++;
                if (tries >= POLL_MAX_TRIES) { throw new Error(t('stillRunning')); }
                return delay(POLL_INTERVAL).then(loop);
            });
        }
        return loop();
    }

    // updatesScope (optional) scopes each site's update to the run's type + its selected slugs
    // (fresh run: the selection map; resumed run: the slugs persisted on the site entries). No
    // slugs sent = all items of the type; no updatesScope = legacy whole-site update.
    function runUpdatesSequential(sites, updatesScope) {
        return sites.reduce(function (chain, s) {
            return chain.then(function () {
                var payload = { site_id: s.site_id };
                if (updatesScope && updatesScope.update_type) {
                    payload.update_type = updatesScope.update_type;
                    var slugs = (updatesScope.selection && updatesScope.selection[s.site_id]) || s.slugs || [];
                    if (slugs.length) { payload.slugs = slugs; }
                }
                // Tolerate a per-site update failure (e.g. offline): the post screenshots still run.
                return api('run_update', payload).catch(function () {});
            });
        }, Promise.resolve());
    }

    function collectResults(postBatchBySite) {
        var flagged = {};
        return Object.keys(postBatchBySite).reduce(function (chain, sid) {
            return chain.then(function () {
                return api('results', { batch: postBatchBySite[sid] }).then(function (data) {
                    // "To review" = a real visual change (>0%) or one explicitly marked to fix. A bare
                    // "new" with 0% is an unchanged, un-triaged comparison and counts as clean.
                    flagged[sid] = (data.comparisons || []).filter(function (c) {
                        return (Number(c.percent) || 0) > 0 || 'to_fix' === c.status;
                    }).length;
                }).catch(function () { flagged[sid] = 0; });
            });
        }, Promise.resolve()).then(function () { return flagged; });
    }

    function startRun(host, trigger, sites, updatesScope) {
        if (activeRun) { return; }
        activeRun = true;
        setAllTriggersRunning(true);
        var single     = 1 === sites.length;
        var checkSites = sites.filter(function (s) { return s.checks > 0; });
        var total      = checkSites.reduce(function (n, s) { return n + s.checks; }, 0);
        var run = mountRun(host, trigger, sites, checkSites, single, total, true);   // fresh run: open the popup now
        run.updatesScope = updatesScope || null;
        startHeartbeat();
        // Track the run server-side so it can be resumed if this tab disappears mid-run.
        // Best effort: a failed tracking call must not block the run itself.
        var tracking = {
            driver: driverId(),
            site_ids: checkSites.map(function (s) { return s.site_id; }),
            names: checkSites.map(function (s) { return s.name; }),
            checks: checkSites.map(function (s) { return s.checks; })
        };
        if (updatesScope && updatesScope.update_type) {
            // Persist the scope so a resume re-applies the original selection.
            tracking.update_type = updatesScope.update_type;
            if (updatesScope.selection) { tracking.selection = updatesScope.selection; }
        }
        api('run_start', tracking).catch(function () {})
            .then(function () { return runPhased(run); }).catch(function (e) { failRun(run, e); });
    }

    // Build the run card INSIDE the modal and render the "Updates running" reopen button next to the
    // widget heading. Shared by a fresh run and a resumed run. `show` opens the popup straight away
    // (a fresh run the user just confirmed); a resumed run passes false so nothing pops up on its own,
    // so the run plays out in the background and the user opens it via the reopen button. The card's
    // own dismiss is hidden while running (CSS) and appears once the run is done to clear it.
    function mountRun(host, trigger, sites, checkSites, single, total, show) {
        if (!modalNode || !modalNode.parentNode) { modalNode = buildModalNode(); }
        clearModalContent();
        modalNode.classList.remove('small');
        modalNode.classList.add('wcd-run-modal');
        var content = el('div', { class: 'content wcd-run-modalbody' });
        modalNode.appendChild(content);
        // Keep-open warning: while a run is in flight the popup cannot be closed (the close icon is
        // hidden and the close is vetoed), so this explains why and is removed once the run finishes.
        var warning = el('div', { class: 'ui warning message wcd-run-warning' }, [
            el('i', { class: 'exclamation triangle icon' }),
            el('span', { text: t('keepOpenWarning') })
        ]);
        var run = buildRun(content, sites, checkSites, single, total);
        // Place the warning directly under the run card header (between the head band and the timeline).
        run.card.insertBefore(warning, run.head.nextSibling);
        run.trigger = trigger;
        run.host = host;
        run.warning = warning;
        run.dismiss.addEventListener('click', function () { if (!run.dismiss.disabled) { closeRun(run); } });
        activeRunRef = run;
        // Green, matching the launch CTA, so a running reopen reads as the same "go" action.
        renderReopenButton(run, 'green', 'sync loading icon', t('reopenRunning'));
        if (show) { reopenModal(); }
        return run;
    }

    /* ── "Updates running" reopen button (next to the widget heading) ───────── */
    // While the run popup is closed, this button keeps the run reachable: clicking it reopens the
    // popup. It renders into the header slot (`.wcd-run-reopen-slot`) on the widget; the Updates
    // bar and per-site tab have no such slot, so it falls back to their run-host.

    function reopenSlot(run) {
        return document.querySelector('.wcd-run-reopen-slot') || run.host || null;
    }

    // Render the reopen button. While running it is a plain "Updates running" button; once the run
    // finishes it carries the verdict and a dismiss control to clear the run.
    function renderReopenButton(run, cls, iconCls, text, withDismiss) {
        var slot = reopenSlot(run);
        if (!slot) { return; }
        var btn = el('button', { class: 'ui small button wcd-reopen ' + cls, type: 'button' }, [
            el('i', { class: iconCls }),
            el('span', { text: text })
        ]);
        btn.addEventListener('click', reopenModal);
        var children = [btn];
        if (withDismiss) {
            var dismiss = el('button', { class: 'ui small basic icon button wcd-reopen__dismiss', type: 'button' }, [el('i', { class: 'times icon' })]);
            dismiss.addEventListener('click', function (e) { e.stopPropagation(); closeRun(run); });
            children.push(dismiss);
        }
        run.reopen = { node: el('span', { class: 'wcd-reopen-wrap' }, children) };
        slot.innerHTML = '';
        slot.appendChild(run.reopen.node);
    }

    function removeReopenButton(run) {
        var slot = reopenSlot(run);
        if (slot) { slot.innerHTML = ''; }
    }

    /* ── Heartbeat: keep the run's server-side activity fresh while THIS tab drives it ─── */
    // Runs independently of the awaited AJAX calls, so even a multi-minute synchronous update keeps
    // the run from looking abandoned. A second tab only takes over once the heartbeat goes silent.
    function startHeartbeat() {
        stopHeartbeat();
        // Send one immediately so this tab claims the run as its driver right away (a reload mid-run
        // then matches instantly), not only after the first interval.
        api('run_heartbeat', { driver: driverId() }).catch(function () {});
        heartbeatTimer = window.setInterval(function () { api('run_heartbeat', { driver: driverId() }).catch(function () {}); }, HEARTBEAT_INTERVAL);
    }
    function stopHeartbeat() {
        if (heartbeatTimer) { window.clearInterval(heartbeatTimer); heartbeatTimer = null; }
    }

    function runPhased(run) {
        var preBatchBySite = {}, postBatchBySite = {};
        var total = run.total;

        // Defense in depth: with ZERO check sites, take_pre/take_post would send an empty
        // site_ids array, which api() drops entirely (empty arrays append nothing), and the
        // server's legacy fallback (scope_site_ids) would then dispatch screenshots for ALL
        // enabled sites and burn credits. Currently unreachable (the preflight aborts with
        // t('noSites') before startRun, and resume only tracks checks>0 sites), but if it is
        // ever reached the run must install updates only: PRE -> UPDATES -> DONE, no take/poll.
        if (!run.checkSites.length) {
            setPhase(run, 0);
            setShot(run.pre, { queue: 0, processing: 0, done: 0, failed: 0 });
            setPhase(run, 1);
            setFill(run, 1 / 3);
            return runUpdatesSequential(run.sites, run.updatesScope).then(function () {
                // Nothing left to screenshot server-side: stop tracking the run. Best effort.
                api('run_discard', {}).catch(function () {});
                setShot(run.post, { queue: 0, processing: 0, done: 0, failed: 0 });
                setPhase(run, 3);
                finishRun(run, {});
            });
        }

        // PRE
        setPhase(run, 0);
        // Show everything as queued straight away so the panel isn't 0/0/0/0 until the first poll.
        setShot(run.pre, { queue: total, processing: 0, done: 0, failed: 0 });
        // ONE bulk call starts the whole phase (the server fans out chunked batch-per-group
        // take calls), instead of one round trip per site.
        return api('take_pre', { site_ids: run.checkSites.map(function (s) { return s.site_id; }) }).then(function (d) {
            preBatchBySite = d.batches || {};
        }).then(function () {
            return pollBatches(values(preBatchBySite), function (data) {
                setShot(run.pre, data);
                setSiteCounts(run, preBatchBySite, data.by_batch);
                setFill(run, (total ? (data.done || 0) / total : 1) / 3);
            });
        }).then(function (final) {
            // Render the final poll counts (a failed pre screenshot must stay visible as failed).
            setShot(run.pre, final || { queue: 0, processing: 0, done: total, failed: 0 });
            // UPDATES
            setPhase(run, 1);
            setFill(run, 1 / 3);
            return runUpdatesSequential(run.sites, run.updatesScope);
        }).then(function () {
            // POST
            setPhase(run, 2);
            setFill(run, 2 / 3);
            setShot(run.post, { queue: total, processing: 0, done: 0, failed: 0 });
            return api('take_post', { site_ids: run.checkSites.map(function (s) { return s.site_id; }) }).then(function (d) {
                postBatchBySite = d.batches || {};
            });
        }).then(function () {
            // Every post batch is dispatched, so the run can no longer be lost: stop tracking it
            // explicitly. The bulk take_post records all batches in one handler, but this clear
            // stays the authoritative end of tracking. Best effort.
            api('run_discard', {}).catch(function () {});
            // Keep the pre batches around: re-check polls compare against the same pre screenshots.
            run.preBatches = values(preBatchBySite);
            return pollBatches(values(postBatchBySite), function (data) {
                setShot(run.post, data);
                setSiteCounts(run, postBatchBySite, data.by_batch);
                setFill(run, 2 / 3 + (total ? (data.done || 0) / total : 1) / 3);
            }, run.preBatches);
        }).then(function (final) {
            setShot(run.post, final || { queue: 0, processing: 0, done: total, failed: 0 });
            // DONE
            setPhase(run, 3);
            return collectResults(postBatchBySite);
        }).then(function (flagged) {
            finishRun(run, flagged);
        });
    }

    function values(obj) { return Object.keys(obj).map(function (k) { return obj[k]; }); }

    function finishRun(run, flagged) {
        activeRun = false;
        stopHeartbeat();
        setAllTriggersRunning(false);
        run.card.setAttribute('data-state', 'done');
        run.dismiss.disabled = false;
        RUN_STEPS.forEach(function (st) { run.stepNodes[st.id].className = 'wcd-run__step is-done'; });
        setFill(run, 1);
        run.pill.className = 'wcd-run__pill is-done';
        run.pill.innerHTML = '';
        run.pill.appendChild(el('i', { class: 'check icon' }));
        run.pill.appendChild(document.createTextNode(' ' + t('phaseDone')));
        run.sub.textContent = run.checkSites.length + ' ' + plural(run.checkSites.length, t('site'), t('sitesPlural')) + ' · ' + run.total + ' ' + t('checks');
        setStatePill(run.pre.pill, 'done', 'shot');
        setStatePill(run.upd.pill, 'done', 'upd');
        run.upd.proc.textContent = '0';
        setStatePill(run.post.pill, 'done', 'shot');

        var totalFlagged = 0;
        Object.keys(run.siteRefs).forEach(function (sid) {
            var ref = run.siteRefs[sid];
            var f   = flagged[sid] || 0;
            totalFlagged += f;
            // The counter keeps the last polled done/total (a failed check must not show as done).
            ref.pill.className = 'wcd-run__sitepill ' + (f > 0 ? 'is-flagged' : 'is-clean');
            ref.pill.textContent = f > 0 ? fmt(t('toReview'), { '%d': f }) : t('clean');
        });
        renderRunFooter(run, totalFlagged);
        // Run finished: the card's dismiss now appears (single close), the keep-open warning goes
        // away, and the reopen button shows the verdict (visible when the popup is closed).
        if (run.warning && run.warning.parentNode) { run.warning.parentNode.removeChild(run.warning); }
        var allGood = 0 === totalFlagged;
        var verdict = allGood ? t('allGood') : fmt(1 === totalFlagged ? t('pageToReview') : t('pagesToReview'), { '%d': totalFlagged });
        renderReopenButton(run, allGood ? 'is-ok' : 'is-flagged', (allGood ? 'check circle' : 'exclamation triangle') + ' icon', verdict, true);
    }

    function renderRunFooter(run, flagged) {
        run.foot.innerHTML = '';
        var allGood = 0 === flagged;
        run.foot.appendChild(el('span', { class: 'wcd-run__verdict ' + (allGood ? 'is-ok' : 'is-flagged') }, [
            el('i', { class: (allGood ? 'check circle' : 'exclamation triangle') + ' icon' }),
            document.createTextNode(allGood ? t('allGood') : fmt(1 === flagged ? t('pageToReview') : t('pagesToReview'), { '%d': flagged }))
        ]));
        run.foot.appendChild(el('span', { class: 'wcd-run__footspacer' }));
        var recheck = el('button', { class: 'ui button', type: 'button' }, [el('i', { class: 'redo icon' }), document.createTextNode(t('recheck'))]);
        recheck.addEventListener('click', function () { recheckRun(run); });
        run.foot.appendChild(recheck);
        if (cfg.visualChecksUrl) {
            run.foot.appendChild(el('a', { class: 'ui blue button', href: cfg.visualChecksUrl }, [el('i', { class: 'external icon' }), document.createTextNode(t('viewResults'))]));
        }
    }

    // Shared POST phase: poll the given post batches, collect the comparisons, finish the card.
    // Used by the in-run flow's re-check and by the resume of an interrupted run.
    function runPostPhase(run, postBatchBySite) {
        setPhase(run, 2);
        setShot(run.post, { queue: run.total, processing: 0, done: 0, failed: 0 });
        setFill(run, 2 / 3);
        return pollBatches(values(postBatchBySite), function (data) {
            setShot(run.post, data);
            setSiteCounts(run, postBatchBySite, data.by_batch);
            setFill(run, 2 / 3 + (run.total ? (data.done || 0) / run.total : 1) / 3);
        }, run.preBatches).then(function (final) {
            setShot(run.post, final || { queue: 0, processing: 0, done: run.total, failed: 0 });
            setPhase(run, 3);
            return collectResults(postBatchBySite);
        }).then(function (flagged) {
            finishRun(run, flagged);
        });
    }

    // Re-check re-runs only the POST screenshots + comparison for the run's sites.
    function recheckRun(run) {
        if (run.rechecking) { return; }
        run.rechecking = true;
        activeRun = true;
        startHeartbeat();
        renderReopenButton(run, 'green', 'sync loading icon', t('reopenRunning'));
        setAllTriggersRunning(true);
        run.card.setAttribute('data-state', 'running');
        run.dismiss.disabled = true;
        run.pill.className = 'wcd-run__pill is-running';
        run.pill.innerHTML = '';
        run.pill.appendChild(el('i', { class: 'sync loading icon' }));
        run.pill.appendChild(document.createTextNode(' ' + t('running')));
        run.foot.innerHTML = '';
        run.foot.appendChild(el('span', { class: 'wcd-muted', text: t('runFooterNote') }));

        setPhase(run, 2);
        setShot(run.post, { queue: run.total, processing: 0, done: 0, failed: 0 });
        setFill(run, 2 / 3);
        api('take_post', { site_ids: run.checkSites.map(function (s) { return s.site_id; }) }).then(function (d) {
            return runPostPhase(run, d.batches || {});
        }).catch(function (e) {
            failRun(run, e);
        }).then(function () { run.rechecking = false; });
    }

    /* ─────────────────────── Resume an interrupted run ─────────────────── */
    // The run state lives server-side (recorded by the AJAX endpoints as the run progresses). On any
    // page that hosts the entry point, a still-active run is picked up automatically on load and
    // continued at its persisted phase (the popup reopens). A run whose heartbeat is still fresh
    // looks like another tab is driving it, so we leave it alone and re-check after a short window.

    function checkResume() {
        if (activeRunRef) { return; }   // this tab already owns/drives a run
        var host = document.querySelector('.wcd-run-host');
        if (!host) { return; }
        api('run_status', {}).then(function (d) {
            if (!d || !d.active) { hideResumePending(); return; }
            // Take over driving immediately when this is THIS tab's own run (driver matches, e.g. a
            // same-tab reload, no other tab can be driving it) or when the heartbeat has been silent
            // long enough that no other tab is driving it.
            var mine = d.driver && d.driver === driverId();
            if (mine || d.resumable) { resumeRun(host, d); return; }
            // Another tab may still be driving it: show the "Updates running" button + lock the CTA
            // RIGHT NOW so the user cannot start a second run, and re-check until we may take over.
            showResumePending(host, d);
            scheduleResumeRecheck();
        }).catch(function () {});
    }

    function scheduleResumeRecheck() {
        if (resumeRecheckTimer || activeRunRef) { return; }
        resumeRecheckTimer = window.setTimeout(function () {
            resumeRecheckTimer = null;
            checkResume();
        }, RESUME_RECHECK);
    }

    // Immediately surface that a run is in progress (driven by another tab, or not yet reclaimable):
    // lock the launch CTA and show an "Updates running" button. Clicking it takes the run over in
    // this tab (the user explicitly wants to see it) and opens the popup.
    var resumePendingShown = false;
    function showResumePending(host, state) {
        setAllTriggersRunning(true);
        var slot = document.querySelector('.wcd-run-reopen-slot') || host;
        if (!slot) { return; }
        var btn = el('button', { class: 'ui small green button wcd-reopen', type: 'button' }, [
            el('i', { class: 'sync loading icon' }),
            el('span', { text: t('reopenRunning') })
        ]);
        btn.addEventListener('click', function () {
            if (activeRunRef) { reopenModal(); return; }
            resumeRun(host, state);
        });
        slot.innerHTML = '';
        slot.appendChild(el('span', { class: 'wcd-reopen-wrap', 'data-pending': '1' }, [btn]));
        resumePendingShown = true;
    }

    function hideResumePending() {
        if (!resumePendingShown) { return; }
        resumePendingShown = false;
        var pending = document.querySelector('.wcd-reopen-wrap[data-pending]');
        if (pending && pending.parentNode) { pending.parentNode.innerHTML = ''; }
        setAllTriggersRunning(false);
    }

    // Rebuild the run card from the persisted state and continue at its phase. The tracked sites are
    // exactly the run's check sites (run_start only records sites with checks).
    function resumeRun(host, state) {
        if (activeRunRef || activeRun) { return; }   // a takeover is already under way in this tab
        var sites = (state.sites || []).map(function (s) {
            return {
                site_id: parseInt(s.site_id, 10),
                name: s.name || '',
                checks: Number(s.checks) || 0,
                // Persisted item slugs of a scoped (Updates-page) run; empty = all of the type.
                slugs: (s.slugs || []).map(String)
            };
        }).filter(function (s) { return s.site_id; });
        if (!sites.length) { return; }

        activeRun = true;
        resumePendingShown = false;   // the live run card now owns the slot
        var single  = 1 === sites.length;
        var total   = sites.reduce(function (n, s) { return n + s.checks; }, 0);
        var trigger = document.querySelector('.wcd-safe-update, .wcd-updates-run');
        setAllTriggersRunning(true);   // disable the CTAs so a re-click cannot wipe the resumed card
        // A resumed run plays out in the background: do NOT pop the modal open on its own. The
        // "Updates running" button next to the widget heading lets the user open it when they want.
        var run = mountRun(host, trigger, sites, sites, single, total, false);
        // Re-apply a scoped run's update type; the per-site slugs ride on the site entries.
        run.updatesScope = state.update_type ? { update_type: state.update_type } : null;
        startHeartbeat();

        run.preBatches = values(state.pre_batches || {});
        var updated    = (state.updated_sites || []).map(Number);
        var notUpdated = sites.filter(function (s) { return updated.indexOf(s.site_id) === -1; });

        // POST: pre + updates already ran. Let the server take any missing post screenshots (it
        // re-purges the now-stale caches) and poll them in.
        if ('post' === state.phase) {
            setPhase(run, 2);
            setShot(run.pre, { queue: 0, processing: 0, done: total, failed: 0 });
            api('run_resume_post', {}).then(function (d) {
                if (d.warning) { window.alert(d.warning); }
                run.preBatches = values(d.pre_batches || {});
                return runPostPhase(run, d.batches || {});
            }).catch(function (e) { failRun(run, e); });
            return;
        }

        // UPDATES: pre done; finish the remaining updates, then post.
        if ('updates' === state.phase) {
            setShot(run.pre, { queue: 0, processing: 0, done: total, failed: 0 });
            resumeUpdatesThenPost(run, sites, notUpdated).catch(function (e) { failRun(run, e); });
            return;
        }

        // PRE (default): finish the pre screenshots (take any still missing, poll all), then continue.
        setPhase(run, 0);
        var preBatchBySite = {};
        Object.keys(state.pre_batches || {}).forEach(function (k) { preBatchBySite[k] = state.pre_batches[k]; });
        var missingPre = sites.filter(function (s) { return !preBatchBySite[s.site_id]; }).map(function (s) { return s.site_id; });
        var ensurePre = missingPre.length
            ? api('take_pre', { site_ids: missingPre }).then(function (d) {
                Object.keys(d.batches || {}).forEach(function (k) { preBatchBySite[k] = d.batches[k]; });
            })
            : Promise.resolve();
        ensurePre.then(function () {
            run.preBatches = values(preBatchBySite);
            setShot(run.pre, { queue: total, processing: 0, done: 0, failed: 0 });
            return pollBatches(values(preBatchBySite), function (data) {
                setShot(run.pre, data);
                setSiteCounts(run, preBatchBySite, data.by_batch);
                setFill(run, (total ? (data.done || 0) / total : 1) / 3);
            });
        }).then(function (final) {
            setShot(run.pre, final || { queue: 0, processing: 0, done: total, failed: 0 });
            return resumeUpdatesThenPost(run, sites, notUpdated);
        }).catch(function (e) { failRun(run, e); });
    }

    // Shared tail for a resumed run: update the not-yet-updated sites, then take + poll the post phase.
    // Credit-safe: only the notUpdated sites run again, scoped to the run's persisted type + slugs.
    function resumeUpdatesThenPost(run, sites, notUpdated) {
        setPhase(run, 1);
        setFill(run, 1 / 3);
        return runUpdatesSequential(notUpdated, run.updatesScope).then(function () {
            setPhase(run, 2);
            setFill(run, 2 / 3);
            setShot(run.post, { queue: run.total, processing: 0, done: 0, failed: 0 });
            return api('take_post', { site_ids: sites.map(function (s) { return s.site_id; }) });
        }).then(function (d) {
            // Every post batch is dispatched: stop tracking (the rest finishes server-side).
            api('run_discard', {}).catch(function () {});
            return runPostPhase(run, d.batches || {});
        });
    }

    function failRun(run, e) {
        activeRun = false;
        stopHeartbeat();
        setAllTriggersRunning(false);
        run.card.setAttribute('data-state', 'error');
        run.dismiss.disabled = false;
        // Stop the spinner pill and flag the step that was in flight.
        run.pill.className = 'wcd-run__pill is-failed';
        run.pill.innerHTML = '';
        run.pill.appendChild(el('i', { class: 'exclamation triangle icon' }));
        run.pill.appendChild(document.createTextNode(' ' + t('phaseFailed')));
        RUN_STEPS.forEach(function (st) {
            if (run.stepNodes[st.id].classList.contains('is-active')) { run.stepNodes[st.id].className = 'wcd-run__step is-failed'; }
        });
        run.foot.innerHTML = '';
        run.foot.appendChild(el('p', { class: 'wcd-error', text: e && e.message ? e.message : t('genericError') }));
        if (run.warning && run.warning.parentNode) { run.warning.parentNode.removeChild(run.warning); }
        renderReopenButton(run, 'is-failed', 'exclamation triangle icon', t('phaseFailed'), true);
    }

    // Dismiss a finished/failed run: remove its card + reopen button, close the popup, and drop any
    // lingering server-side state so it never re-opens on the next page load. Only reachable once the
    // run is no longer in flight (the card's own dismiss appears in its final state).
    function closeRun(run) {
        stopHeartbeat();
        removeReopenButton(run);
        activeRunRef = null;
        activeRun = false;
        setAllTriggersRunning(false);
        clearModalContent();
        closeModal();
        api('run_discard', {}).catch(function () {});
    }

    // Lock/unlock every safe-update entry trigger on the page (the widget/tab `.wcd-safe-update`
    // CTA and both Updates-page bar `.wcd-updates-run` buttons): a run started from or driven by
    // any surface must block them all, or the unlocked sibling could open a second preflight.
    function setAllTriggersRunning(running) {
        document.querySelectorAll('.wcd-safe-update, .wcd-updates-run').forEach(function (node) {
            setTriggerRunning(node, running);
        });
    }

    // Reflect the run state on one launch CTA (spinner + disabled while a run is active).
    function setTriggerRunning(trigger, running) {
        if (!trigger) { return; }
        // Idempotent: never re-capture the restore label while already running (a second true call
        // would save the "running" label as the restore value and never recover the original).
        if (running === trigger.classList.contains('is-running')) { return; }
        trigger.classList.toggle('is-running', running);
        trigger.disabled = running;
        var icon = trigger.querySelector('i.icon');
        var text = trigger.lastChild && 3 === trigger.lastChild.nodeType ? trigger.lastChild : null;
        if (running) {
            if (text) { trigger.setAttribute('data-restore', text.textContent.trim()); text.textContent = ' ' + t('ctaRunning'); }
            if (icon) { trigger.setAttribute('data-restore-icon', icon.className); icon.className = 'sync loading icon'; }
        } else {
            var r = trigger.getAttribute('data-restore');
            if (text && r) { text.textContent = ' ' + r; }
            var ri = trigger.getAttribute('data-restore-icon');
            if (icon && ri) { icon.className = ri; }
        }
    }

    /* ───────────────────────────── Delegation ──────────────────────────── */

    document.addEventListener('change', function (e) {
        if (!e.target.classList) { return; }
        if (e.target.classList.contains('wcd-site-toggle')) {
            onToggleSite(e.target);
        }
    });

    // Focusing the password field while it still shows the sentinel dots selects them, so the first
    // keystroke replaces the placeholder (rather than appending to it) while an untouched field still
    // counts as "unchanged". Delegated so it works for the one reused settings modal.
    document.addEventListener('focusin', function (e) {
        if (e.target && e.target.name === 'basic_auth_password' && e.target.value === PWD_SENTINEL) {
            e.target.select();
        }
    });

    /* ───────────────────────── Widget stats ────────────────────────────── */

    // Fill the Pages/Checks stats once the dashboard has rendered (kept off the page-load path).
    // Matches the dashboard safe-update widget's [data-stats-scope] container (inside its
    // mainwp-scrolly-overflow). At most one exists per page; the Updates-page bar has no stats
    // container, so this is a clean no-op there.
    function loadBannerStats() {
        var hero = document.querySelector('[data-stats-scope]');
        if (!hero) { return; }
        var pages = hero.querySelector('[data-role="pages"]');
        var checks = hero.querySelector('[data-role="checks"]');
        var scope = hero.getAttribute('data-stats-scope');
        var siteId = parseInt(hero.getAttribute('data-site-id'), 10) || 0;
        var data = { scope: scope };
        if (scope === 'site' && siteId) { data.site_id = siteId; }

        api('banner_stats', data).then(function (d) {
            if (pages) { pages.textContent = d.pages; }
            if (checks) { checks.textContent = d.checks; }
        }).catch(function () {
            if (pages) { pages.textContent = '0'; }
            if (checks) { checks.textContent = '0'; }
        });
    }

    /* ──────────────────────── Visual Checks overview ────────────────────── */
    // Only active on the Visual Checks page (#wcd-runs). Native Fomantic filter dropdowns (period /
    // status / website / visual; type is fixed to On-Demand server-side) + batch/list views; the
    // server returns rendered HTML fragments which we swap in (drill-in loads per batch on open).

    function initRuns() {
        var root = document.getElementById('wcd-runs');
        if (!root) { return; }
        var listEl = root.querySelector('#wcd-runs-list');
        var pagerEl = root.querySelector('#wcd-runs-pagination');
        var state = {
            view: 'batch',
            page: 1,
            from: root.getAttribute('data-from') || '',
            to: root.getAttribute('data-to') || ''
        };

        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

        // Fomantic dropdowns keep their value in the embedded hidden input (multi = comma-separated).
        function hiddenValue(id) {
            var input = root.querySelector('#' + id + ' input[type="hidden"]');
            return input ? input.value : '';
        }

        // Snapshot of the filter controls, taken when the user clicks Filter (MainWP's native
        // explicit-apply pattern). Pagination/view changes reuse the snapshot, so unapplied
        // control changes never leak into a request.
        function gatherFilters() {
            var sites = hiddenValue('wcd-runs-website');
            return {
                from: state.from,
                to: state.to,
                status: hiddenValue('wcd-runs-status'),
                difference_only: hiddenValue('wcd-runs-visual') === '1' ? 1 : 0,
                site_ids: sites ? sites.split(',') : []
            };
        }

        var applied = gatherFilters();
        var loadSeq = 0;

        function load() {
            // A newer request supersedes an in-flight one (out-of-order responses are discarded).
            var seq = ++loadSeq;
            var request = {
                view: state.view,
                page: state.page,
                from: applied.from,
                to: applied.to,
                status: applied.status,
                difference_only: applied.difference_only,
                site_ids: applied.site_ids
            };
            listEl.innerHTML = '';
            listEl.appendChild(el('div', { class: 'wcd-runs-loading' }, [el('div', { class: 'ui active inline loader' })]));
            pagerEl.innerHTML = '';
            api('runs_render', request).then(function (d) {
                if (seq !== loadSeq) { return; }
                listEl.innerHTML = d.html || '';
                pagerEl.innerHTML = d.pagination || '';
            }).catch(function (e) {
                if (seq !== loadSeq) { return; }
                listEl.innerHTML = '';
                listEl.appendChild(el('p', { class: 'wcd-error', text: e.message }));
            });
        }

        function applyFilters() {
            applied = gatherFilters();
            state.page = 1;
            load();
        }

        function applyPeriod(value) {
            var range = root.querySelector('.wcd-runs-daterange');
            if ('custom' === value) {
                if (range) { range.hidden = false; }
                readDates();
                return;
            }
            if (range) { range.hidden = true; }
            if ('all' === value) {
                state.from = '';
                state.to = '';
            } else {
                var n = parseInt(value, 10) || 30;
                var to = new Date();
                var from = new Date();
                from.setDate(from.getDate() - n);
                state.from = iso(from);
                state.to = iso(to);
            }
            var f = root.querySelector('#wcd-runs-from'), t = root.querySelector('#wcd-runs-to');
            if (f) { f.value = state.from; }
            if (t) { t.value = state.to; }
        }

        function readDates() {
            var f = root.querySelector('#wcd-runs-from'), t = root.querySelector('#wcd-runs-to');
            state.from = f ? f.value : '';
            state.to = t ? t.value : '';
        }

        // Init the native Fomantic dropdowns. Fomantic JS always ships on MainWP admin pages; the
        // guard only protects against an unexpected absence (the page then simply shows defaults).
        // The period dropdown needs an onChange to maintain the from/to dates and the custom-range
        // visibility; the other dropdowns only apply once the Filter button is clicked.
        var $ = (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdown === 'function') ? window.jQuery : null;
        if ($) {
            $('#wcd-runs-period', root).dropdown({ onChange: function (value) { applyPeriod(String(value)); } });
            $('#wcd-runs-visual', root).dropdown();
            // Status + website are searchable multiselects: typing filters the items (substring match).
            $('#wcd-runs-status, #wcd-runs-website', root).dropdown({ fullTextSearch: true });
        }

        function toggleBatch(batchEl) {
            if (!batchEl) { return; }
            var bodyRow = batchEl.querySelector('.wcd-runs-batch-body');
            var cell = bodyRow ? bodyRow.querySelector('td') : null;
            if (!bodyRow || !cell) { return; }
            var open = !bodyRow.hidden;
            bodyRow.hidden = open;
            batchEl.classList.toggle('is-open', !open);
            var caret = batchEl.querySelector('.accordion-trigger i.icon');
            if (caret) { caret.className = 'caret ' + (open ? 'right' : 'down') + ' icon'; }
            if (!open && !batchEl.getAttribute('data-loaded')) {
                batchEl.setAttribute('data-loaded', '1');
                cell.innerHTML = '';
                cell.appendChild(el('div', { class: 'wcd-runs-loading' }, [el('div', { class: 'ui active inline loader' })]));
                api('runs_comparisons', { batch: batchEl.getAttribute('data-batch-id') }).then(function (d) {
                    cell.innerHTML = d.html || '';
                }).catch(function (e) {
                    // Allow a retry on the next open.
                    batchEl.removeAttribute('data-loaded');
                    cell.innerHTML = '';
                    cell.appendChild(el('p', { class: 'wcd-error', text: e.message }));
                });
            }
        }

        function resetFilters() {
            state.from = root.getAttribute('data-from') || '';
            state.to = root.getAttribute('data-to') || '';
            var f = root.querySelector('#wcd-runs-from'), t = root.querySelector('#wcd-runs-to');
            if (f) { f.value = state.from; }
            if (t) { t.value = state.to; }
            var range = root.querySelector('.wcd-runs-daterange');
            if (range) { range.hidden = true; }
            // Reset the dropdown values at the source of truth (the hidden inputs) so gatherFilters()
            // is correct even without Fomantic, then let Fomantic redraw its UI state. The period
            // onChange only updates the date state (no request), so this stays a single load.
            [['wcd-runs-period', '30'], ['wcd-runs-visual', '0'], ['wcd-runs-status', ''], ['wcd-runs-website', '']].forEach(function (pair) {
                var input = root.querySelector('#' + pair[0] + ' input[type="hidden"]');
                if (input) { input.value = pair[1]; }
            });
            if ($) {
                $('#wcd-runs-period', root).dropdown('set selected', '30');
                $('#wcd-runs-visual', root).dropdown('set selected', '0');
                $('#wcd-runs-status, #wcd-runs-website', root).dropdown('clear');
                // Fomantic's `clear` empties the chips/hidden input but not the typed search text;
                // wipe it explicitly so Reset is deterministic even if the field keeps focus.
                root.querySelectorAll('#wcd-runs-status .search, #wcd-runs-website .search')
                    .forEach(function (s) { s.value = ''; });
            }
            applyFilters();
        }

        root.addEventListener('click', function (e) {
            if (e.target.closest('.wcd-runs-apply')) { applyFilters(); return; }
            if (e.target.closest('.wcd-runs-reset')) { resetFilters(); return; }
            var viewBtn = e.target.closest('.wcd-runs-view-btn');
            if (viewBtn) {
                state.view = viewBtn.getAttribute('data-view') || 'batch';
                root.querySelectorAll('.wcd-runs-view-btn').forEach(function (b) { b.classList.toggle('active', b === viewBtn); });
                state.page = 1;
                load();
                return;
            }
            var pageBtn = e.target.closest('.wcd-runs-page');
            if (pageBtn && pageBtn.getAttribute('data-page') && !pageBtn.disabled) {
                state.page = parseInt(pageBtn.getAttribute('data-page'), 10) || 1;
                load();
                return;
            }
            var head = e.target.closest('.wcd-runs-batch-head');
            if (head) { toggleBatch(head.closest('.wcd-runs-batch')); }
        });

        root.addEventListener('change', function (e) {
            if (e.target.matches('#wcd-runs-from, #wcd-runs-to')) { readDates(); }
        });

        load();
    }

    function onReady() {
        loadBannerStats();
        initRuns();
        initSiteUpdatesBar();
        checkResume();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    // Warn before leaving the page while a run is in flight: closing the tab can interrupt the
    // pipeline between the update and the post screenshots (unlike merely closing the modal).
    window.addEventListener('beforeunload', function (e) {
        if (activeRun || syncInFlight > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.addEventListener('click', function (e) {
        var tokenToggle = e.target.closest && e.target.closest('.wcd-token-toggle');
        var tokenReset = e.target.closest && e.target.closest('.wcd-token-reset');
        var configure = e.target.closest && e.target.closest('.wcd-configure-urls');
        var siteSettings = e.target.closest && e.target.closest('.wcd-site-settings');
        var settingsSave = e.target.closest && e.target.closest('.wcd-settings-save');
        var settingsCancel = e.target.closest && e.target.closest('.wcd-settings-cancel');
        var safe = e.target.closest && e.target.closest('.wcd-safe-update');
        var updatesRun = e.target.closest && e.target.closest('.wcd-updates-run');
        var activateAll = e.target.closest && e.target.closest('.wcd-activate-all');

        if (tokenReset) {
            // Submit button inside the reset form: veto the submit unless the user confirms.
            if (!window.confirm(t('resetConfirm'))) { e.preventDefault(); }
            return;
        }
        if (tokenToggle) {
            e.preventDefault();
            var tokenInput = document.getElementById('wcd_api_token');
            if (tokenInput) {
                var reveal = tokenInput.type === 'password';
                tokenInput.type = reveal ? 'text' : 'password';
                var icon = tokenToggle.querySelector('i');
                if (icon) { icon.className = reveal ? 'eye slash icon' : 'eye icon'; }
            }
            return;
        }
        if (activateAll) { e.preventDefault(); if (!activateAll.disabled) { onActivateAll(); } return; }
        if (configure) { e.preventDefault(); onConfigureUrls(configure); return; }
        if (siteSettings) { e.preventDefault(); if (!siteSettings.disabled) { onSiteSettings(siteSettings); } return; }
        if (settingsCancel) { e.preventDefault(); var sm = settingsModal(); if (sm) { hideSettingsModal(sm); } return; }
        if (settingsSave) { e.preventDefault(); onSaveSiteSettings(settingsSave); return; }
        if (safe) {
            e.preventDefault();
            // Disabled trigger (no pending updates): do nothing.
            if (safe.disabled || safe.classList.contains('is-disabled') || safe.classList.contains('is-running')) { return; }
            var scope = safe.getAttribute('data-scope') || 'bulk';
            var siteId = parseInt(safe.getAttribute('data-site-id'), 10) || 0;
            runPreflight(safe, scope, siteId);
            return;
        }
        if (updatesRun) {
            e.preventDefault();
            if (updatesRun.disabled || updatesRun.classList.contains('is-running')) { return; }
            var updateType = updatesRun.getAttribute('data-update-type') || '';
            var mode = updatesRun.getAttribute('data-mode') || 'all';
            var barSiteId = parseInt(updatesRun.getAttribute('data-site-id'), 10) || 0;
            var updatesScope;
            if (barSiteId) {
                // Site Updates subpage: the tabs switch client-side (no reload), so the type
                // resolves from the ACTIVE tab at click time (same source as initSiteUpdatesBar).
                // Unresolvable tabs (abandoned, db updates) degrade to a no-op alert, never a
                // wrong-type run.
                var tabName = activeSiteTabName();
                updateType = SITE_TAB_TYPES[tabName] || '';
                if (!updateType) { window.alert(t('noSelection')); return; }
                if ('selected' === mode) {
                    var activeTab = document.querySelector('.ui.tab.active[data-tab="' + tabName + '"]');
                    var siteSel = readUpdatesSelection(updatesRun, updateType, activeTab);
                    if (!Object.keys(siteSel).length) { window.alert(t('noSelection')); return; }
                    updatesScope = { update_type: updateType, mode: 'selected', selection: siteSel };
                } else {
                    // NEVER send mode:'all' from the site context: the server would derive the
                    // site set from ALL managed sites. An empty slug list under this site's key
                    // means "every pending item of the type, on this site only".
                    var allSel = {};
                    allSel[barSiteId] = [];
                    updatesScope = { update_type: updateType, mode: 'selected', selection: allSel };
                }
            } else {
                updatesScope = { update_type: updateType, mode: mode, selection: null };
                if ('selected' === mode) {
                    updatesScope.selection = readUpdatesSelection(updatesRun.closest('.wcd-updates-bar'), updateType);
                    if (!Object.keys(updatesScope.selection).length) { window.alert(t('noSelection')); return; }
                }
            }
            runPreflight(updatesRun, 'bulk', 0, updatesScope);
        }
    });
})();
