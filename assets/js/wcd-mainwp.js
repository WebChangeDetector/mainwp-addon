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
        var loading = modal.querySelector('[data-role="loading"]');
        var form = modal.querySelector('[data-role="form"]');
        var error = modal.querySelector('[data-role="error"]');
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
        showSettingsModal(modal);

        api('get_site_settings', { site_id: siteId }).then(function (data) {
            fillSettings(modal, data);
            if (loading) { loading.hidden = true; }
            if (form) { form.hidden = false; }
            // Init the accordion (collapsed) once the form is visible.
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.accordion === 'function') {
                window.jQuery(modal).find('.wcd-settings-advanced').accordion({ exclusive: false });
            }
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdown === 'function') {
                window.jQuery(modal).find('.ui.dropdown').dropdown();
            }
        }).catch(function (e) {
            if (handleUnlinked(card, e)) { hideSettingsModal(modal); return; }
            if (loading) { loading.hidden = true; }
            if (error) { error.hidden = false; error.textContent = e.message; }
            if (form) { form.hidden = false; }
        });
    }

    function onSaveSiteSettings(button) {
        var modal = settingsModal();
        if (!modal) { return; }
        var siteId = parseInt(modal.getAttribute('data-site-id'), 10) || 0;
        if (!siteId) { return; }
        var card = document.querySelector('.wcd-site[data-site-id="' + siteId + '"]');
        var error = modal.querySelector('[data-role="error"]');
        if (error) { error.hidden = true; error.textContent = ''; }

        var region = settingsField(modal, 'screenshot_region');
        var threshold = settingsField(modal, 'threshold');
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
            hideSettingsModal(modal);
        }).catch(function (e) {
            if (card && handleUnlinked(card, e)) { hideSettingsModal(modal); return; }
            if (error) { error.hidden = false; error.textContent = e.message; }
        }).finally(function () {
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

    function preflightArgs(scope, siteId) {
        return scope === 'site' && siteId ? { site_id: siteId } : {};
    }

    // Locate the in-card run host that belongs to the clicked trigger. The Updates-page banner
    // (entry-banner.php) renders the .wcd-run-host right after its .wcd-hero; the dashboard widget
    // (widget-safe-update.php) has no .wcd-hero, so we fall back to the page's single .wcd-run-host
    // (at most one entry point renders per page).
    function runHostFor(trigger) {
        var hero = trigger && trigger.closest ? trigger.closest('.wcd-hero') : null;
        if (hero && hero.nextElementSibling && hero.nextElementSibling.classList.contains('wcd-run-host')) {
            return hero.nextElementSibling;
        }
        return document.querySelector('.wcd-run-host');
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
    function runPreflight(trigger, scope, siteId) {
        var host  = runHostFor(trigger);
        var modal = openModal();
        modalHead(modal, t('preflightTitle'));
        var body = el('div', { class: 'scrolling content' });
        body.appendChild(el('div', { class: 'ui active inline loader' }));
        modal.appendChild(body);

        api('preflight', preflightArgs(scope, siteId)).then(function (data) {
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
            buildPreflight(modal, body, data, checkSites, runSites, sites, host, trigger);
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
    function buildPreflight(modal, body, data, checkSites, runSites, allSites, host, trigger) {
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

        // unchecked note (run sites that stay live without screenshots)
        var unchecked = runSites.length - checkSites.length;
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
        confirm.addEventListener('click', function () { startRun(host, trigger, runSites); });
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

    /* ─── Unified in-card run (per-site pipeline) ────────────────────────── */
    // One card for the whole run: a timeline, aggregate Pre/Updates/Post panels, per-site rows
    // (phase pill + Queue/Processing/Done/Failed mini-grid) and a results footer. The browser is
    // still the scheduler, but the sites advance INDEPENDENTLY: as soon as one site's pre batch
    // completes it enters the single-file update FIFO (max ONE run_update in flight, ordered by
    // pre completion); when its update returns, its own post batch is dispatched and polled. ONE
    // bundled poll loop covers all currently interesting batches; the comparisons are created
    // server-side as the post screenshots finish, so results stream in per site.

    var RUN_STEPS = [
        { id: 'pre',    lbl: 'phasePre',     verb: 'verbPre' },
        { id: 'update', lbl: 'phaseUpdates', verb: 'verbUpdate' },
        { id: 'post',   lbl: 'phasePost',    verb: 'verbPost' },
        { id: 'done',   lbl: 'phaseDone',    verb: 'verbDone' }
    ];
    // Per-site pipeline phases mapped onto the 4 timeline steps: update_queued/updating share the
    // UPDATES step; post_dispatch (take_post in flight) already counts as POST; done/failed are
    // terminal.
    var PHASE_RANK = { pre: 0, update_queued: 1, updating: 1, post_dispatch: 2, post: 2, done: 3, failed: 3 };

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
            var spill = el('span', { class: 'wcd-run__sitepill', text: t('statusPre') });
            // Webapp-style mini-grid per site row: its own Queue/Processing/Done/Failed counters,
            // fed from the poll's by_batch breakdown (the phase decides pre vs post bucket).
            var cells = {};
            var grid = el('div', { class: 'wcd-run__sitegrid' });
            [['queue', 'queue'], ['processing', 'processing'], ['done', 'doneCount'], ['failed', 'failed']].forEach(function (c) {
                var val = el('div', { class: 'wcd-run__minival', text: '0' });
                cells[c[0]] = val;
                grid.appendChild(el('div', { class: 'wcd-run__minicell' }, [el('div', { class: 'wcd-run__minilbl', text: t(c[1]) }), val]));
            });
            siteRefs[s.site_id] = { count: count, pill: spill, cells: cells, total: s.checks };
            sitesWrap.appendChild(el('div', { class: 'wcd-run__site' }, [
                el('div', { class: 'wcd-run__siterow' }, [
                    el('span', { class: 'wcd-run__sitemark', text: initials(s.name) }),
                    el('span', { class: 'wcd-run__sitename', text: s.name }),
                    count,
                    spill
                ]),
                grid
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

    /* ── Per-site pipeline state machine ─────────────────────────────────── */
    // Every run site is tracked in run.pipe[siteId]: { site, hasChecks, phase, preBatch,
    // postBatch, preBucket, postBucket, flagged, resultsPending }. Sites without checks skip the
    // screenshot phases: they only pass through the update FIFO and are done when their update
    // returns (they were always updated without screenshots).

    function initPipeline(run) {
        run.pipe = {};
        run.updateQueue = [];                   // FIFO of site ids waiting for their run_update
        run.updateBusy = false;                 // max ONE run_update in flight (single-file queue)
        run.mutationLane = Promise.resolve();   // single writer lane for the structural run state
        run.polling = false;
        run.finished = false;
        run.failed = false;
        run.progressed = false;
        run.sites.forEach(function (s) {
            run.pipe[s.site_id] = {
                site: s,
                hasChecks: s.checks > 0,
                phase: 'pre',
                preBatch: '',
                postBatch: '',
                preBucket: null,
                postBucket: null,
                flagged: 0,
                resultsPending: false,
                error: ''
            };
        });
    }

    // ALL mutating AJAX (take_pre / run_update / take_post / run_resume_post) goes through this
    // shared promise lane, so the server-side structural run option only ever has one writer at a
    // time (a concurrent read-modify-write could silently drop a recorded batch).
    function mutate(run, fn) {
        var result = run.mutationLane.then(fn);
        run.mutationLane = result.catch(function () {});
        return result;
    }

    function eachSite(run, fn) {
        Object.keys(run.pipe).forEach(function (sid) { fn(run.pipe[sid], sid); });
    }

    function allTerminal(run) {
        var open = 0;
        eachSite(run, function (st) { if (3 !== PHASE_RANK[st.phase]) { open++; } });
        return 0 === open;
    }

    function bucketFinished(b) { return b ? (b.done || 0) + (b.failed || 0) : 0; }

    // Per-site complete mirrors the server's aggregate rule (queue empty AND something finished),
    // so a site never advances on a batch whose queue has not been populated yet.
    function bucketComplete(b) {
        return !!b && 0 === (b.queue || 0) + (b.processing || 0) && bucketFinished(b) > 0;
    }

    // The mini-grid shows the bucket of the phase the site is in: the pre bucket up to and
    // including its update, the post bucket from the post dispatch on (terminal sites keep the
    // last bucket they reached).
    function siteBucket(st) {
        return (PHASE_RANK[st.phase] >= 2 && st.postBucket) ? st.postBucket : st.preBucket;
    }

    function setSitePhase(run, sid, phase) {
        var st = run.pipe[sid];
        if (!st || st.phase === phase) { return; }
        st.phase = phase;
        run.progressed = true;   // a transition counts as progress for the stall counter
        renderSiteRow(run, st, sid);
    }

    // One site row: done/total counter, phase pill and the Queue/Processing/Done/Failed mini-grid.
    function renderSiteRow(run, st, sid) {
        var ref = run.siteRefs[sid];
        if (!ref) { return; }   // sites without checks have no row
        var bucket = siteBucket(st) || { queue: st.site.checks, processing: 0, done: 0, failed: 0 };
        ref.cells.queue.textContent = String(bucket.queue || 0);
        ref.cells.processing.textContent = String(bucket.processing || 0);
        ref.cells.done.textContent = String(bucket.done || 0);
        ref.cells.failed.textContent = String(bucket.failed || 0);
        // Cap at the site's own total: against an older API several sites can share one batch
        // (transitional fallback), whose counts cover the whole batch.
        ref.count.textContent = Math.min(bucket.done || 0, ref.total) + '/' + ref.total;
        setSitePill(ref.pill, st, bucket);
    }

    function setSitePill(pill, st, bucket) {
        pill.removeAttribute('data-tooltip');
        if ('failed' === st.phase) {
            pill.className = 'wcd-run__sitepill is-failed';
            pill.textContent = t('statusFailed');
            // Surface the per-site error (e.g. a 402 from its take_post) as a native tooltip.
            if (st.error) { pill.setAttribute('data-tooltip', st.error); }
            return;
        }
        if ('done' === st.phase) {
            if (st.resultsPending) {   // post done, comparisons summary still loading
                pill.className = 'wcd-run__sitepill is-active';
                pill.textContent = t('statusDone');
                return;
            }
            pill.className = 'wcd-run__sitepill ' + (st.flagged > 0 ? 'is-flagged' : 'is-clean');
            pill.textContent = st.flagged > 0 ? fmt(t('toReview'), { '%d': st.flagged }) : t('clean');
            return;
        }
        if ('update_queued' === st.phase) {
            pill.className = 'wcd-run__sitepill';
            pill.textContent = t('statusWaiting');
            return;
        }
        var key = 'updating' === st.phase ? 'statusUpdating'
            : 'pre' === st.phase ? 'statusPre'
                : ('post' === st.phase && 0 === (bucket.queue || 0) && (bucket.processing || 0) > 0) ? 'statusComparing' : 'statusPost';
        pill.className = 'wcd-run__sitepill is-active';
        pill.textContent = t(key);
    }

    /* ── Single-file update FIFO ─────────────────────────────────────────── */
    // Max ONE run_update in flight, ordered by pre completion: the synchronous multi-minute call
    // binds one dashboard PHP worker per site, and MainWP's update abilities are not documented
    // for parallel calls (guarded internal-call contract, not to be widened).

    function enqueueUpdate(run, sid) {
        setSitePhase(run, sid, 'update_queued');
        run.updateQueue.push(sid);
        drainUpdateQueue(run);
    }

    function drainUpdateQueue(run) {
        if (run.updateBusy || run.failed) { return; }
        var sid = run.updateQueue.shift();
        if (undefined === sid) { return; }
        var st = run.pipe[sid];
        run.updateBusy = true;
        setSitePhase(run, sid, 'updating');
        renderRun(run);
        // Tolerate a per-site update failure (e.g. offline): the post screenshots still run.
        mutate(run, function () { return api('run_update', { site_id: st.site.site_id }).catch(function () {}); }).then(function () {
            if (!st.hasChecks) {
                setSitePhase(run, sid, 'done');   // no screenshots: the update ends this site's pipeline
                return null;
            }
            setSitePhase(run, sid, 'post_dispatch');
            renderRun(run);
            return mutate(run, function () { return api('take_post', { site_ids: [st.site.site_id] }); });
        }).then(function (d) {
            if (!d) { return; }
            var batch = (d.batches || {})[st.site.site_id];
            if (!batch) { throw new Error(t('genericError')); }
            st.postBatch = String(batch);
            setSitePhase(run, sid, 'post');
        }).catch(function (e) {
            st.error = e && e.message ? e.message : '';
            setSitePhase(run, sid, 'failed');   // only this site fails; the rest keeps going
        }).finally(function () {
            run.updateBusy = false;
            renderRun(run);
            drainUpdateQueue(run);
            maybeFinish(run);
        });
    }

    // A site's post batch completed: fetch its comparisons and settle the row (streamed results).
    function siteResults(run, st, sid) {
        st.resultsPending = true;
        setSitePhase(run, sid, 'done');
        api('results', { batch: st.postBatch }).then(function (data) {
            // "To review" = a real visual change (>0%) or one explicitly marked to fix. A bare
            // "new" with 0% is an unchanged, un-triaged comparison and counts as clean.
            st.flagged = (data.comparisons || []).filter(function (c) {
                return (Number(c.percent) || 0) > 0 || 'to_fix' === c.status;
            }).length;
        }).catch(function () { st.flagged = 0; }).then(function () {
            st.resultsPending = false;
            renderSiteRow(run, st, sid);
            maybeFinish(run);
        });
    }

    // The run is over once every site is terminal AND no per-site results fetch is pending.
    function maybeFinish(run) {
        if (run.failed || run.finished || !allTerminal(run)) { return; }
        var pending = false;
        eachSite(run, function (st) { if (st.resultsPending) { pending = true; } });
        if (!pending) { finishRun(run); }
    }

    /* ── ONE bundled poll loop for the whole run ─────────────────────────── */
    // Each tick sends every currently interesting batch: the pre batches of sites still in PRE
    // and the post batches of sites in POST (as `batches`), plus the completed pre batches as
    // `pre_batches` (their raw buckets keep feeding the mini-grids and their failed counts settle
    // post checks whose pre screenshot failed). Never one poll per site. The try counter only
    // advances while NOTHING progresses, so a big run that is still moving never hits the stall
    // timeout; only a genuinely stuck queue does.

    function pollTargets(run) {
        var batches = [];
        var preBatches = [];
        eachSite(run, function (st) {
            if ('pre' === st.phase && st.preBatch) { batches.push(st.preBatch); }
            else if ('post' === st.phase && st.postBatch) { batches.push(st.postBatch); }
            if ('pre' !== st.phase && st.preBatch) { preBatches.push(st.preBatch); }
        });
        var payload = { batches: batches };
        if (preBatches.length) { payload.pre_batches = preBatches; }
        return payload;
    }

    function startPollLoop(run) {
        if (run.polling) { return; }
        run.polling = true;
        var tries = 0;

        function tick() {
            if (run.failed || run.finished || allTerminal(run)) { run.polling = false; return Promise.resolve(); }
            var payload = pollTargets(run);
            if (!payload.batches.length) {
                // Nothing pollable right now (e.g. every remaining site is mid-update): idle
                // without advancing the stall counter; the update FIFO makes its own progress.
                return delay(POLL_INTERVAL).then(tick);
            }
            run.progressed = false;
            return api('poll', payload).then(function (data) {
                applyPollTick(run, data.by_batch || {});
                tries = run.progressed ? 0 : tries + 1;
                if (tries >= POLL_MAX_TRIES) { throw new Error(t('stillRunning')); }
                renderRun(run);
                maybeFinish(run);
                if (run.failed || run.finished || allTerminal(run)) { run.polling = false; return; }
                return delay(POLL_INTERVAL).then(tick);
            });
        }

        tick().catch(function (e) { run.polling = false; failRun(run, e); });
    }

    // Fold one poll tick into the per-site states: refresh the cached buckets (with the per-site
    // pre-fail shift on post buckets) and fire the per-site transitions.
    function applyPollTick(run, byBatch) {
        eachSite(run, function (st, sid) {
            if (st.preBatch && byBatch[st.preBatch]) {
                if (bucketFinished(byBatch[st.preBatch]) !== bucketFinished(st.preBucket)) { run.progressed = true; }
                st.preBucket = byBatch[st.preBatch];
            }
            if (st.postBatch && byBatch[st.postBatch]) {
                var b = byBatch[st.postBatch];
                // A check whose pre screenshot failed never gets a comparison: shift it from
                // processing to failed, per site (mirrors the server's aggregate shift).
                var shift = Math.min(st.preBucket ? (st.preBucket.failed || 0) : 0, b.processing || 0);
                b = { queue: b.queue || 0, processing: (b.processing || 0) - shift, done: b.done || 0, failed: (b.failed || 0) + shift };
                if (bucketFinished(b) !== bucketFinished(st.postBucket)) { run.progressed = true; }
                st.postBucket = b;
            }
            if ('pre' === st.phase && bucketComplete(st.preBucket)) {
                enqueueUpdate(run, sid);   // FIFO order = pre completion order
            } else if ('post' === st.phase && bucketComplete(st.postBucket)) {
                siteResults(run, st, sid);
            }
            renderSiteRow(run, st, sid);
        });
    }

    /* ── Card rendering (aggregate panels + timeline) ────────────────────── */

    function addBucket(sum, b) {
        sum.queue += b.queue || 0;
        sum.processing += b.processing || 0;
        sum.done += b.done || 0;
        sum.failed += b.failed || 0;
    }

    // Mean per-site progress drives the timeline fill: each site contributes thirds (pre, update,
    // post), weighted by its own finished-check count where a batch is in flight.
    function siteProgress(st) {
        var rank = PHASE_RANK[st.phase];
        if (3 === rank) { return 1; }
        var total = st.site.checks || 0;
        function frac(b) { return total ? Math.min(bucketFinished(b), total) / total : 0; }
        if (0 === rank) { return frac(st.preBucket) / 3; }
        if (1 === rank) { return 1 / 3; }
        return 2 / 3 + ('post' === st.phase ? frac(st.postBucket) / 3 : 0);
    }

    // Recompute the aggregate panels, the timeline and the subtitle from the per-site states. The
    // three panels stay aggregates: Pre sums every pre bucket, Post sums every post bucket and
    // Updates counts the pending update items of the queued + updating sites. A timeline node is
    // done only when EVERY site passed that phase; the fill is the mean per-site progress.
    function renderRun(run) {
        if (!run.pipe || run.finished || run.failed) { return; }
        var pre = { queue: 0, processing: 0, done: 0, failed: 0 };
        var post = { queue: 0, processing: 0, done: 0, failed: 0 };
        var inPhase = { pre: 0, updates: 0, post: 0 };
        var updItems = 0;
        var progress = 0;
        var count = 0;
        var minRank = 3;
        eachSite(run, function (st) {
            count++;
            var rank = PHASE_RANK[st.phase];
            minRank = Math.min(minRank, rank);
            if (0 === rank) { inPhase.pre++; }
            if (1 === rank) {
                inPhase.updates++;
                updItems += st.site.updates ? st.site.updates.total : 0;
            }
            if (2 === rank) { inPhase.post++; }
            if (st.hasChecks) {
                addBucket(pre, st.preBucket || { queue: st.site.checks, processing: 0, done: 0, failed: 0 });
                if (st.postBucket) { addBucket(post, st.postBucket); }
                else if (2 === rank) { post.queue += st.site.checks; }
            }
            progress += siteProgress(st);
        });
        setShot(run.pre, pre);
        setShot(run.post, post);
        run.upd.proc.textContent = String(updItems);
        setStatePill(run.pre.pill, inPhase.pre > 0 ? 'active' : 'done', 'shot');
        setStatePill(run.upd.pill, inPhase.updates > 0 ? 'active' : (minRank >= 2 ? 'done' : 'wait'), 'upd');
        setStatePill(run.post.pill, inPhase.post > 0 ? 'active' : (3 === minRank ? 'done' : 'wait'), 'shot');
        RUN_STEPS.forEach(function (step, i) {
            run.stepNodes[step.id].className = 'wcd-run__step' + (i < minRank ? ' is-done' : i === minRank ? ' is-active' : '');
        });
        setFill(run, count ? progress / count : 0);
        run.sub.textContent = t(RUN_STEPS[minRank].verb) + ' · ' + run.checkSites.length + ' ' +
            plural(run.checkSites.length, t('site'), t('sitesPlural')) + ' · ' + t('dontClose');
    }

    function startRun(host, trigger, sites) {
        if (activeRun) { return; }
        activeRun = true;
        setTriggerRunning(trigger, true);
        var single     = 1 === sites.length;
        var checkSites = sites.filter(function (s) { return s.checks > 0; });
        var total      = checkSites.reduce(function (n, s) { return n + s.checks; }, 0);
        var run = mountRun(host, trigger, sites, checkSites, single, total, true);   // fresh run: open the popup now
        startHeartbeat();
        // Track the run server-side so it can be resumed if this tab disappears mid-run.
        // Best effort: a failed tracking call must not block the run itself.
        var tracking = api('run_start', {
            driver: driverId(),
            site_ids: checkSites.map(function (s) { return s.site_id; }),
            names: checkSites.map(function (s) { return s.name; }),
            checks: checkSites.map(function (s) { return s.checks; })
        }).catch(function () {});
        tracking.then(function () { return runPipeline(run); }).catch(function (e) { failRun(run, e); });
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
    // popup. It renders into the header slot (`.wcd-run-reopen-slot`) on the widget; on the Updates
    // banner, which has no such slot, it falls back to the run-host below the banner.

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

    // Fresh run: seed every site into the pipeline, dispatch ONE bulk take_pre for all check
    // sites (the server fans out chunked batch-per-group take calls), then let the poll loop
    // advance each site on its own. Sites without checks skip PRE and join the update FIFO first.
    // End of server-side tracking: record_post_batch self-clears the run state once the LAST
    // site's post batch is recorded (no explicit run_discard on the happy path anymore).
    function runPipeline(run) {
        initPipeline(run);
        renderRun(run);
        var ids = run.checkSites.map(function (s) { return s.site_id; });
        // Register the bulk take_pre in the mutation lane FIRST: enqueueing a zero-check site
        // before it would put that site's synchronous multi-minute run_update ahead of the pre
        // dispatch in the FIFO lane and stall every check site's screenshots behind it.
        var pre = mutate(run, function () { return api('take_pre', { site_ids: ids }); });
        eachSite(run, function (st, sid) { if (!st.hasChecks) { enqueueUpdate(run, sid); } });
        return pre.then(function (d) {
            var batches = d.batches || {};
            run.checkSites.forEach(function (s) {
                if (batches[s.site_id]) { run.pipe[s.site_id].preBatch = String(batches[s.site_id]); }
            });
            startPollLoop(run);
        });
    }

    function finishRun(run) {
        run.finished = true;
        activeRun = false;
        stopHeartbeat();
        setTriggerRunning(run.trigger, false);
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

        // Per-site verdicts were streamed in as each site finished; here we only sum them up and
        // re-render the rows (the counters keep the last polled done/total, so a failed check
        // never shows as done).
        var totalFlagged = 0;
        var anyFailed = false;
        eachSite(run, function (st, sid) {
            totalFlagged += st.flagged || 0;
            if ('failed' === st.phase) { anyFailed = true; }
            renderSiteRow(run, st, sid);
        });
        // A run that finished WITH a failed site never reaches the record_post_batch self-clear
        // (that site has no post batch), so the server state would linger and auto-resume itself
        // after the idle gate on every page load. The user has seen the verdict here: drop the
        // tracking (best effort). Clean runs already self-cleared server-side; failRun keeps the
        // state on purpose so an aborted run stays resumable.
        if (anyFailed) { api('run_discard', {}).catch(function () {}); }
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

    // Re-check re-runs only the POST screenshots + comparisons for the run's check sites, through
    // the same per-site pipeline (one bundled take_post dispatch, the shared poll loop, streamed
    // per-site results). The cached pre buckets stay: their failed counts still shift the new
    // post batches' orphaned checks.
    function recheckRun(run) {
        if (run.rechecking || !run.pipe) { return; }
        run.rechecking = true;
        run.finished = false;
        run.failed = false;
        activeRun = true;
        startHeartbeat();
        renderReopenButton(run, 'green', 'sync loading icon', t('reopenRunning'));
        setTriggerRunning(run.trigger, true);
        run.card.setAttribute('data-state', 'running');
        run.dismiss.disabled = true;
        run.pill.className = 'wcd-run__pill is-running';
        run.pill.innerHTML = '';
        run.pill.appendChild(el('i', { class: 'sync loading icon' }));
        run.pill.appendChild(document.createTextNode(' ' + t('running')));
        run.foot.innerHTML = '';
        run.foot.appendChild(el('span', { class: 'wcd-muted', text: t('runFooterNote') }));

        var ids = [];
        eachSite(run, function (st) {
            if (!st.hasChecks) { return; }   // no screenshots for this site; it stays terminal
            st.phase = 'post_dispatch';
            st.postBatch = '';
            st.postBucket = null;
            st.flagged = 0;
            st.error = '';
            ids.push(st.site.site_id);
        });
        renderRun(run);
        mutate(run, function () { return api('take_post', { site_ids: ids }); }).then(function (d) {
            var batches = d.batches || {};
            eachSite(run, function (st, sid) {
                if (!st.hasChecks) { return; }
                if (batches[st.site.site_id]) {
                    st.postBatch = String(batches[st.site.site_id]);
                    setSitePhase(run, sid, 'post');
                } else {
                    setSitePhase(run, sid, 'failed');
                }
            });
            startPollLoop(run);
        }).catch(function (e) {
            failRun(run, e);
        }).finally(function () { run.rechecking = false; });
    }

    /* ─────────────────────── Resume an interrupted run ─────────────────── */
    // The run state lives server-side (recorded per site by the AJAX endpoints as the run
    // progresses). On any page that hosts the entry point, a still-active run is picked up
    // automatically on load and continued through the same per-site state machine (in the
    // background; the "Updates running" button opens the popup). A run whose heartbeat is still
    // fresh looks like another tab is driving it, so we leave it alone and re-check shortly.

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
        setTriggerRunning(document.querySelector('.wcd-safe-update'), true);
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
        setTriggerRunning(document.querySelector('.wcd-safe-update'), false);
    }

    // Rebuild the run card from the persisted state and continue: the SAME per-site state machine,
    // just seeded from the persisted per-site fields (the legacy global `phase` is ignored):
    //   post batch recorded -> poll it (post)
    //   in updated_sites    -> ONE bundled run_resume_post dispatches its missing post batch
    //   pre batch recorded  -> poll it (NEVER re-take: credit safety)
    //   nothing recorded    -> ONE bundled take_pre
    // The tracked sites are exactly the run's check sites (run_start only records sites with checks).
    function resumeRun(host, state) {
        if (activeRunRef || activeRun) { return; }   // a takeover is already under way in this tab
        var sites = (state.sites || []).map(function (s) {
            return { site_id: parseInt(s.site_id, 10), name: s.name || '', checks: Number(s.checks) || 0 };
        }).filter(function (s) { return s.site_id; });
        if (!sites.length) { return; }

        activeRun = true;
        resumePendingShown = false;   // the live run card now owns the slot
        var single  = 1 === sites.length;
        var total   = sites.reduce(function (n, s) { return n + s.checks; }, 0);
        var trigger = document.querySelector('.wcd-safe-update');
        setTriggerRunning(trigger, true);   // disable the CTA so a re-click cannot wipe the resumed card
        // A resumed run plays out in the background: do NOT pop the modal open on its own. The
        // "Updates running" button next to the widget heading lets the user open it when they want.
        var run = mountRun(host, trigger, sites, sites, single, total, false);
        startHeartbeat();
        initPipeline(run);

        var seeds = seedPipeline(run, state);
        renderRun(run);
        var lastSeedError = null;
        // A rejected seed bundle (e.g. every requested site unresumable, or a 402 on the bundled
        // take_pre) must not abandon the healthy sites already seeded with live batches: fail only
        // the sites of THAT bundle and keep the rest going.
        function seedStep(fn, ids) {
            return function () {
                return fn(run, ids).catch(function (e) {
                    lastSeedError = e;
                    ids.forEach(function (sid) {
                        var st = run.pipe[sid];
                        if (st && 3 !== PHASE_RANK[st.phase]) {
                            st.error = e && e.message ? e.message : '';
                            setSitePhase(run, sid, 'failed');
                        }
                    });
                });
            };
        }
        var chain = Promise.resolve();
        if (seeds.resume.length) { chain = chain.then(seedStep(resumeUpdatedSites, seeds.resume)); }
        if (seeds.fresh.length) { chain = chain.then(seedStep(takeMissingPre, seeds.fresh)); }
        chain.then(function () {
            renderRun(run);
            var pollable = false;
            eachSite(run, function (st) {
                if (3 !== PHASE_RANK[st.phase] && (st.preBatch || st.postBatch)) { pollable = true; }
            });
            // Only when NOTHING is left to poll does the whole card fail (the server state stays,
            // so a later resume can retry); otherwise the healthy sites finish normally.
            if (!pollable && lastSeedError) { failRun(run, lastSeedError); return; }
            startPollLoop(run);
            maybeFinish(run);
        }).catch(function (e) { failRun(run, e); });
    }

    // Derive each site's re-entry point from the persisted per-site fields. A site whose update
    // was mid-flight when the tab died is not in updated_sites, so it re-enters via the FIFO
    // (re-running its update is safe: nothing pending means success without a post).
    function seedPipeline(run, state) {
        var preB = state.pre_batches || {};
        var postB = state.post_batches || {};
        var updated = (state.updated_sites || []).map(Number);
        var resume = [];
        var fresh = [];
        eachSite(run, function (st) {
            var id = st.site.site_id;
            if (preB[id]) { st.preBatch = String(preB[id]); }
            if (postB[id]) {
                st.postBatch = String(postB[id]);
                st.phase = 'post';
            } else if (updated.indexOf(id) !== -1) {
                st.phase = 'post_dispatch';   // its updates ran; only its post batch is missing
                resume.push(id);
            } else if (!st.preBatch) {
                fresh.push(id);   // nothing recorded yet: needs its pre screenshots
            }
            // else: pre batch recorded -> stay in 'pre' and poll it (never re-take).
        });
        return { resume: resume, fresh: fresh };
    }

    // ONE bundled run_resume_post for the already-updated sites: the server re-purges their (by
    // now stale) caches, dispatches the missing post batches and records them.
    function resumeUpdatedSites(run, ids) {
        return mutate(run, function () { return api('run_resume_post', { site_ids: ids }); }).then(function (d) {
            if (d.warning) { window.alert(d.warning); }
            var batches = d.batches || {};
            var pre = d.pre_batches || {};
            ids.forEach(function (sid) {
                var st = run.pipe[sid];
                if (!st) { return; }
                if (pre[sid]) { st.preBatch = String(pre[sid]); }
                if (batches[sid]) {
                    st.postBatch = String(batches[sid]);
                    setSitePhase(run, sid, 'post');
                } else {
                    setSitePhase(run, sid, 'failed');   // skipped by the server (e.g. no mapping left)
                }
            });
        });
    }

    // ONE bundled take_pre for the sites whose pre screenshots were never dispatched.
    function takeMissingPre(run, ids) {
        return mutate(run, function () { return api('take_pre', { site_ids: ids }); }).then(function (d) {
            var batches = d.batches || {};
            ids.forEach(function (sid) {
                if (run.pipe[sid] && batches[sid]) { run.pipe[sid].preBatch = String(batches[sid]); }
            });
        });
    }

    function failRun(run, e) {
        run.failed = true;   // stops the poll loop and the update FIFO
        activeRun = false;
        stopHeartbeat();
        setTriggerRunning(run.trigger, false);
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
        setTriggerRunning(run.trigger, false);
        clearModalContent();
        closeModal();
        api('run_discard', {}).catch(function () {});
    }

    // Reflect the run state on the launch CTA (spinner + disabled while a run is active).
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

    /* ───────────────────────── Hero banner stats ───────────────────────── */

    // Fill the Pages/Checks stats once the dashboard has rendered (kept off the page-load path).
    // Matches the Updates-page hero banner (.wcd-hero) and the dashboard safe-update widget
    // (the [data-stats-scope] container inside its mainwp-scrolly-overflow); both carry
    // data-stats-scope. At most one exists per page.
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

    /* ─────────────── Interaction Flows (per-site tab section) ───────────── */
    // Only active where the site tab rendered #wcd-flows-section. Read-only list + the On-Demand
    // toggle (enabled_manual); steps/runs/run-detail lazy-load per drill-in. The run detail polls
    // flow_run_view every 10s (webapp parity) ONLY while it is open AND the run is still
    // processing, with cleanup on close, accordion collapse, status settle and errors; ticks are
    // skipped while the browser tab is hidden.

    function initFlows() {
        var root = document.getElementById('wcd-flows-section');
        if (!root) { return; }
        var siteId = parseInt(root.getAttribute('data-site-id'), 10) || 0;

        var FLOW_POLL_INTERVAL = 10000;
        var poll = { timer: null, node: null, runId: null, flowId: null };

        function stopRunPoll() {
            if (poll.timer) { window.clearInterval(poll.timer); }
            poll.timer = null; poll.node = null; poll.runId = null; poll.flowId = null;
        }

        function startRunPoll(node, flowId, runId) {
            stopRunPoll();
            poll.node = node; poll.flowId = flowId; poll.runId = runId;
            poll.timer = window.setInterval(pollTick, FLOW_POLL_INTERVAL);
        }

        function pollTick() {
            // The detail view is gone (closed, reloaded, collapsed): stop for good.
            if (!poll.node || !poll.node.isConnected || poll.node.hidden) { stopRunPoll(); return; }
            // Browser tab in background: skip the request, resume on the next visible tick.
            if (document.hidden) { return; }
            // Freeze the polled run: if the user switches to another run detail while this request
            // is in flight, the stale response must neither overwrite the new view nor stop its poll.
            var tickRunId = poll.runId;
            api('flow_run_view', { site_id: siteId, flow_id: poll.flowId, run_id: tickRunId }).then(function (d) {
                if (poll.runId !== tickRunId) { return; }   // superseded: discard the stale response
                if (!poll.node || !poll.node.isConnected) { stopRunPoll(); return; }
                poll.node.innerHTML = d.html || '';
                if ('processing' !== d.status) { stopRunPoll(); }
            }).catch(function () {
                if (poll.runId === tickRunId) { stopRunPoll(); }
            });
        }

        function showError(node, message) {
            node.innerHTML = '';
            node.appendChild(el('p', { class: 'wcd-error', text: message }));
        }

        function loadList() {
            api('flow_list', { site_id: siteId }).then(function (d) {
                stopRunPoll();
                root.innerHTML = d.html || '';
            }).catch(function (e) {
                showError(root, e.message);
            });
        }

        function flowOf(node) { return node.closest('.wcd-flow'); }

        function loadSteps(flowEl) {
            var box = flowEl.querySelector('[data-role="flow-steps"]');
            if (!box || box.getAttribute('data-loaded')) { return; }
            box.setAttribute('data-loaded', '1');
            box.innerHTML = '';
            box.appendChild(el('div', { class: 'ui active inline loader' }));
            api('flow_steps', { site_id: siteId, flow_id: flowEl.getAttribute('data-flow-id') }).then(function (d) {
                box.innerHTML = d.html || '';
            }).catch(function (e) {
                // Allow a retry on the next expand.
                box.removeAttribute('data-loaded');
                showError(box, e.message);
            });
        }

        function loadRuns(flowEl, page) {
            var box = flowEl.querySelector('[data-role="flow-runs"]');
            if (!box) { return; }
            if (!page && box.getAttribute('data-loaded')) { return; }
            box.setAttribute('data-loaded', '1');
            // The run-detail host lives inside this box; a (re)load drops any polled view.
            if (poll.node && box.contains(poll.node)) { stopRunPoll(); }
            box.innerHTML = '';
            box.appendChild(el('div', { class: 'ui active inline loader' }));
            api('flow_runs', { site_id: siteId, flow_id: flowEl.getAttribute('data-flow-id'), page: page || 1 }).then(function (d) {
                box.innerHTML = d.html || '';
            }).catch(function (e) {
                if (!page) { box.removeAttribute('data-loaded'); }
                showError(box, e.message);
            });
        }

        function toggleFlowRow(flowEl) {
            var bodyRow = flowEl.querySelector('.wcd-flow-body');
            if (!bodyRow) { return; }
            var open = !bodyRow.hidden;
            bodyRow.hidden = open;
            var caret = flowEl.querySelector('.accordion-trigger i.icon');
            if (caret) { caret.className = 'caret ' + (open ? 'right' : 'down') + ' icon'; }
            if (open) {
                // Collapsed: a polling run detail inside is no longer visible.
                if (poll.node && flowEl.contains(poll.node)) { stopRunPoll(); }
                return;
            }
            loadSteps(flowEl);
            loadRuns(flowEl);
        }

        function viewRun(btn) {
            var flowEl = flowOf(btn);
            var host = flowEl ? flowEl.querySelector('[data-role="run-detail"]') : null;
            if (!host) { return; }
            var runId = btn.getAttribute('data-run-id');
            if (!host.hidden && host.getAttribute('data-run-id') === runId) {
                // Same run clicked again: close the detail.
                host.hidden = true;
                host.innerHTML = '';
                host.removeAttribute('data-run-id');
                stopRunPoll();
                return;
            }
            stopRunPoll();
            host.hidden = false;
            host.setAttribute('data-run-id', runId);
            host.innerHTML = '';
            host.appendChild(el('div', { class: 'ui active inline loader' }));
            var flowId = flowEl.getAttribute('data-flow-id');
            api('flow_run_view', { site_id: siteId, flow_id: flowId, run_id: runId }).then(function (d) {
                host.innerHTML = d.html || '';
                if ('processing' === d.status) { startRunPoll(host, flowId, runId); }
            }).catch(function (e) {
                showError(host, e.message);
            });
        }

        function onToggleFlow(input) {
            var flowId = input.getAttribute('data-flow-id');
            var enabled = input.checked;
            // Cost transparency: enabling makes the flow's checkpoints billable checks, so it
            // needs an explicit confirm; disabling does not.
            if (enabled && !window.confirm(t('flowEnableConfirm'))) {
                setToggleChecked(input, false);
                return;
            }
            input.disabled = true;
            api('flow_toggle', { site_id: siteId, flow_id: flowId, enabled: enabled ? 1 : 0 }).then(function (d) {
                // Apply the state from the response only (no optimistic UI beyond the click).
                setToggleChecked(input, !!d.enabled_manual);
            }).catch(function (e) {
                setToggleChecked(input, !enabled);
                window.alert(e.message);
                // Plan gate hit despite the (now invalidated) cache: re-render the gated list
                // (toggles disabled + upsell message).
                if (e.data && e.data.upgrade) { loadList(); }
            }).finally(function () {
                input.disabled = false;
            });
        }

        root.addEventListener('click', function (e) {
            var runBtn = e.target.closest('.wcd-flow-run-view');
            if (runBtn) { viewRun(runBtn); return; }
            var pageBtn = e.target.closest('.wcd-flow-runs-page');
            if (pageBtn && !pageBtn.disabled) {
                var flowEl = flowOf(pageBtn);
                if (flowEl) { loadRuns(flowEl, parseInt(pageBtn.getAttribute('data-page'), 10) || 1); }
                return;
            }
            // Toggle clicks must never fold the accordion row.
            if (e.target.closest('.wcd-flow-toggle-cell')) { return; }
            var head = e.target.closest('.wcd-flow-head');
            if (head) { toggleFlowRow(head.closest('.wcd-flow')); }
        });

        root.addEventListener('change', function (e) {
            if (e.target.classList && e.target.classList.contains('wcd-flow-toggle')) { onToggleFlow(e.target); }
        });

        loadList();
    }

    function onReady() {
        loadBannerStats();
        initRuns();
        initFlows();
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
        }
    });
})();
