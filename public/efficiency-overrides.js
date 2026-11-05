'use strict';

function qrexClearResetHistoryReferences() {
    try {
        const version='2026-11-02-history-reset';
        if(localStorage.getItem('qrex-history-reset')===version)return;
        for(let index=localStorage.length-1;index>=0;index--) {
            const key=localStorage.key(index);
            if(['qrex-recent-so:','qrex-last-pallet:','qrex-daily-report-'].some(prefix=>key?.startsWith(prefix)))localStorage.removeItem(key);
        }
        localStorage.setItem('qrex-history-reset',version);
    } catch {}
}
qrexClearResetHistoryReferences();

const qrexBaseApi = api;
const qrexBaseClearWorkspaceCache = clearWorkspaceCache;
let qrexRecordsAbort = null;
let qrexCacheGeneration = 0;
const qrexActiveReads = new Set();
const qrexRecordsCache = new Map();
const qrexRecordsCacheTtl = 5 * 60 * 1000;
const qrexInspectionCache = new Map();
const qrexInspectionCacheTtl = 45 * 1000;
const qrexBootstrapCacheKey = () => `qrex-bootstrap-v2:${S.user?.id || 0}:${S.user?.role || ''}`;
const qrexRecentSoKey = product => `qrex-recent-so:${S.user?.id || 0}:${product}`;
const qrexLastPalletKey = () => `qrex-last-pallet:${S.user?.id || 0}`;
let qrexKnownAuth = qrexAuthContext();

function qrexAbortError() {
    const error = Error('This request is no longer current.');
    error.name = 'AbortError';
    return error;
}

// The callback must include body consumption so the deadline covers stalled JSON too.
async function qrexWithRequestTimeout(request, {signal = null, timeoutMs = 30000} = {}) {
    const controller = new AbortController();
    let timer, rejectAbort;
    const aborted = new Promise((resolve, reject) => { rejectAbort = reject; });
    const cancel = error => { rejectAbort(error); controller.abort(error); };
    const onAbort = () => cancel(signal.reason?.name === 'AbortError' ? signal.reason : qrexAbortError());
    try {
        if (signal?.aborted) throw signal.reason?.name === 'AbortError' ? signal.reason : qrexAbortError();
        signal?.addEventListener('abort', onAbort, {once: true});
        const delay = Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 30000;
        timer = setTimeout(() => {
            const error = Error('The request timed out. Your entries are still on this page.');
            error.name = 'TimeoutError';
            error.code = 'REQUEST_TIMEOUT';
            error.timeout = true;
            cancel(error);
        }, delay);
        return await Promise.race([Promise.resolve().then(() => {
            if (controller.signal.aborted) throw controller.signal.reason;
            return request(controller.signal);
        }), aborted]);
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
    }
}

// Global transport contract for app.js: parsed JSON, HTTP status, timeout=true, AbortError.
async function qrexRequestJson(url, options = {}, {timeoutMs = 30000} = {}) {
    let status;
    try {
        return await qrexWithRequestTimeout(async signal => {
            const response = await fetch(url, {...options, signal});
            status = response.status;
            let body;
            try { body = await response.json(); }
            catch (error) {
                if (signal.aborted) throw signal.reason;
                const unavailable = Error('The server is unavailable. Your entries are still on this page.');
                unavailable.status = status;
                throw unavailable;
            }
            if (!response.ok) {
                const error = Error(body?.error || 'The action could not be completed.');
                error.status = status;
                throw error;
            }
            return body;
        }, {signal: options.signal, timeoutMs});
    } catch (error) {
        if (status !== undefined && error.status === undefined) error.status = status;
        throw error;
    }
}

