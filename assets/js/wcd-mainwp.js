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
                throw new Error((json && json.data && json.data.message) || t('genericError'));
            }
            return json.data;
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
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

    function hasModalPlugin() {
        return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function');
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
                onHidden: function () { clearModalContent(); }
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

    // Degraded path when Fomantic's modal JS is missing (it is always present on MainWP admin pages,
    // so this is a safety net). Emulate the dimmer with Fomantic's OWN classes so the modal is still
    // centered and dismissable, without reintroducing any custom modal CSS.
    function showFallback() {
        if (!fallbackDimmer) {
            fallbackDimmer = el('div', { class: 'ui dimmer modals page' });
            fallbackDimmer.addEventListener('click', function (e) {
                if (e.target === fallbackDimmer) { closeModal(); }
            });
            document.body.appendChild(fallbackDimmer);
        }
        fallbackDimmer.appendChild(modalNode);
        fallbackDimmer.classList.add('active', 'visible');
        modalNode.classList.add('active', 'visible');
        if (closeIconNode && !closeIconNode.getAttribute('data-wcd-bound')) {
            closeIconNode.setAttribute('data-wcd-bound', '1');
            closeIconNode.addEventListener('click', closeModal);
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

    function onToggleSite(checkbox) {
        var card = cardOf(checkbox);
        var siteId = siteIdOf(card);
        var enabled = checkbox.checked;
        checkbox.disabled = true;

        api('toggle_site', { site_id: siteId, enabled: enabled ? 1 : 0 }).then(function (data) {
            card.classList.toggle('wcd-on', enabled);
            card.querySelectorAll('.wcd-sync-urls, .wcd-configure-urls').forEach(function (b) { b.disabled = !enabled; });
            var count = card.querySelector('[data-role="urlcount"]');
            if (enabled) {
                if (data && data.synced) {
                    // URLs were sent to sync (queued); poll until they appear, then render.
                    pollUrls(card, 0);
                } else if (count) {
                    count.textContent = (data && data.sync_message) ? data.sync_message : '';
                }
            } else {
                if (count) { count.textContent = (S.disabled || 'Disabled'); }
                var cfgBox = card.querySelector('[data-role="urlconfig"]');
                if (cfgBox) { cfgBox.hidden = true; cfgBox.innerHTML = ''; }
            }
        }).catch(function (e) {
            checkbox.checked = !enabled;
            window.alert(e.message);
        }).finally(function () { checkbox.disabled = false; });
    }

    function renderUrlRows(card, urls) {
        var box = card.querySelector('[data-role="urlconfig"]');
        box.innerHTML = '';
        if (!urls.length) {
            box.appendChild(el('p', { class: 'wcd-muted', text: t('noChecks') }));
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
                        .catch(function (e) { input.checked = !input.checked; window.alert(e.message); });
                });
                vp.appendChild(el('label', {}, [input, document.createTextNode(' ' + kind)]));
            });
            box.appendChild(el('div', { class: 'wcd-url-row' }, [info, vp]));
        });
    }

    function loadUrls(card) {
        var box = card.querySelector('[data-role="urlconfig"]');
        box.hidden = false;
        box.innerHTML = '';
        box.appendChild(el('div', { class: 'ui active inline loader' }));
        return api('get_site_urls', { site_id: siteIdOf(card) }).then(function (data) {
            renderUrlRows(card, data.urls);
            var count = card.querySelector('[data-role="urlcount"]');
            if (count) { count.textContent = data.active + ' / ' + data.total; }
        }).catch(function (e) {
            box.innerHTML = '';
            box.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        });
    }

    function onConfigureUrls(button) {
        var card = cardOf(button);
        var box = card.querySelector('[data-role="urlconfig"]');
        if (!box.hidden) { box.hidden = true; return; }
        loadUrls(card);
    }

    function onSyncUrls(button) {
        var card = cardOf(button);
        var original = button.textContent;
        button.disabled = true;
        button.textContent = t('syncing');
        api('sync_urls', { site_id: siteIdOf(card) }).then(function () {
            return pollUrls(card, 0);
        }).catch(function (e) {
            window.alert(e.message);
        }).finally(function () {
            button.disabled = false;
            button.textContent = original;
        });
    }

    // start-sync is queued server-side; poll the group URLs until they appear.
    function pollUrls(card, tries) {
        return api('get_site_urls', { site_id: siteIdOf(card) }).then(function (data) {
            if (data.total > 0 || tries >= 10) {
                renderUrlRows(card, data.urls);
                card.querySelector('[data-role="urlconfig"]').hidden = false;
                var count = card.querySelector('[data-role="urlcount"]');
                if (count) { count.textContent = data.active + ' / ' + data.total; }
                return;
            }
            return delay(2000).then(function () { return pollUrls(card, tries + 1); });
        });
    }

    /* ─────────────────────────── Safe-update flow ──────────────────────── */
    // Entry is our own "Run visual check & update" button, so the run is always WITH WebChange
    // Detector (no with/without decision step). The button goes straight to the preflight.

    function preflightArgs(scope, siteId) {
        return scope === 'site' && siteId ? { site_id: siteId } : {};
    }

    function runPreflight(scope, siteId) {
        var modal = openModal();
        modalHead(modal, t('preflightTitle'));
        var body = el('div', { class: 'content' });
        body.appendChild(el('div', { class: 'ui active inline loader' }));
        modal.appendChild(body);

        api('preflight', preflightArgs(scope, siteId)).then(function (data) {
            body.innerHTML = '';
            if (!data.sites.length) {
                body.appendChild(el('p', { class: 'wcd-muted', text: t('noChecks') }));
                return;
            }

            body.appendChild(el('div', { class: 'ui two tiny statistics' }, [
                el('div', { class: 'statistic' }, [el('div', { class: 'value', text: String(data.sites.length) }), el('div', { class: 'label', text: 'Sites' })]),
                el('div', { class: 'statistic' }, [el('div', { class: 'value', text: String(data.checks) }), el('div', { class: 'label', text: 'Checks' })])
            ]));

            if (data.checks_left !== null && data.checks_left !== undefined) {
                var enough = data.enough;
                var creditText = data.checks + ' checks, ' + data.checks_left + ' available ';
                var pill = el('span', { class: 'ui mini ' + (enough ? 'green' : 'red') + ' label', text: enough ? t('enoughCredits') : t('notEnough') });
                body.appendChild(el('p', {}, [document.createTextNode(creditText), pill]));
            }

            var foot = el('div', { class: 'actions' });
            var cancel = el('button', { class: 'ui button', type: 'button', text: t('cancel') });
            cancel.addEventListener('click', closeModal);
            var confirm = el('button', { class: 'ui green button', type: 'button', text: t('confirmRun') + ' ' + data.sites.length });
            confirm.addEventListener('click', function () { runPipeline(data.sites); });
            foot.appendChild(cancel);
            foot.appendChild(confirm);
            modal.appendChild(foot);
        }).catch(function (e) {
            body.innerHTML = '';
            body.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        });
    }

    /* ───────────────────────── Running + results ───────────────────────── */

    function runPipeline(sites) {
        var modal = openModal();
        modalHead(modal, t('runningTitle'));
        var body = el('div', { class: 'scrolling content' });
        modal.appendChild(body);

        var cards = {};
        sites.forEach(function (site) {
            var ui = buildRunCard(site);
            body.appendChild(ui.card);
            cards[site.site_id] = ui;
        });

        var tasks = sites.map(function (site) { return runSite(site, cards[site.site_id]); });

        Promise.all(tasks.map(function (p) { return p.catch(function () {}); })).then(function () {
            if (!modal.querySelector('.actions')) {
                var foot = el('div', { class: 'actions' });
                var close = el('button', { class: 'ui green button', type: 'button', text: t('close') });
                close.addEventListener('click', closeModal);
                foot.appendChild(close);
                modal.appendChild(foot);
            }
        });
    }

    // Per-site run card: header + a circular Pre/Updates/Post/Done stepper + a queue/processing/done/
    // failed stat grid + a footer that flips to the results summary when finished. Everything is native
    // Fomantic except the circular stepper (Fomantic has no circular-node stepper).
    function buildRunCard(site) {
        var badge = el('span', { class: 'ui mini label', text: t('phasePre') });
        var head = el('div', { class: 'wcd-runcard-head' }, [
            el('div', { class: 'ui small header', text: site.name || ('Site #' + site.site_id) }),
            badge
        ]);

        var steps = {};
        var stepper = el('div', { class: 'wcd-prog' });
        [['pre', t('phasePre')], ['updates', t('phaseUpdates')], ['post', t('phasePost')], ['done', t('phaseDone')]].forEach(function (s) {
            var node = el('div', { class: 'wcd-prog-step' }, [
                el('span', { class: 'wcd-prog-dot' }),
                el('span', { class: 'wcd-prog-lbl', text: s[1] })
            ]);
            stepper.appendChild(node);
            steps[s[0]] = node;
        });

        var activity = el('div', { class: 'wcd-activity' });
        var foot = el('div', { class: 'wcd-runcard-foot' });
        foot.hidden = true;
        var results = el('div', {});
        results.hidden = true;

        var card = el('div', { class: 'ui segment wcd-runcard' }, [head, stepper, activity, foot, results]);

        return { card: card, badge: badge, steps: steps, activity: activity, foot: foot, results: results };
    }

    // Stepper node state: active (blue), done (green), error (red), or pending (grey when empty).
    function setStep(node, state) {
        if (!node) { return; }
        node.classList.remove('is-active', 'is-done', 'is-error');
        if (state) { node.classList.add('is-' + state); }
    }

    function setBadge(badge, key, color) {
        if (!badge) { return; }
        badge.textContent = t(key);
        badge.classList.remove('blue', 'green', 'red');
        if (color) { badge.classList.add(color); }
    }

    // The card's activity area: 'count' shows a single "in progress" number (queue + processing),
    // 'updating' shows a loader while updates install, anything else clears it.
    function setActivity(ui, mode, value) {
        ui.activity.innerHTML = '';
        if ('count' === mode) {
            ui.activity.appendChild(el('div', { class: 'ui mini statistic' }, [
                el('div', { class: 'value', text: String(value) }),
                el('div', { class: 'label', text: t('inProgress') })
            ]));
        } else if ('updating' === mode) {
            ui.activity.appendChild(el('div', { class: 'wcd-activity-updating' }, [
                el('div', { class: 'ui active inline loader' }),
                el('span', { text: t('stepUpdate') })
            ]));
        }
    }

    function remaining(data) {
        return (Number(data.queue) || 0) + (Number(data.processing) || 0);
    }

    // Poll a batch until its queue is empty, feeding the live counts to onCounts each tick. "complete"
    // requires at least one finished item so we never stop on a batch whose queue is not populated yet.
    function pollBatch(batch, onCounts) {
        if (!batch) { return Promise.resolve(); }
        var tries = 0;
        function loop() {
            return api('poll', { batch: batch }).then(function (data) {
                if (onCounts) { onCounts(data); }
                if (data.complete) { return; }
                tries++;
                if (tries >= POLL_MAX_TRIES) { throw new Error(t('stillRunning')); }
                return delay(POLL_INTERVAL).then(loop);
            });
        }
        return loop();
    }

    function runSite(site, ui) {
        var hasChecks = site.checks > 0;
        var postBatch = '';
        var chain = Promise.resolve();

        if (hasChecks) {
            setStep(ui.steps.pre, 'active');
            setBadge(ui.badge, 'phasePre', 'blue');
            chain = chain.then(function () {
                return api('take_pre', { site_id: site.site_id }).then(function (d) {
                    return pollBatch(d.batch, function (data) { setActivity(ui, 'count', remaining(data)); });
                });
            }).then(function () { setStep(ui.steps.pre, 'done'); });
        } else {
            setStep(ui.steps.pre, 'done');
        }

        chain = chain.then(function () {
            setStep(ui.steps.updates, 'active');
            setBadge(ui.badge, 'phaseUpdates', 'blue');
            setActivity(ui, 'updating');
            return api('run_update', { site_id: site.site_id });
        }).then(function () { setStep(ui.steps.updates, 'done'); });

        if (hasChecks) {
            chain = chain.then(function () {
                setStep(ui.steps.post, 'active');
                setBadge(ui.badge, 'phasePost', 'blue');
                return api('take_post', { site_id: site.site_id });
            }).then(function (d) {
                postBatch = d.batch;
                return pollBatch(postBatch, function (data) { setActivity(ui, 'count', remaining(data)); });
            }).then(function () {
                setStep(ui.steps.post, 'done');
                return api('results', { batch: postBatch });
            }).then(function (data) {
                setStep(ui.steps.done, 'done');
                setBadge(ui.badge, 'phaseDone', 'green');
                finishSite(ui, site, data.comparisons || []);
            });
        } else {
            chain = chain.then(function () {
                setStep(ui.steps.post, 'done');
                setStep(ui.steps.done, 'done');
                setBadge(ui.badge, 'phaseDone', 'green');
                setActivity(ui, '');
                ui.foot.hidden = false;
                ui.foot.innerHTML = '';
                ui.foot.appendChild(el('span', { class: 'wcd-muted', text: t('noChecks') }));
            });
        }

        return chain.catch(function (e) {
            ['pre', 'updates', 'post', 'done'].forEach(function (k) {
                if (ui.steps[k].classList.contains('is-active')) { setStep(ui.steps[k], 'error'); }
            });
            setBadge(ui.badge, 'phaseFailed', 'red');
            setActivity(ui, '');
            ui.foot.hidden = false;
            ui.foot.innerHTML = '';
            ui.foot.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        });
    }

    // Finished: clear the activity counter, render the comparison table inline (shown directly, no
    // extra click), then add the results summary + Re-check footer.
    function finishSite(ui, site, comparisons) {
        setActivity(ui, '');
        renderResults(ui.results, comparisons);
        ui.results.hidden = false;
        renderDoneFooter(ui, site, comparisons);
    }

    // When a site finishes: swap the status line for the results summary ("All good" / N changes) plus
    // View results (toggles the comparison table) and Re-check (re-runs the post screenshots + compare).
    function renderDoneFooter(ui, site, comparisons) {
        var changes = comparisons.filter(function (c) {
            return (Number(c.percent) || 0) > 0 || 'new' === c.status || 'to_fix' === c.status;
        }).length;

        ui.foot.hidden = false;
        ui.foot.innerHTML = '';
        if (changes > 0) {
            ui.foot.appendChild(el('span', { class: 'wcd-attn wcd-attn-changes' }, [
                el('i', { class: 'exclamation triangle icon' }),
                document.createTextNode(changes + ' ' + (1 === changes ? t('changeDetected') : t('changesDetected')))
            ]));
        } else {
            ui.foot.appendChild(el('span', { class: 'wcd-attn wcd-attn-ok' }, [
                el('i', { class: 'check circle icon' }),
                document.createTextNode(t('allGood'))
            ]));
        }

        var recheck = el('button', { class: 'ui mini button', type: 'button' }, [el('i', { class: 'redo icon' }), document.createTextNode(t('recheck'))]);
        recheck.addEventListener('click', function () { onRecheck(site, ui); });
        ui.foot.appendChild(recheck);
    }

    function onRecheck(site, ui) {
        if (ui.rechecking) { return; } // ignore rapid double-clicks while a re-check is in flight
        ui.rechecking = true;
        ui.results.hidden = true;
        ui.results.innerHTML = '';
        ui.foot.hidden = true;
        ui.foot.innerHTML = '';
        setStep(ui.steps.post, 'active');
        setStep(ui.steps.done, '');
        setBadge(ui.badge, 'phasePost', 'blue');

        var postBatch = '';
        api('take_post', { site_id: site.site_id }).then(function (d) {
            postBatch = d.batch;
            return pollBatch(postBatch, function (data) { setActivity(ui, 'count', remaining(data)); });
        }).then(function () {
            setStep(ui.steps.post, 'done');
            return api('results', { batch: postBatch });
        }).then(function (data) {
            setStep(ui.steps.done, 'done');
            setBadge(ui.badge, 'phaseDone', 'green');
            finishSite(ui, site, data.comparisons || []);
        }).catch(function (e) {
            setStep(ui.steps.post, 'error');
            setBadge(ui.badge, 'phaseFailed', 'red');
            setActivity(ui, '');
            ui.foot.hidden = false;
            ui.foot.innerHTML = '';
            ui.foot.appendChild(el('p', { class: 'wcd-error', text: e.message }));
        }).finally(function () { ui.rechecking = false; });
    }

    function statusPill(status) {
        var map = { ok: 'green', new: 'yellow', to_fix: 'red', false_positive: 'green' };
        var label = { ok: 'OK', new: 'Review', to_fix: 'Alert', false_positive: 'OK' };
        return el('span', { class: 'ui mini ' + (map[status] || 'yellow') + ' label', text: label[status] || status });
    }

    function renderResults(box, comparisons) {
        box.innerHTML = '';
        if (!comparisons || !comparisons.length) {
            box.appendChild(el('p', { class: 'wcd-muted', text: '0 comparisons' }));
            return;
        }
        var table = el('table', { class: 'ui celled striped table' });
        var thead = el('thead', {}, [el('tr', {}, ['Status', 'Page', 'Viewport', 'Change', 'AI summary', ''].map(function (h) {
            return el('th', { text: h });
        }))]);
        table.appendChild(thead);

        var tbody = el('tbody', {});
        comparisons.forEach(function (c) {
            var pct = Number(c.percent) || 0;
            var link = c.public ? el('a', { href: c.public, target: '_blank', rel: 'noopener', class: 'ui mini button', text: 'View' }) : document.createTextNode('');
            // At 0% difference there is nothing to analyse, so the AI summary is skipped server-side.
            var aiCell = 0 === pct
                ? el('td', {}, [el('span', { class: 'wcd-muted', text: t('aiSkipped') })])
                : el('td', { text: c.ai_summary || '' });
            tbody.appendChild(el('tr', {}, [
                el('td', {}, [statusPill(c.status)]),
                el('td', { text: c.url || '' }),
                el('td', { text: c.device || '' }),
                el('td', { text: pct.toFixed(1) + '%' }),
                aiCell,
                el('td', {}, [link])
            ]));
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    /* ───────────────────────────── Delegation ──────────────────────────── */

    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('wcd-site-toggle')) {
            onToggleSite(e.target);
        }
    });

    /* ───────────────────────── Hero banner stats ───────────────────────── */

    // Fill the banner's Pages/Checks once the dashboard has rendered (kept off the page-load path).
    function loadBannerStats() {
        var hero = document.querySelector('.wcd-hero[data-stats-scope]');
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadBannerStats);
    } else {
        loadBannerStats();
    }

    document.addEventListener('click', function (e) {
        var configure = e.target.closest && e.target.closest('.wcd-configure-urls');
        var sync = e.target.closest && e.target.closest('.wcd-sync-urls');
        var safe = e.target.closest && e.target.closest('.wcd-safe-update');

        if (configure) { e.preventDefault(); onConfigureUrls(configure); return; }
        if (sync) { e.preventDefault(); onSyncUrls(sync); return; }
        if (safe) {
            e.preventDefault();
            var scope = safe.getAttribute('data-scope') || 'bulk';
            var siteId = parseInt(safe.getAttribute('data-site-id'), 10) || 0;
            runPreflight(scope, siteId);
        }
    });
})();
