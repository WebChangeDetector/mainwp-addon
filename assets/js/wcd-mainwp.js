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
    var activeRun = false;      // true while a safe-update pipeline is in flight (drives close guard)

    function hasModalPlugin() {
        return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.modal === 'function');
    }

    // The run continues server-side regardless; this only stops the user from losing sight of it by
    // accident. Returns true when the modal may close (no active run, or the user confirmed).
    function mayCloseModal() {
        return !activeRun || window.confirm(t('closeRunning'));
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
                // Veto the close (X / dimmer) while a run is active unless the user confirms.
                onHide: mayCloseModal,
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

    // Locate the in-card run host that belongs to the clicked trigger (the .wcd-run-host sibling
    // rendered right after each .wcd-hero by entry-banner.php).
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
        [[t('sites'), runSites.length], [t('pages'), data.pages], [t('screenshots'), data.screenshots], [t('checks'), data.checks]].forEach(function (pair, i) {
            strip.appendChild(el('div', { class: 'wcd-pf-stat' + (3 === i ? ' is-accent' : '') }, [
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
        confirm.addEventListener('click', function () { closeModal(); startRun(host, trigger, runSites); });
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
            card: card, pill: pill, sub: sub, dismiss: dismiss, fill: fill, stepNodes: stepNodes,
            pre: pre, upd: upd, post: post, siteRefs: siteRefs, foot: foot,
            single: single, sites: sites, checkSites: checkSites, total: total
        };
    }

    function setFill(run, frac) { setWidth(run.fill, Math.max(0, Math.min(1, frac)) * 100); }

    function setPhase(run, idx) {
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

    function runUpdatesSequential(sites) {
        return sites.reduce(function (chain, s) {
            return chain.then(function () {
                // Tolerate a per-site update failure (e.g. offline): the post screenshots still run.
                return api('run_update', { site_id: s.site_id }).catch(function () {});
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

    function startRun(host, trigger, sites) {
        if (!host || activeRun) { return; }
        host.innerHTML = '';
        activeRun = true;
        setTriggerRunning(trigger, true);
        var single     = 1 === sites.length;
        var checkSites = sites.filter(function (s) { return s.checks > 0; });
        var total      = checkSites.reduce(function (n, s) { return n + s.checks; }, 0);
        var run = buildRun(host, sites, checkSites, single, total);
        run.trigger = trigger;
        run.dismiss.addEventListener('click', function () { if (!run.dismiss.disabled) { closeRun(run); } });
        // Track the run server-side so it can be resumed if this tab disappears mid-run.
        // Best effort: a failed tracking call must not block the run itself.
        var tracking = api('run_start', {
            site_ids: checkSites.map(function (s) { return s.site_id; }),
            names: checkSites.map(function (s) { return s.name; }),
            checks: checkSites.map(function (s) { return s.checks; })
        }).catch(function () {});
        tracking.then(function () { return runPhased(run); }).catch(function (e) { failRun(run, e); });
    }

    function runPhased(run) {
        var preBatchBySite = {}, postBatchBySite = {};
        var total = run.total;

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
            return runUpdatesSequential(run.sites);
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
        setTriggerRunning(run.trigger, true);
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
    // The run state lives server-side (recorded by the AJAX endpoints as the run progresses). If
    // the driving tab disappeared after updates were installed but before the post screenshots
    // were dispatched, the next page load offers to take them now (or discard the run).

    function checkResume() {
        var host = document.querySelector('.wcd-run-host');
        if (!host || host.querySelector('.wcd-run')) { return; }
        api('run_status', {}).then(function (d) {
            if (!d || !d.active || !d.stale || !(d.missing_post || []).length) { return; }
            renderResumeNotice(host, (d.missing_post || []).length);
        }).catch(function () {});
    }

    function renderResumeNotice(host, missingCount) {
        var body = fmt(1 === missingCount ? t('resumeBodySingle') : t('resumeBodyPlural'), { '%d': String(missingCount) });
        var resume = el('button', { class: 'ui small green button', type: 'button' }, [
            el('i', { class: 'camera icon' }),
            document.createTextNode(t('resumePost'))
        ]);
        var discard = el('button', { class: 'ui small basic button', type: 'button', text: t('discard') });
        var box = el('div', { class: 'ui warning message wcd-resume' }, [
            el('div', { class: 'header', text: t('resumeTitle') }),
            el('p', { text: body }),
            el('p', {}, [resume, discard])
        ]);
        resume.addEventListener('click', function () { startResume(host, box, resume); });
        discard.addEventListener('click', function () {
            api('run_discard', {}).catch(function () {});
            if (box.parentNode) { box.parentNode.removeChild(box); }
        });
        host.appendChild(box);
    }

    function startResume(host, box, button) {
        button.disabled = true;
        api('run_resume_post', {}).then(function (d) {
            if (box.parentNode) { box.parentNode.removeChild(box); }
            if (d.warning) { window.alert(d.warning); }
            var sites = d.sites || [];
            var batches = d.batches || {};
            if (!sites.length) { return; }
            activeRun = true;
            var single = 1 === sites.length;
            var total = sites.reduce(function (n, s) { return n + (Number(s.checks) || 0); }, 0);
            var run = buildRun(host, sites, sites, single, total);
            run.dismiss.addEventListener('click', function () { if (!run.dismiss.disabled) { closeRun(run); } });
            // Pre + updates already happened in the interrupted run; show them as done.
            setShot(run.pre, { queue: 0, processing: 0, done: total, failed: 0 });
            run.preBatches = values(d.pre_batches || {});
            runPostPhase(run, batches).catch(function (e) { failRun(run, e); });
        }).catch(function (e) {
            button.disabled = false;
            window.alert(e.message);
        });
    }

    function failRun(run, e) {
        activeRun = false;
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
    }

    function closeRun(run) {
        if (run.card && run.card.parentNode) { run.card.parentNode.removeChild(run.card); }
    }

    // Reflect the run state on the launch CTA (spinner + disabled while a run is active).
    function setTriggerRunning(trigger, running) {
        if (!trigger) { return; }
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
            $('#wcd-runs-visual, #wcd-runs-status, #wcd-runs-website', root).dropdown();
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
        if (activeRun) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.addEventListener('click', function (e) {
        var configure = e.target.closest && e.target.closest('.wcd-configure-urls');
        var sync = e.target.closest && e.target.closest('.wcd-sync-urls');
        var safe = e.target.closest && e.target.closest('.wcd-safe-update');

        if (configure) { e.preventDefault(); onConfigureUrls(configure); return; }
        if (sync) { e.preventDefault(); onSyncUrls(sync); return; }
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