function qrexAuthContext() {
    return {user: S.user, id: S.user?.id, role: S.user?.role, csrf: S.csrf};
}
function qrexSameAuth(auth) {
    return auth.user === S.user && auth.id === S.user?.id && auth.role === S.user?.role && auth.csrf === S.csrf;
}
function qrexReadContext() {
    return {auth: qrexAuthContext(), generation: qrexCacheGeneration};
}
function qrexAssertCurrent(context) {
    if (context.generation !== qrexCacheGeneration || !qrexSameAuth(context.auth)) throw qrexAbortError();
}
function qrexAuthError(error) {
    return [401, 403, 419].includes(error.status);
}
function qrexDeepCopy(value) {
    return typeof structuredClone === 'function' ? structuredClone(value) : JSON.parse(JSON.stringify(value));
}
function qrexClearReferences(userId) {
    const id = userId || 0;
    try {
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const key = localStorage.key(i);
            if (key?.startsWith(`qrex-bootstrap-v2:${id}:`) || key?.startsWith(`qrex-recent-so:${id}:`) || key === `qrex-last-pallet:${id}`) localStorage.removeItem(key);
        }
    } catch {}
}

clearWorkspaceCache = function(...args) {
    qrexCacheGeneration++;
    qrexRecordsCache.clear();
    qrexInspectionCache.clear();
    for (const controller of qrexActiveReads) controller.abort(qrexAbortError());
    qrexActiveReads.clear();
    qrexRecordsAbort = null;
    qrexClearReferences(qrexKnownAuth.id);
    qrexClearReferences(S.user?.id);
    qrexKnownAuth = qrexAuthContext();
    return qrexBaseClearWorkspaceCache(...args);
};

async function qrexFetchJson(action, data = null, params = {}, signal = null, requestOptions = {}) {
    const context = qrexReadContext();
    const controller = new AbortController();
    const onAbort = () => controller.abort(qrexAbortError());
    if (signal?.aborted) onAbort();
    else signal?.addEventListener('abort', onAbort, {once: true});
    qrexActiveReads.add(controller);
    try {
        const body = await qrexRequestJson('api.php?' + new URLSearchParams({action, ...params}), {
            method: data !== null ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: data !== null ? {'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf} : {},
            body: data !== null ? JSON.stringify(data) : undefined,
            signal: controller.signal
        }, requestOptions);
        qrexAssertCurrent(context);
        return body;
    } catch (error) {
        qrexAssertCurrent(context);
        if (qrexAuthError(error)) clearWorkspaceCache();
        throw error;
    } finally {
        signal?.removeEventListener('abort', onAbort);
        qrexActiveReads.delete(controller);
    }
}

function qrexCacheRead(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); }
    catch { return null; }
}
function qrexCacheWrite(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
}
function qrexCacheRemove(key) {
    try { localStorage.removeItem(key); } catch {}
}
function qrexRecordsCacheKey(params = {}) {
    const query = new URLSearchParams(params);
    for (const [key, value] of [...query]) if (value === '' || (key === 'page' && Number(value) === 1)) query.delete(key);
    query.sort();
    return `${S.user?.id || 0}:${S.user?.role || ''}:records:${query}`;
}

api = async function(action, data = null, params = {}, requestOptions = {}) {
    if (!qrexSameAuth(qrexKnownAuth)) clearWorkspaceCache();
    if (requestOptions.signal?.aborted) throw qrexAbortError();
    const context = qrexReadContext();
    if (action === 'bootstrap' && data === null && S.user) {
        const key = qrexBootstrapCacheKey();
        try {
            const existing = qrexCacheRead(key);
            if (!existing?.data) {
                const fresh = await qrexBaseApi(action, data, params, requestOptions);
                qrexAssertCurrent(context);
                const version = fresh.version ? {version: fresh.version, csrf: fresh.csrf}
                    : await qrexBaseApi('bootstrap_version', null, {}, requestOptions);
                qrexAssertCurrent(context);
                if (version.csrf) {
                    S.csrf = version.csrf;
                    context.auth = qrexAuthContext();
                    qrexKnownAuth = qrexAuthContext();
                }
                qrexCacheWrite(key, {version: version.version, data: fresh, stored_at: Date.now()});
                return qrexDeepCopy({...fresh, user: S.user, csrf: S.csrf});
            }
            const version = await qrexBaseApi('bootstrap_version', null, {}, requestOptions);
            qrexAssertCurrent(context);
            if (version.csrf) {
                S.csrf = version.csrf;
                context.auth = qrexAuthContext();
                qrexKnownAuth = qrexAuthContext();
            }
            const cached = qrexCacheRead(key);
            if (cached?.version === version.version && cached?.data) {
                return qrexDeepCopy({...cached.data, user: S.user, csrf: S.csrf});
            }
            const fresh = await qrexBaseApi(action, data, params, requestOptions);
            qrexAssertCurrent(context);
            qrexCacheWrite(key, {version: version.version, data: fresh, stored_at: Date.now()});
            return qrexDeepCopy(fresh);
        } catch (error) {
            if (!qrexSameAuth(context.auth)) throw qrexAbortError();
            if (qrexAuthError(error)) { clearWorkspaceCache(); throw error; }
            qrexAssertCurrent(context);
            if (error.name === 'AbortError' || requestOptions.signal?.aborted) throw qrexAbortError();
            const cached = qrexCacheRead(key);
            if (cached?.data) return qrexDeepCopy({...cached.data, user: S.user, csrf: S.csrf});
            throw error;
        }
    }
    if (data === null && (action === 'records' || action === 'inspection')) {
        // A caller-owned deadline/signal must not cancel another caller's shared request.
        if (requestOptions.signal || requestOptions.timeoutMs !== undefined) {
            return qrexDeepCopy(await qrexFetchJson(action, null, params, requestOptions.signal, requestOptions));
        }
        const cache = action === 'records' ? qrexRecordsCache : qrexInspectionCache;
        const key = action === 'records' ? qrexRecordsCacheKey(params) : `${S.user?.id || 0}:${S.user?.role || ''}:inspection:${new URLSearchParams(params)}`;
        const cached = cache.get(key);
        if (cached && (cached.pending || cached.expires > Date.now())) {
            const body = await cached.promise;
            qrexAssertCurrent(context);
            return qrexDeepCopy(body);
        }
        let controller = null;
        if (action === 'records') {
            qrexRecordsAbort?.abort();
            controller = new AbortController();
            qrexRecordsAbort = controller;
        }
        const entry = {pending: true, expires: 0, promise: null};
        entry.promise = qrexFetchJson(action, null, params, controller?.signal).then(body => {
            qrexAssertCurrent(context);
            entry.pending = false;
            entry.expires = Date.now() + (action === 'records' ? qrexRecordsCacheTtl : qrexInspectionCacheTtl);
            for (const [oldKey, oldEntry] of cache) {
                if (cache.size <= 40) break;
                if (!oldEntry.pending && oldKey !== key) cache.delete(oldKey);
            }
            return qrexDeepCopy(body);
        }).catch(error => {
            if (cache.get(key) === entry) cache.delete(key);
            throw error;
        }).finally(() => {
            if (controller && qrexRecordsAbort === controller) qrexRecordsAbort = null;
        });
        cache.set(key, entry);
        const body = await entry.promise;
        qrexAssertCurrent(context);
        return qrexDeepCopy(body);
    }
    const changesData = data !== null && action !== 'evaluate';
    if (changesData) clearWorkspaceCache();
    try {
        const result = await qrexBaseApi(action, data, params, requestOptions);
        if (!qrexSameAuth(context.auth) || requestOptions.signal?.aborted) throw qrexAbortError();
        if (!changesData) qrexAssertCurrent(context);
        return qrexDeepCopy(result);
    } catch (error) {
        if (!qrexSameAuth(context.auth)) throw qrexAbortError();
        if (qrexAuthError(error)) clearWorkspaceCache();
        else if (!changesData) qrexAssertCurrent(context);
        throw error;
    } finally {
        if (changesData) clearWorkspaceCache();
    }
};

function qrexTableSkeleton(rows = 8, cols = 8) {
    return `<div class="surface"><div class="table-wrap"><table class="skeleton-table" aria-hidden="true"><thead><tr>${Array.from({length: cols}, () => '<th><span></span></th>').join('')}</tr></thead><tbody>${Array.from({length: rows}, () => `<tr>${Array.from({length: cols}, () => '<td><span></span></td>').join('')}</tr>`).join('')}</tbody></table></div></div>`;
}
pageLoading = function() {
    $('#main').innerHTML = `<div class="page-skeleton"><div class="skeleton-heading"><span></span><strong></strong></div>${qrexTableSkeleton(7, 7)}</div>`;
};

function qrexRememberSo(product, so, total = 0) {
    if (!product || !so) return;
    const key = qrexRecentSoKey(product);
    const rows = (qrexCacheRead(key) || []).filter(row => row.so !== so);
    rows.unshift({so, total, at: Date.now()});
    qrexCacheWrite(key, rows.slice(0, 6));
}
function qrexRecentSoChips(product) {
    const rows = qrexCacheRead(qrexRecentSoKey(product)) || [];
    if (!rows.length) return '';
    return `<div class="recent-so-chips"><span>Recent SO</span>${rows.map(row => `<button type="button" data-action="recent-so-chip" data-product="${esc(product)}" data-so="${esc(row.so)}">${esc(row.so)}${row.total ? ` <small>${Number(row.total)}</small>` : ''}</button>`).join('')}</div>`;
}

const qrexBaseRecordsShell = recordsShell;
recordsShell = function(q, body) {
    const html = qrexBaseRecordsShell(q, body);
    const product = q.get('product') || '';
    const density = localStorage.getItem('qrex-table-density') === 'compact' ? 'Comfort view' : 'Compact view';
    const densityButton = `<button type="button" class="btn small" data-action="table-density">${icon('columns-3')} ${density}</button>`;
    let out = html.replace('<div class="actions">', `<div class="actions">${densityButton}`);
    if (product) out = out.replace('</form><div id="records-data">', `</form>${qrexRecentSoChips(product)}<div id="records-data">`);
    return out;
};

const qrexBaseRecords = records;
records = async function(q, ticket) {
    await qrexBaseRecords(q, ticket);
    if (ticket !== S.routeTicket) return;
    const product = q.get('product') || '';
    const so = q.get('so') || '';
    const totalText = $('#records-data .section-head .muted')?.textContent || '';
    const total = Number((totalText.match(/(\d+)\s+inspection/) || [])[1] || 0);
    if (product && so) qrexRememberSo(product, so, total);
    document.body.classList.toggle('table-density-compact', localStorage.getItem('qrex-table-density') === 'compact');
};

const qrexBaseDashboard = dashboard;
dashboard = async function(ticket) {
    await qrexBaseDashboard(ticket);
    if (ticket !== S.routeTicket) return;
    const last = qrexCacheRead(qrexLastPalletKey());
    if (last?.id) {
        const target = $('.overview-welcome') || $('#main').firstElementChild;
        target?.insertAdjacentHTML('afterend', `<section class="continue-last-card"><div>${icon('player-play')}<span><strong>Continue last pallet</strong><small>SO ${esc(last.so || '-')} / ${esc(last.unit || 'Pallet')} &middot; ${esc(productLabel(last.product || ''))}</small></span></div><button type="button" class="btn primary small" data-action="continue-last-pallet" data-id="${esc(last.id)}">${icon('arrow-right')} Continue</button></section>`);
    }
};

const qrexBaseInspection = inspection;
inspection = async function(q, ticket) {
    await qrexBaseInspection(q, ticket);
    if (ticket !== S.routeTicket || !S.inspection?.editable) return;
    qrexCacheWrite(qrexLastPalletKey(), {
        id: S.inspection.id,
        so: S.inspection.so_number,
        unit: S.inspection.unit_label,
        product: S.inspection.product,
        at: Date.now()
    });
};

document.addEventListener('click', e => {
    const el = e.target.closest('[data-action]');
    if (!el) return;
    if (el.dataset.action === 'recent-so-chip') {
        const params = new URLSearchParams({product: el.dataset.product, so: el.dataset.so});
        location.hash = 'records?' + params;
    }
    if (el.dataset.action === 'table-density') {
        const compact = localStorage.getItem('qrex-table-density') !== 'compact';
        localStorage.setItem('qrex-table-density', compact ? 'compact' : 'comfortable');
        document.body.classList.toggle('table-density-compact', compact);
        el.innerHTML = icon('columns-3') + ' ' + (compact ? 'Comfort view' : 'Compact view');
    }
    if (el.dataset.action === 'continue-last-pallet') {
        location.hash = 'inspection?id=' + el.dataset.id;
    }
}, true);
