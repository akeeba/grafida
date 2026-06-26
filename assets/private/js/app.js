/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Vanilla JS SPA — no framework. ES2020.
 *
 * Security note: every place that inserts content into the DOM uses one of:
 *   - element.textContent = ... (plain text, never treated as HTML)
 *   - safe DOM-builder helpers (el(), txt(), …)
 *   - element.value = ... (input values)
 *   - element.setAttribute(...) for attributes
 * innerHTML is only used for static developer-authored strings (no user data).
 */

'use strict';

// ============================================================
//  Global state
// ============================================================

const State = {
    strings: {},
    language: 'en-GB',
    languageOverride: 'auto',
    availableLanguages: {},
    // Interface display-mode preference: 'auto' (follow OS), 'light' or 'dark'.
    displayMode: 'auto',
    // The OS light/dark preference as probed by the back-end (Boson's webview
    // does not report `prefers-color-scheme` reliably): true/false, or null when
    // undetectable (then we fall back to the media query).
    systemPrefersDark: null,
    // The concrete theme currently applied ('light' | 'dark') after resolving
    // 'auto' against the OS preference. Drives the TinyMCE skin/content CSS.
    resolvedTheme: 'dark',
    secureStore: true,
    supportedFieldTypes: [],
    app: {},
    sites: [],
    currentSiteId: null,
    // The draft currently open in the editor. Held in memory and only written to
    // the database on the first Save — a new or remote-imported article leaves no
    // trace until the user explicitly saves it. `id` is null while unsaved.
    currentDraft: null,
    currentDraftId: null,
    // JSON snapshot of the editor form taken when the draft opened / was last saved,
    // used to detect unsaved changes.
    editorBaseline: null,
    // Forces the "dirty" state regardless of the form snapshot — set when the user
    // re-points the draft at another site, a change the snapshot cannot capture.
    editorForceDirty: false,
    // The site the open draft was last persisted to; the articles list returns
    // here on leave so a draft moved (and saved) to another site stays visible,
    // while an unsaved, then discarded, move does not move the list.
    editorSavedSiteId: null,
    drafts: [],
    remoteArticles: [],
    // The remote-article browse state: current filters, sort and page, plus the
    // last page's pagination total. Reset whenever the active site changes (see
    // defaultArticleQuery / loadArticlesScreen). `articleListSiteId` records the
    // site the current query belongs to; `articleListRefs` caches that site's
    // categories/tags/languages for the filter dropdowns.
    articleQuery: null,
    articlePaging: { page: 1, totalPages: 1 },
    articleListSiteId: null,
    articleListRefs: null,
    references: null,
    editorCss: null,
    tinyMCEEditor: null,
    activeScreen: 'sites',
    // Working copy of the open draft's article images (intro + full text), the
    // single source of truth for the editor's Images section. See collectImages().
    editorImages: {},
    // Cache of preview data: URIs for offline image blobs, keyed by their
    // `grafida-media://N` reference, so re-renders don't re-fetch.
    mediaPreviews: {},
    // Maps a data: URI inserted into TinyMCE this session to the offline blob id
    // it came from, so inline images get tagged with data-grafida-media-id and
    // are uploaded to the site on publish (see the editor's tagging handler).
    inlineMediaByUri: {},
};

// ============================================================
//  i18n helper
// ============================================================

function t(key) {
    return State.strings[key] || key;
}

// ============================================================
//  Safe DOM builder helpers
// ============================================================

/**
 * Create an element with optional className and append children.
 * Children may be strings (created as text nodes) or HTMLElements.
 * All string children are inserted as textContent — never as HTML.
 */
function el(tag, className, ...children) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    for (const child of children) {
        if (child == null) continue;
        if (typeof child === 'string' || typeof child === 'number') {
            node.appendChild(document.createTextNode(String(child)));
        } else if (child instanceof Node) {
            node.appendChild(child);
        }
    }
    return node;
}

/** Create a text node. */
function txt(str) {
    return document.createTextNode(String(str));
}

/**
 * Interpolate a localized template into a list of DOM nodes.
 *
 * The template is a single, whole sentence containing `%s` placeholders (one
 * per substitution, in order) — this keeps each translatable string intact so
 * translators control word order, instead of splitting a sentence around an
 * injected value. Each `%s` is replaced by the matching substitution, which
 * may be a DOM Node (e.g. a bold <strong>) or a string; everything else
 * becomes plain text nodes.
 *
 * @param {string} template — localized string with `%s` placeholders
 * @param {...(Node|string)} subs — substitutions, in placeholder order
 * @returns {Node[]} text/substituted nodes, ready to spread into el()
 */
function formatNodes(template, ...subs) {
    const parts = String(template).split('%s');
    const nodes = [];
    parts.forEach((part, i) => {
        if (part !== '') nodes.push(txt(part));
        if (i < parts.length - 1) {
            const sub = subs[i];
            nodes.push(sub instanceof Node ? sub : txt(sub == null ? '' : String(sub)));
        }
    });
    return nodes;
}

/** Create a button with textContent and class names. */
function btn(labelKey, ...classes) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = classes.join(' ');
    b.textContent = t(labelKey);
    return b;
}

/**
 * Create a decorative FontAwesome solid icon element. The icon is purely
 * visual, so it is hidden from assistive technology.
 */
function icon(name) {
    const i = document.createElement('i');
    i.className = 'fa-solid fa-' + name;
    i.setAttribute('aria-hidden', 'true');
    return i;
}

/** Create an action button with a leading icon followed by a text label. */
function iconBtn(iconName, label, ...classes) {
    const b = el('button', classes.join(' '), icon(iconName), txt(label));
    b.type = 'button';
    return b;
}

/**
 * Build a 64x64 rounded-square favicon element for a site. Falls back to a
 * globe glyph when the site has no cached favicon.
 */
function siteFaviconEl(site) {
    const box = el('div', 'site-favicon');
    if (site && site.favicon) {
        const img = document.createElement('img');
        img.src = site.favicon;
        img.alt = '';
        box.appendChild(img);
    } else {
        box.classList.add('site-favicon-empty');
        box.appendChild(icon('globe'));
    }
    return box;
}

/** Create a labelled form-control group using DOM methods. */
function formGroup(labelText, inputEl) {
    const group = document.createElement('div');
    group.className = 'form-group';
    const lbl = document.createElement('label');
    lbl.textContent = labelText;
    group.appendChild(lbl);
    group.appendChild(inputEl);
    return group;
}

/** Append multiple children to a parent. */
function appendChildren(parent, ...children) {
    for (const child of children) {
        if (child != null) parent.appendChild(child);
    }
    return parent;
}

/** Remove all children from a node. */
function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

// ============================================================
//  API helpers
// ============================================================

async function apiFetch(method, path, body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    };
    if (body !== null) {
        opts.body = JSON.stringify(body);
    }
    const res = await fetch('boson://app' + path, opts);
    let json;
    try {
        json = await res.json();
    } catch {
        throw new Error('Invalid JSON response from server');
    }
    if (!json.ok) {
        const err = new Error(json.error || 'API error');
        err.code = json.code || null;
        err.fieldLabels = json.fieldLabels || null;
        err.status = res.status;
        throw err;
    }
    return json.data;
}

const api = {
    bootstrap: () => apiFetch('GET', '/api/bootstrap'),
    testConnection: (url, token) => apiFetch('POST', '/api/sites/test', { url, token }),
    listSites: () => apiFetch('GET', '/api/sites'),
    createSite: (body) => apiFetch('POST', '/api/sites', body),
    updateSite: (id, body) => apiFetch('PATCH', `/api/sites/${id}`, body),
    deleteSite: (id) => apiFetch('DELETE', `/api/sites/${id}`),
    getReferences: (siteId) => apiFetch('GET', `/api/sites/${siteId}/references`),
    refreshReferences: (siteId) => apiFetch('POST', `/api/sites/${siteId}/references/refresh`),
    getEditorCss: (siteId) => apiFetch('GET', `/api/sites/${siteId}/editor-css`),
    getRemoteArticles: (siteId, params = {}) => {
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (v !== '' && v != null) qs.set(k, String(v));
        });
        const tail = qs.toString();
        return apiFetch('GET', `/api/sites/${siteId}/articles${tail ? `?${tail}` : ''}`);
    },
    getRemoteArticle: (siteId, articleId) => apiFetch('GET', `/api/sites/${siteId}/articles/${articleId}`),
    getDrafts: (siteId) => apiFetch('GET', `/api/sites/${siteId}/drafts`),
    createDraft: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/drafts`, body),
    getDraft: (id) => apiFetch('GET', `/api/drafts/${id}`),
    saveDraft: (id, body) => apiFetch('PUT', `/api/drafts/${id}`, body),
    deleteDraft: (id) => apiFetch('DELETE', `/api/drafts/${id}`),
    publishDraft: (id) => apiFetch('POST', `/api/drafts/${id}/publish`),
    uploadMedia: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media`, body),
    browseMedia: (siteId, path = '') => apiFetch('GET', `/api/sites/${siteId}/media?path=${encodeURIComponent(path)}`),
    getMediaBlob: (id) => apiFetch('GET', `/api/media/${id}`),
    convertMarkdown: (markdown) => apiFetch('POST', '/api/markdown', { markdown }),
    setLanguage: (tag) => apiFetch('POST', '/api/settings/language', { tag }),
    setDisplayMode: (mode) => apiFetch('POST', '/api/settings/display-mode', { mode }),
    systemTheme: () => apiFetch('GET', '/api/settings/system-theme'),
    getStorageInfo: () => apiFetch('GET', '/api/settings/storage'),
    openStorageFolder: () => apiFetch('POST', '/api/settings/storage/open'),
    resetStorage: () => apiFetch('POST', '/api/settings/storage/reset'),
    openUrl: (url) => apiFetch('POST', '/api/open-url', { url }),
};

// ============================================================
//  Toast notifications
// ============================================================

function showToast(msg, type = 'info', duration = 3500) {
    const container = document.getElementById('toast-container');
    const toastEl = el('div', `toast toast-${type}`);
    toastEl.textContent = String(msg);
    container.appendChild(toastEl);
    setTimeout(() => {
        toastEl.style.opacity = '0';
        toastEl.style.transition = 'opacity 0.3s';
        setTimeout(() => toastEl.remove(), 300);
    }, duration);
}

// ============================================================
//  Modal
// ============================================================

/**
 * Show a modal dialog.
 * @param {string} titleText — modal heading (inserted as textContent)
 * @param {Node|Node[]} bodyNodes — DOM nodes for the body
 * @param {Node[]} footerNodes — DOM nodes for the footer
 */
function showModal(titleText, bodyNodes, footerNodes) {
    const overlay = document.getElementById('modal-overlay');
    const titleEl = document.getElementById('modal-title');
    const bodyEl = document.getElementById('modal-body');
    const footerEl = document.getElementById('modal-footer');

    titleEl.textContent = titleText;
    clearNode(bodyEl);
    clearNode(footerEl);

    const bNodes = Array.isArray(bodyNodes) ? bodyNodes : [bodyNodes];
    const fNodes = Array.isArray(footerNodes) ? footerNodes : [footerNodes];
    bNodes.forEach(n => n && bodyEl.appendChild(n));
    fNodes.forEach(n => n && footerEl.appendChild(n));

    overlay.classList.remove('hidden');
    overlay.onclick = (e) => { if (e.target === overlay) closeModal(); };
    document.addEventListener('keydown', _modalEscHandler);
}

function _modalEscHandler(e) {
    if (e.key === 'Escape') closeModal();
}

function closeModal() {
    document.getElementById('modal-overlay').classList.add('hidden');
    document.removeEventListener('keydown', _modalEscHandler);
}

/**
 * Show a Yes/No confirmation modal with keyboard navigation.
 *
 * Yes is red (danger) and is the default active option; No is blue (info).
 * ENTER or SPACE applies the active option; TAB (or Shift+TAB) cycles which
 * option is active; ESC cancels (No). Clicking either button or the backdrop
 * resolves accordingly.
 *
 * @param {string} titleText — modal heading
 * @param {Node|Node[]} bodyNodes — DOM nodes for the body
 * @returns {Promise<boolean>} resolves true for Yes, false for No/cancel.
 */
function confirmYesNo(titleText, bodyNodes) {
    return new Promise(resolve => {
        const yesBtn = iconBtn('check', t('GRAFIDA_BTN_YES'), 'btn', 'btn-danger');
        const noBtn = iconBtn('xmark', t('GRAFIDA_BTN_NO'), 'btn', 'btn-info');

        const buttons = [yesBtn, noBtn];
        let active = 0; // default: Yes

        const setActive = (i) => {
            active = (i + buttons.length) % buttons.length;
            buttons[active].focus();
        };

        const finish = (result) => {
            document.removeEventListener('keydown', onKey, true);
            closeModal();
            resolve(result);
        };

        function onKey(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                setActive(active + (e.shiftKey ? -1 : 1));
            } else if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                finish(active === 0);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                finish(false);
            }
        }

        yesBtn.addEventListener('click', () => finish(true));
        noBtn.addEventListener('click', () => finish(false));

        // Capture phase so we intercept SPACE/ENTER before the focused button's
        // own native activation, keeping a single source of truth for the result.
        document.addEventListener('keydown', onKey, true);

        showModal(titleText, bodyNodes, buttons);
        // showModal closes on backdrop click without resolving; route it to "No".
        document.getElementById('modal-overlay').onclick = (e) => {
            if (e.target === e.currentTarget) finish(false);
        };
        setActive(0);
    });
}

// ============================================================
//  Screen routing
// ============================================================

function showScreen(name) {
    State.activeScreen = name;
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    const screen = document.getElementById(`${name}-screen`);
    if (screen) screen.classList.add('active');

    document.querySelectorAll('nav#main-nav a').forEach(a => {
        a.classList.toggle('active', a.dataset.screen === name);
    });
}

// ============================================================
//  Site selector in sidebar
// ============================================================

const LAST_SITE_KEY = 'grafida.lastSiteId';

function rememberLastSite(id) {
    try {
        if (id) localStorage.setItem(LAST_SITE_KEY, String(id));
    } catch (e) { /* storage may be unavailable */ }
}

function recallLastSite() {
    try {
        const raw = localStorage.getItem(LAST_SITE_KEY);
        return raw ? parseInt(raw, 10) : null;
    } catch (e) {
        return null;
    }
}

/** Refresh the favicon shown below the sidebar site dropdown. */
function renderSidebarFavicon() {
    const box = document.getElementById('sidebar-site-favicon');
    if (!box) return;
    clearNode(box);

    const site = State.currentSiteId
        ? State.sites.find(s => s.id === State.currentSiteId)
        : null;

    if (site) box.appendChild(siteFaviconEl(site));
}

function renderSiteSelector() {
    const sel = document.getElementById('site-select');
    clearNode(sel);

    // The placeholder is only meaningful when there is nothing to select.
    if (!State.sites.length) {
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = `— ${t('GRAFIDA_MSG_NO_SITES')} —`;
        sel.appendChild(defaultOpt);
        sel.disabled = true;
        State.currentSiteId = null;
        renderSidebarFavicon();
        updateNewArticleButton();
        updateNavState();
        return;
    }

    State.sites.forEach(site => {
        const opt = document.createElement('option');
        opt.value = site.id;
        opt.textContent = site.title;
        sel.appendChild(opt);
    });

    // Preselect: the current site, else the last used one, else the first.
    let selectedId = null;
    if (State.currentSiteId && State.sites.find(s => s.id === State.currentSiteId)) {
        selectedId = State.currentSiteId;
    } else {
        const remembered = recallLastSite();
        if (remembered && State.sites.find(s => s.id === remembered)) {
            selectedId = remembered;
        } else {
            selectedId = State.sites[0].id;
        }
    }

    sel.value = selectedId;
    State.currentSiteId = selectedId;
    rememberLastSite(selectedId);

    // A single-option drop-down offers no choice, so disable it.
    sel.disabled = State.sites.length === 1;

    renderSidebarFavicon();
    updateNewArticleButton();
    updateNavState();
}

/**
 * Programmatically switch the active site (mirrors the nav drop-down change):
 * updates state, the drop-down value and the cached reference data.
 */
function selectSite(siteId) {
    if (!siteId || !State.sites.find(s => s.id === siteId)) return;
    State.currentSiteId = siteId;
    rememberLastSite(siteId);
    State.references = null;
    State.editorCss = null;
    const sel = document.getElementById('site-select');
    if (sel) sel.value = String(siteId);
    renderSidebarFavicon();
    updateNewArticleButton();
}

function updateNavState() {
    const hasSites = State.sites.length > 0;
    const articlesLink = document.querySelector('nav#main-nav a[data-screen="articles"]');
    if (articlesLink) {
        articlesLink.classList.toggle('disabled', !hasSites);
        articlesLink.setAttribute('aria-disabled', hasSites ? 'false' : 'true');
    }
    // The Articles screen is unusable without a site; fall back to Sites.
    if (!hasSites && State.activeScreen === 'articles') {
        showScreen('sites');
    }
}

function updateNewArticleButton() {
    const btnEl = document.getElementById('btn-new-article');
    if (btnEl) btnEl.disabled = !State.currentSiteId;
}

// ============================================================
//  SITES SCREEN
// ============================================================

function renderSitesScreen() {
    const list = document.getElementById('sites-list');
    clearNode(list);

    if (!State.sites.length) {
        const emptyDiv = el('div', 'empty-state',
            el('p', null, t('GRAFIDA_MSG_NO_SITES'))
        );
        list.appendChild(emptyDiv);
        return;
    }

    State.sites.forEach(site => {
        const item = buildSiteItem(site);
        list.appendChild(item);
    });
}

/**
 * Re-fetch reference metadata (categories, tags, access levels, languages,
 * custom fields) from the site, bypassing the local cache. Updates
 * State.references when the reloaded site is the one currently open in the
 * editor. The optional button is disabled while the request is in flight.
 */
async function reloadSiteMetadata(siteId, button) {
    if (!siteId) return false;
    if (button) button.disabled = true;
    try {
        const refs = await api.refreshReferences(siteId);
        const editorSiteId = State.currentDraft ? State.currentDraft.siteId : null;
        if (siteId === State.currentSiteId || siteId === editorSiteId) State.references = refs;

        // The refresh re-downloads the favicon; reflect it on the Sites list and
        // in the sidebar without a full reload.
        if (refs && 'favicon' in refs) {
            const site = State.sites.find(s => s.id === siteId);
            if (site) {
                site.favicon = refs.favicon;
                renderSitesScreen();
                if (siteId === State.currentSiteId) renderSidebarFavicon();
            }
        }

        showToast(t('GRAFIDA_MSG_REFS_REFRESHED'), 'success');
        return true;
    } catch (err) {
        showToast(err.message, 'error');
        return false;
    } finally {
        if (button) button.disabled = false;
    }
}

function buildSiteItem(site) {
    const info = el('div', 'site-item-info',
        el('div', 'site-item-title', site.title || ''),
        el('div', 'site-item-url', site.baseUrl || site.url || '')
    );

    const btnEdit = iconBtn('pen', t('GRAFIDA_BTN_EDIT'), 'btn', 'btn-sm', 'btn-secondary');
    btnEdit.addEventListener('click', () => openEditSiteModal(site.id));

    const btnDel = iconBtn('trash', t('GRAFIDA_BTN_DELETE'), 'btn', 'btn-sm', 'btn-danger');
    btnDel.addEventListener('click', () => confirmDeleteSite(site.id));

    const btnReload = iconBtn('arrows-rotate', t('GRAFIDA_BTN_RELOAD_METADATA'), 'btn', 'btn-sm', 'btn-secondary');
    btnReload.addEventListener('click', () => reloadSiteMetadata(site.id, btnReload));

    const actions = el('div', 'site-item-actions', btnReload, btnEdit, btnDel);
    return el('div', 'site-item', siteFaviconEl(site), info, actions);
}

function buildSiteFormBody(site = null) {
    // Title
    const titleInput = document.createElement('input');
    titleInput.id = 'modal-site-title';
    titleInput.type = 'text';
    titleInput.className = 'form-control';
    titleInput.autocomplete = 'off';
    if (site) titleInput.value = site.title || '';

    // URL
    const urlInput = document.createElement('input');
    urlInput.id = 'modal-site-url';
    urlInput.type = 'url';
    urlInput.className = 'form-control';
    urlInput.autocomplete = 'off';
    urlInput.placeholder = 'https://example.com';
    if (site) urlInput.value = site.baseUrl || '';

    // Token
    const tokenInput = document.createElement('input');
    tokenInput.id = 'modal-site-token';
    tokenInput.type = 'password';
    tokenInput.className = 'form-control';
    tokenInput.autocomplete = 'off';

    const tokenLabel = document.createElement('label');
    tokenLabel.textContent = t('GRAFIDA_LBL_TOKEN');
    if (site) {
        const hint = el('span', 'text-muted', ' (leave blank to keep)');
        tokenLabel.appendChild(hint);
    }

    const tokenGroup = el('div', 'form-group', tokenLabel, tokenInput);

    // Test connection row
    const testBtn = iconBtn('plug', t('GRAFIDA_BTN_TEST_CONNECTION'), 'btn', 'btn-secondary');
    testBtn.id = 'btn-test-connection';
    const testResult = el('span', null);
    testResult.id = 'test-result';
    const testRow = el('div', 'form-actions', testBtn, testResult);

    return [
        formGroup(t('GRAFIDA_LBL_TITLE'), titleInput),
        formGroup(t('GRAFIDA_LBL_URL'), urlInput),
        tokenGroup,
        testRow,
    ];
}

function buildSiteFormFooter(saveHandler) {
    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    cancelBtn.addEventListener('click', closeModal);

    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
    saveBtn.id = 'btn-save-site';
    saveBtn.addEventListener('click', saveHandler);

    return [cancelBtn, saveBtn];
}

function openAddSiteModal() {
    const body = buildSiteFormBody(null);
    const footer = buildSiteFormFooter(() => saveSiteHandler(null));
    showModal(t('GRAFIDA_BTN_ADD_SITE'), body, footer);
    document.getElementById('btn-test-connection').addEventListener('click', testConnectionHandler);
}

function openEditSiteModal(id) {
    const site = State.sites.find(s => s.id === id);
    if (!site) return;
    const body = buildSiteFormBody(site);
    const footer = buildSiteFormFooter(() => saveSiteHandler(id));
    showModal(t('GRAFIDA_BTN_EDIT'), body, footer);
    document.getElementById('btn-test-connection').addEventListener('click', testConnectionHandler);
}

async function testConnectionHandler() {
    const urlEl = document.getElementById('modal-site-url');
    const tokenEl = document.getElementById('modal-site-token');
    const resultEl = document.getElementById('test-result');
    const testBtn = document.getElementById('btn-test-connection');
    const url = urlEl ? urlEl.value.trim() : '';
    const token = tokenEl ? tokenEl.value.trim() : '';

    if (!url || !token) {
        resultEl.className = 'text-muted';
        resultEl.textContent = 'Please enter URL and token.';
        return;
    }

    testBtn.disabled = true;
    resultEl.textContent = '…';
    resultEl.className = '';

    try {
        await api.testConnection(url, token);
        resultEl.className = 'alert alert-success';
        resultEl.textContent = t('GRAFIDA_MSG_CONNECTION_OK');
    } catch {
        resultEl.className = 'alert alert-error';
        resultEl.textContent = t('GRAFIDA_MSG_CONNECTION_FAIL');
    } finally {
        testBtn.disabled = false;
    }
}

async function saveSiteHandler(id) {
    const titleEl = document.getElementById('modal-site-title');
    const urlEl = document.getElementById('modal-site-url');
    const tokenEl = document.getElementById('modal-site-token');
    const title = titleEl ? titleEl.value.trim() : '';
    const url = urlEl ? urlEl.value.trim() : '';
    const token = tokenEl ? tokenEl.value.trim() : '';

    if (!title || !url) {
        showToast('Title and URL are required.', 'error');
        return;
    }

    const body = { title, url };
    if (token) body.token = token;

    try {
        if (id === null) {
            await createSiteWithInsecureFallback(body);
        } else {
            body.allowInsecure = false;
            await api.updateSite(id, body);
        }
        closeModal();
        const sites = await api.listSites();
        State.sites = sites;
        renderSitesScreen();
        renderSiteSelector();
        showToast(t('GRAFIDA_MSG_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function createSiteWithInsecureFallback(body) {
    body.allowInsecure = false;
    try {
        await api.createSite(body);
    } catch (err) {
        if (err.code === 'secure_store_unavailable') {
            const accepted = await showInsecureWarning();
            if (!accepted) {
                try { window.close(); } catch {}
                // Clear the page as a best-effort last resort
                const root = document.getElementById('app');
                if (root) clearNode(root);
                return;
            }
            body.allowInsecure = true;
            await api.createSite(body);
        } else {
            throw err;
        }
    }
}

function showInsecureWarning() {
    return new Promise((resolve) => {
        const msgP = el('p', null, t('GRAFIDA_MSG_INSECURE_WARNING'));

        const declineBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
        declineBtn.id = 'btn-insecure-decline';

        const acceptBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
        acceptBtn.id = 'btn-insecure-accept';

        showModal('⚠️ Warning', [msgP], [declineBtn, acceptBtn]);

        document.getElementById('btn-insecure-accept').onclick = () => { closeModal(); resolve(true); };
        document.getElementById('btn-insecure-decline').onclick = () => { closeModal(); resolve(false); };
    });
}

async function confirmDeleteSite(id) {
    const site = State.sites.find(s => s.id === id);
    if (!site) return;

    const siteNameStrong = el('strong', null, site.title || '');
    const msgP = el('p', null, ...formatNodes(t('GRAFIDA_MSG_DELETE_SITE_CONFIRM'), siteNameStrong));

    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    cancelBtn.addEventListener('click', closeModal);

    const delBtn = iconBtn('trash', t('GRAFIDA_BTN_DELETE'), 'btn', 'btn-danger');
    delBtn.id = 'btn-confirm-delete';
    delBtn.addEventListener('click', async () => {
        try {
            await api.deleteSite(id);
            closeModal();
            if (State.currentSiteId === id) {
                State.currentSiteId = null;
                State.references = null;
            }
            const sites = await api.listSites();
            State.sites = sites;
            renderSitesScreen();
            renderSiteSelector();
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    showModal(t('GRAFIDA_BTN_DELETE'), [msgP], [cancelBtn, delBtn]);
}

// ============================================================
//  ARTICLES SCREEN
// ============================================================

// The columns the remote-article list may be sorted by, mirroring Joomla's
// back-end "Sort by" dropdown (only the columns the REST API actually accepts as
// `list[ordering]`). Direction is a separate control. Default is `a.id`.
const ARTICLE_SORT_COLUMNS = [
    ['a.id', 'GRAFIDA_SORT_ID'],
    ['a.title', 'GRAFIDA_SORT_TITLE'],
    ['category_title', 'GRAFIDA_SORT_CATEGORY'],
    ['a.access', 'GRAFIDA_SORT_ACCESS'],
    ['a.created_by', 'GRAFIDA_SORT_AUTHOR'],
    ['language', 'GRAFIDA_SORT_LANGUAGE'],
    ['a.created', 'GRAFIDA_SORT_CREATED'],
    ['a.modified', 'GRAFIDA_SORT_MODIFIED'],
    ['a.publish_up', 'GRAFIDA_SORT_PUBLISH_UP'],
    ['a.publish_down', 'GRAFIDA_SORT_PUBLISH_DOWN'],
    ['a.hits', 'GRAFIDA_SORT_HITS'],
    ['a.featured', 'GRAFIDA_SORT_FEATURED'],
    ['a.state', 'GRAFIDA_SORT_STATUS'],
    ['a.ordering', 'GRAFIDA_SORT_ORDERING'],
];

const ARTICLE_PER_PAGE = [5, 10, 15, 20, 25, 30, 50, 100];

/** The default filter/sort/page state for the remote-article list. */
function defaultArticleQuery() {
    return {
        search: '', ordering: 'a.id', direction: 'desc',
        category: '', tag: '', language: '', state: '',
        featured: '', checked_out: '',
        limit: 20, page: 1,
    };
}

let _articleSearchTimer = null;

async function loadArticlesScreen() {
    const container = document.getElementById('articles-container');

    if (!State.currentSiteId) {
        clearNode(container);
        container.appendChild(el('div', 'empty-state', el('p', null, t('GRAFIDA_MSG_SELECT_SITE'))));
        return;
    }

    // A new active site starts from a clean query and drops the cached filter
    // reference data (categories/tags/languages are per-site).
    if (State.articleListSiteId !== State.currentSiteId || !State.articleQuery) {
        State.articleListSiteId = State.currentSiteId;
        State.articleQuery = defaultArticleQuery();
        State.articleListRefs = null;
    }

    clearNode(container);
    container.appendChild(el('div', 'loading-row', el('div', 'spinner'), txt(' ' + t('GRAFIDA_MSG_LOADING'))));

    try {
        // Drafts and the filter reference data are loaded once per visit; the
        // remote article page is then fetched (and refetched on every filter,
        // sort or page change) by reloadRemoteArticles().
        const [drafts] = await Promise.all([
            api.getDrafts(State.currentSiteId),
            loadArticleFilterRefs(),
        ]);
        State.drafts = drafts || [];

        clearNode(container);
        const draftsSection = el('div', 'article-list-section');
        draftsSection.id = 'articles-drafts';
        container.appendChild(draftsSection);

        const remoteSection = el('div', 'article-list-section');
        remoteSection.appendChild(el('h3', null, t('GRAFIDA_LBL_REMOTE_ARTICLES')));
        remoteSection.appendChild(buildArticleFilterBar());
        const list = el('div', null);
        list.id = 'articles-remote-list';
        remoteSection.appendChild(list);
        const pager = el('div', 'articles-pagination');
        pager.id = 'articles-pagination';
        remoteSection.appendChild(pager);
        container.appendChild(remoteSection);

        renderDraftsSection();
        await reloadRemoteArticles();
    } catch (err) {
        clearNode(container);
        container.appendChild(el('div', 'alert alert-error', String(err.message)));
    }
}

/** Loads (and caches per-site) the categories/tags/languages for the filter bar. */
async function loadArticleFilterRefs() {
    if (State.articleListRefs && State.articleListRefs.siteId === State.currentSiteId) return;
    try {
        const refs = await api.getReferences(State.currentSiteId);
        State.articleListRefs = {
            siteId: State.currentSiteId,
            categories: refs.categories || [],
            tags: refs.tags || [],
            languages: refs.languages || [],
        };
    } catch {
        State.articleListRefs = { siteId: State.currentSiteId, categories: [], tags: [], languages: [] };
    }
}

/** Renders just the local-drafts section (used on load and after a draft delete). */
function renderDraftsSection() {
    const section = document.getElementById('articles-drafts');
    if (!section) return;
    clearNode(section);
    if (!State.drafts.length) return;
    section.appendChild(el('h3', null, t('GRAFIDA_LBL_LOCAL_DRAFTS')));
    State.drafts.forEach(draft => section.appendChild(buildArticleItem(draft, 'draft')));
}

/** Builds the persistent search / sort / filter toolbar for remote articles. */
function buildArticleFilterBar() {
    const q = State.articleQuery;
    const bar = el('div', 'articles-filter-bar');

    // Search box (debounced) + explicit search button.
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control';
    search.placeholder = t('GRAFIDA_PLACEHOLDER_SEARCH');
    search.value = q.search;
    search.setAttribute('aria-label', t('GRAFIDA_PLACEHOLDER_SEARCH'));
    search.addEventListener('input', () => {
        clearTimeout(_articleSearchTimer);
        _articleSearchTimer = setTimeout(() => setArticleQuery({ search: search.value }), 400);
    });
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { clearTimeout(_articleSearchTimer); setArticleQuery({ search: search.value }); }
    });
    bar.appendChild(el('div', 'articles-filter-search', search));

    // Sort column + direction.
    bar.appendChild(filterSelect('GRAFIDA_LBL_SORT_BY',
        ARTICLE_SORT_COLUMNS.map(([v, k]) => [v, t(k)]), q.ordering, false,
        (v) => setArticleQuery({ ordering: v })));
    bar.appendChild(filterSelect('GRAFIDA_LBL_DIRECTION', [
        ['desc', t('GRAFIDA_SORT_DIR_DESC')],
        ['asc', t('GRAFIDA_SORT_DIR_ASC')],
    ], q.direction, false, (v) => setArticleQuery({ direction: v })));

    // Category / Tag / Language filters from the cached reference data.
    const refs = State.articleListRefs || { categories: [], tags: [], languages: [] };
    bar.appendChild(filterSelect('GRAFIDA_FILTER_CATEGORY_ANY',
        categoryFilterOptions(refs.categories), q.category, true,
        (v) => setArticleQuery({ category: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_TAG_ANY',
        (refs.tags || []).map(tg => [String(tg.id), tg.title]), q.tag, true,
        (v) => setArticleQuery({ tag: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_LANGUAGE_ANY',
        languageFilterOptions(refs.languages), q.language, true,
        (v) => setArticleQuery({ language: v })));

    // Published state / featured / checked-out filters.
    bar.appendChild(filterSelect('GRAFIDA_FILTER_STATE_ANY', [
        ['1', t('GRAFIDA_OPT_PUBLISHED')],
        ['0', t('GRAFIDA_OPT_UNPUBLISHED')],
        ['2', t('GRAFIDA_OPT_ARCHIVED')],
        ['-2', t('GRAFIDA_OPT_TRASHED')],
    ], q.state, true, (v) => setArticleQuery({ state: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_FEATURED_ANY', [
        ['1', t('GRAFIDA_FILTER_FEATURED_YES')],
        ['0', t('GRAFIDA_FILTER_FEATURED_NO')],
    ], q.featured, true, (v) => setArticleQuery({ featured: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_CHECKEDOUT_ANY', [
        ['-1', t('GRAFIDA_FILTER_CHECKEDOUT_YES')],
        ['0', t('GRAFIDA_FILTER_CHECKEDOUT_NO')],
    ], q.checked_out, true, (v) => setArticleQuery({ checked_out: v })));

    // Per-page limit.
    bar.appendChild(filterSelect('GRAFIDA_LBL_PER_PAGE',
        ARTICLE_PER_PAGE.map(n => [String(n), String(n)]), String(q.limit), false,
        (v) => setArticleQuery({ limit: Number(v) })));

    // Clear filters.
    const clearBtn = iconBtn('rotate-left', t('GRAFIDA_BTN_CLEAR_FILTERS'), 'btn', 'btn-secondary', 'btn-sm');
    clearBtn.addEventListener('click', () => {
        State.articleQuery = defaultArticleQuery();
        const remoteSection = document.getElementById('articles-remote-list').parentNode;
        const oldBar = remoteSection.querySelector('.articles-filter-bar');
        remoteSection.replaceChild(buildArticleFilterBar(), oldBar);
        reloadRemoteArticles();
    });
    bar.appendChild(el('div', 'articles-filter-clear', clearBtn));

    return bar;
}

/**
 * A labelled <select> for the filter bar. When `withAny` is true, `anyKey` is
 * the leading "no filter" option (value ''); otherwise it is the field's label.
 */
function filterSelect(anyKey, options, selected, withAny, onChange) {
    const wrap = el('div', 'articles-filter-field');
    const sel = document.createElement('select');
    sel.className = 'form-control';
    sel.setAttribute('aria-label', t(anyKey));
    if (withAny) {
        const any = document.createElement('option');
        any.value = '';
        any.textContent = t(anyKey);
        sel.appendChild(any);
    } else {
        const lbl = el('label', 'articles-filter-label', t(anyKey));
        wrap.appendChild(lbl);
    }
    options.forEach(([value, label]) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        if (String(selected) === String(value)) opt.selected = true;
        sel.appendChild(opt);
    });
    sel.addEventListener('change', () => onChange(sel.value));
    wrap.appendChild(sel);
    return wrap;
}

/** Flattened, indented [id, label] category options (mirrors buildCategorySelect). */
function categoryFilterOptions(categories) {
    if (!categories.length) return [];
    if (categories[0].level === undefined) {
        return categories.map(c => [String(c.id), c.title]);
    }
    const ordered = categories.slice().sort((a, b) => (Number(a.lft) || 0) - (Number(b.lft) || 0));
    const minLevel = Math.min(...ordered.map(c => Number(c.level) || 0));
    return ordered.map(c => {
        const depth = (Number(c.level) || 0) - minLevel;
        return [String(c.id), ' '.repeat(depth * 4) + c.title];
    });
}

/** [code, label] content-language options for the language filter. */
function languageFilterOptions(languages) {
    const opts = [['*', t('GRAFIDA_OPT_LANG_ALL')]];
    (languages || [])
        .filter(l => l.published === undefined || Number(l.published) === 1)
        .forEach(l => { if (l.lang_code) opts.push([l.lang_code, `${l.title || l.lang_code} (${l.lang_code})`]); });
    return opts;
}

/** Applies a patch to the article query (resetting to page 1) and refetches. */
function setArticleQuery(patch) {
    State.articleQuery = { ...State.articleQuery, ...patch, page: 1 };
    reloadRemoteArticles();
}

/** Fetches the current remote-article page and re-renders the list + pagination. */
async function reloadRemoteArticles() {
    const list = document.getElementById('articles-remote-list');
    const pager = document.getElementById('articles-pagination');
    if (!list) return;
    clearNode(list);
    clearNode(pager);
    list.appendChild(el('div', 'loading-row', el('div', 'spinner'), txt(' ' + t('GRAFIDA_MSG_LOADING'))));

    const siteId = State.currentSiteId;
    try {
        const result = await api.getRemoteArticles(siteId, State.articleQuery);
        // Ignore a response that arrived after the user moved on.
        if (siteId !== State.currentSiteId) return;
        State.remoteArticles = Array.isArray(result.items) ? result.items : [];
        State.articlePaging = { page: result.page || 1, totalPages: result.totalPages || 1 };
        renderRemoteArticles();
    } catch (err) {
        clearNode(list);
        list.appendChild(el('div', 'alert alert-error', String(err.message)));
    }
}

/** Renders the current remote-article items and pagination controls. */
function renderRemoteArticles() {
    const list = document.getElementById('articles-remote-list');
    const pager = document.getElementById('articles-pagination');
    if (!list || !pager) return;
    clearNode(list);
    clearNode(pager);

    if (!State.remoteArticles.length) {
        list.appendChild(el('div', 'empty-state', el('p', null, t('GRAFIDA_MSG_NO_REMOTE_ARTICLES'))));
        return;
    }

    // A remote article that already has a local draft stays in the list, but is
    // flagged so the user can jump straight to that draft (see openEditorFor).
    State.remoteArticles.forEach(article => list.appendChild(buildArticleItem(article, 'remote')));

    const { page, totalPages } = State.articlePaging;
    if (totalPages <= 1) return;

    const prev = iconBtn('chevron-left', t('GRAFIDA_BTN_PREV_PAGE'), 'btn', 'btn-secondary', 'btn-sm');
    prev.disabled = page <= 1;
    prev.addEventListener('click', () => gotoArticlePage(page - 1));

    const next = iconBtn('chevron-right', t('GRAFIDA_BTN_NEXT_PAGE'), 'btn', 'btn-secondary', 'btn-sm');
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => gotoArticlePage(page + 1));

    const info = el('span', 'articles-pagination-info',
        ...formatNodes(t('GRAFIDA_PAGINATION_INFO'), String(page), String(totalPages)));

    pager.appendChild(prev);
    pager.appendChild(info);
    pager.appendChild(next);
}

/** Moves to a specific remote-article page (keeping all filters) and refetches. */
function gotoArticlePage(page) {
    const total = State.articlePaging.totalPages || 1;
    const clamped = Math.max(1, Math.min(total, page));
    State.articleQuery = { ...State.articleQuery, page: clamped };
    reloadRemoteArticles();
}

function buildArticleItem(article, type) {
    const item = el('div', 'article-item');

    const titleDiv = el('div', 'article-item-title', article.title || '(Untitled)');
    const infoDiv = el('div', 'article-item-info', titleDiv);
    if (article.catid != null) {
        const label = article.categoryTitle
            ? `[#${article.catid}] ${article.categoryTitle}`
            : `[#${article.catid}]`;
        infoDiv.appendChild(el('div', 'article-item-category', label));
    }
    if (article.alias) {
        infoDiv.appendChild(el('div', 'article-item-meta article-item-alias', article.alias));
    }

    const badgeClass = type === 'draft' ? 'badge-draft' : 'badge-remote';
    const badgeText = type === 'draft' ? 'Draft' : 'Remote';
    const badge = el('span', `article-badge ${badgeClass}`, badgeText);

    item.appendChild(infoDiv);

    // A remote article that is already mirrored by a local draft gets an extra
    // badge; clicking it opens that draft rather than re-importing the article.
    const hasDraft = type === 'remote'
        && State.drafts.some(d => d.remoteId != null && d.remoteId === article.id);
    if (hasDraft) {
        item.classList.add('article-item-has-draft');
        item.appendChild(el('span', 'article-badge badge-draft', t('GRAFIDA_LBL_HAS_LOCAL_DRAFT')));
    }

    item.appendChild(badge);

    // Local drafts can be deleted; remote articles are read-only here.
    if (type === 'draft') {
        const actions = el('div', 'article-item-actions');
        const delBtn = el('button', 'btn-icon article-item-delete');
        delBtn.type = 'button';
        delBtn.title = t('GRAFIDA_BTN_DELETE');
        delBtn.setAttribute('aria-label', t('GRAFIDA_BTN_DELETE'));
        delBtn.appendChild(icon('trash'));
        delBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            confirmDeleteDraft(article);
        });
        actions.appendChild(delBtn);
        item.appendChild(actions);
    }

    item.addEventListener('click', () => openEditorFor(article, type));
    return item;
}

async function confirmDeleteDraft(draft) {
    const titleStrong = el('strong', null, draft.title || '(Untitled)');
    const msgP = el('p', null, ...formatNodes(t('GRAFIDA_MSG_DELETE_DRAFT_CONFIRM'), titleStrong));

    const confirmed = await confirmYesNo(t('GRAFIDA_MSG_DELETE_DRAFT_TITLE'), [msgP]);
    if (!confirmed) return;

    try {
        await api.deleteDraft(draft.id);
        State.drafts = State.drafts.filter(d => d.id !== draft.id);
        renderDraftsSection();
        renderRemoteArticles();
        showToast(t('GRAFIDA_MSG_DRAFT_DELETED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function openEditorFor(article, type) {
    // An existing local draft opens directly.
    if (type === 'draft') {
        await openDraftInEditor(article);
        return;
    }

    // A remote article that already has a local draft (same site + remote id)
    // must reuse it rather than spawn a second draft.
    const existing = State.drafts.find(d => d.remoteId != null && d.remoteId === article.id);
    if (existing) {
        await openDraftInEditor(existing);
        return;
    }

    // Otherwise fetch the full article (the list only carries a teaser) and open
    // it as an unsaved draft — nothing is written to the database until Save.
    let draft;
    try {
        draft = await api.getRemoteArticle(State.currentSiteId, article.id);
    } catch (err) {
        showToast(err.message, 'error');
        return;
    }
    await openDraftInEditor(draft);
}

async function openNewArticle() {
    if (!State.currentSiteId) return;
    await openDraftInEditor({
        id: null,
        siteId: State.currentSiteId,
        remoteId: null,
        title: '',
        alias: '',
        catid: null,
        access: 1,
        language: '*',
        state: 1,
        html: '',
        fields: {},
        tags: [],
        images: {},
        metadesc: '',
        metakey: '',
    });
}

/** Opens a draft object (persisted or unsaved) in the editor. */
async function openDraftInEditor(draft) {
    State.currentDraft = draft;
    State.currentDraftId = draft.id ?? null;
    State.editorForceDirty = false;
    State.editorSavedSiteId = draft.siteId ?? State.currentSiteId;
    await openEditorScreen(draft);
}

// ============================================================
//  EDITOR SCREEN
// ============================================================

async function openEditorScreen(draft) {
    showScreen('editor');

    const needRefs = !State.references;
    const needCss = State.editorCss === null;
    const promises = [];

    if (needRefs) {
        promises.push(api.getReferences(draft.siteId).then(r => { State.references = r; }));
    }
    if (needCss) {
        promises.push(
            api.getEditorCss(draft.siteId)
                .then(r => { State.editorCss = r.css; })
                .catch(() => { State.editorCss = null; })
        );
    }

    if (promises.length > 0) {
        try { await Promise.all(promises); } catch {}
    }

    renderEditorSidebar(draft);
    await initTinyMCE(draft);

    // Snapshot the freshly-loaded form so we can detect unsaved changes later.
    State.editorBaseline = JSON.stringify(collectDraftFormData());
}

function renderEditorSidebar(draft) {
    const sidebar = document.getElementById('editor-sidebar-inner');
    clearNode(sidebar);

    // Seed the working copy of the article images from the draft (preserved
    // across metadata reloads, which pass the collected images back in).
    State.editorImages = normalizeImages(draft.images);

    const refs = State.references || {
        categories: [], tags: [], levels: [], languages: [],
        fields: { supported: [], unsupported: [] },
    };

    // Title in main area
    const titleInput = document.getElementById('editor-title-input');
    if (titleInput) titleInput.value = draft.title || '';

    // Site this draft belongs to. Re-pointing it at another site unlinks it from
    // any remote article (the user is warned first) — see changeEditorSite().
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_SITE'), buildSiteSelect(draft.siteId)));

    // Status (Joomla published state). The Publish button is a sync/push; this
    // controls the state the article is given on the site, independent of pushing.
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_STATUS'), buildStatusSelect(draft.state)));

    // Category
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_CATEGORY'), buildCategorySelect(refs.categories, draft.catid)));

    // Access
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_ACCESS'), buildAccessSelect(refs.levels, draft.access)));

    // Language — the site's installed CONTENT languages, not the app's UI languages.
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_LANGUAGE'), buildLanguageSelect(refs.languages || [], draft.language)));

    // Tags
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_TAGS'), buildTagsInput(refs.tags, draft.tags || [])));

    // Custom fields (supported)
    if (refs.fields.supported && refs.fields.supported.length > 0) {
        const sec = el('div', null);
        const secTitle = el('div', 'section-title', 'Custom Fields');
        sec.appendChild(secTitle);
        refs.fields.supported.forEach(field => {
            const val = (draft.fields || {})[field.name];
            const fg = formGroup(field.label, buildFieldInput(field, val));
            fg.dataset.fieldName = field.name;
            sec.appendChild(fg);
        });
        sidebar.appendChild(sec);
    }

    // Unsupported fields notice
    if (refs.fields.unsupported && refs.fields.unsupported.length > 0) {
        const names = refs.fields.unsupported.map(f => f.label).join(', ');
        const notice = el('div', 'unsupported-fields-notice',
            `Unsupported fields (not editable): ${names}`
        );
        sidebar.appendChild(notice);
    }

    // Meta description
    const metadescEl = document.createElement('textarea');
    metadescEl.id = 'editor-metadesc';
    metadescEl.className = 'form-control';
    metadescEl.rows = 3;
    metadescEl.value = draft.metadesc || '';
    sidebar.appendChild(formGroup('Meta description', metadescEl));

    // Meta keywords
    const metakeyEl = document.createElement('input');
    metakeyEl.id = 'editor-metakey';
    metakeyEl.type = 'text';
    metakeyEl.className = 'form-control';
    metakeyEl.value = draft.metakey || '';
    sidebar.appendChild(formGroup('Meta keywords', metakeyEl));

    // Intro / full-text article images (Joomla's "Images and Links" tab).
    sidebar.appendChild(renderImagesSection(draft.siteId));

    // Reload the site's reference metadata (categories, tags, access levels,
    // languages, custom fields). Re-renders the sidebar afterwards, preserving
    // the current unsaved selections.
    const reloadBtn = iconBtn('arrows-rotate', t('GRAFIDA_BTN_RELOAD_METADATA'), 'btn', 'btn-sm', 'btn-secondary');
    reloadBtn.addEventListener('click', async () => {
        const current = collectDraftFormData();
        const ok = await reloadSiteMetadata(draft.siteId, reloadBtn);
        if (ok) renderEditorSidebar({ ...draft, ...current });
    });
    sidebar.appendChild(el('div', 'sidebar-reload', reloadBtn));
}

// Site picker for the open draft. Changing it moves the draft to another site;
// if the draft mirrors a remote article the user is warned that this unlinks it.
function buildSiteSelect(selectedSiteId) {
    const sel = document.createElement('select');
    sel.id = 'editor-site';
    sel.className = 'form-control';

    State.sites.forEach(site => {
        const opt = document.createElement('option');
        opt.value = String(site.id);
        opt.textContent = site.title;
        if (site.id === selectedSiteId) opt.selected = true;
        sel.appendChild(opt);
    });

    sel.disabled = State.sites.length <= 1;
    sel.addEventListener('change', () => changeEditorSite(parseInt(sel.value, 10)));
    return sel;
}

async function changeEditorSite(newSiteId) {
    const draft = State.currentDraft;
    if (!draft || !newSiteId || newSiteId === draft.siteId) return;

    // Moving a draft that mirrors a remote article turns it into a new article;
    // make the user confirm, and revert the dropdown if they decline.
    if (draft.remoteId != null) {
        const confirmed = await confirmYesNo(
            t('GRAFIDA_MSG_CHANGE_SITE_TITLE'),
            [el('p', null, t('GRAFIDA_MSG_CHANGE_SITE_CONFIRM'))]
        );
        if (!confirmed) {
            const siteSel = document.getElementById('editor-site');
            if (siteSel) siteSel.value = String(draft.siteId);
            return;
        }
        draft.remoteId = null;
    }

    // Capture the user's edits, then re-point the draft. Category, access,
    // language and custom fields are site-specific, so they reset to defaults.
    Object.assign(draft, collectDraftFormData());
    draft.siteId = newSiteId;
    draft.catid = null;
    draft.access = 1;
    draft.language = '*';
    draft.fields = {};

    // A site move is not reflected in the form snapshot, so flag it dirty.
    State.editorForceDirty = true;

    // Reload the new site's reference data and editor CSS, then rebuild.
    State.references = null;
    State.editorCss = null;
    try {
        State.references = await api.getReferences(newSiteId);
    } catch (err) {
        showToast(err.message, 'error');
    }
    try {
        State.editorCss = (await api.getEditorCss(newSiteId)).css;
    } catch {
        State.editorCss = null;
    }

    renderEditorSidebar(draft);
    await initTinyMCE(draft);
}

// Joomla article states: 1 published, 0 unpublished, 2 archived, -2 trashed.
function buildStatusSelect(selectedState) {
    const sel = document.createElement('select');
    sel.id = 'editor-state';
    sel.className = 'form-control';

    const states = [
        { value: 1, label: t('GRAFIDA_OPT_PUBLISHED') },
        { value: 0, label: t('GRAFIDA_OPT_UNPUBLISHED') },
        { value: 2, label: t('GRAFIDA_OPT_ARCHIVED') },
        { value: -2, label: t('GRAFIDA_OPT_TRASHED') },
    ];

    const current = selectedState ?? 1;
    states.forEach(s => {
        const opt = document.createElement('option');
        opt.value = String(s.value);
        opt.textContent = s.label;
        if (s.value === current) opt.selected = true;
        sel.appendChild(opt);
    });
    return sel;
}

function buildCategorySelect(categories, selectedCatid) {
    const sel = document.createElement('select');
    sel.id = 'editor-catid';
    sel.className = 'form-control';

    const none = document.createElement('option');
    none.value = '';
    none.textContent = '— None —';
    sel.appendChild(none);

    if (categories.length > 0 && categories[0].level !== undefined) {
        // Joomla returns each category's nested-set position: `lft` gives the
        // tree order and `level` its depth. We don't know (and must not assume)
        // the hidden ROOT node's id, so we never look at parent_id — sorting by
        // `lft` and indenting by `level` (relative to the shallowest category in
        // the list) reproduces the tree regardless of what the root id is.
        const ordered = categories.slice().sort((a, b) => (Number(a.lft) || 0) - (Number(b.lft) || 0));
        const minLevel = Math.min(...ordered.map(c => Number(c.level) || 0));
        ordered.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.id;
            const depth = (Number(cat.level) || 0) - minLevel;
            opt.textContent = ' '.repeat(depth * 4) + cat.title;
            if (cat.id == selectedCatid) opt.selected = true;
            sel.appendChild(opt);
        });
    } else {
        categories.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = cat.title;
            if (cat.id == selectedCatid) opt.selected = true;
            sel.appendChild(opt);
        });
    }
    return sel;
}

function buildAccessSelect(levels, selectedAccess) {
    const sel = document.createElement('select');
    sel.id = 'editor-access';
    sel.className = 'form-control';
    const defaultLevels = levels.length ? levels : [{ id: 1, title: 'Public' }];
    defaultLevels.forEach(level => {
        const opt = document.createElement('option');
        opt.value = level.id;
        opt.textContent = level.title;
        if (level.id == (selectedAccess || 1)) opt.selected = true;
        sel.appendChild(opt);
    });
    return sel;
}

function buildLanguageSelect(contentLanguages, selectedLang) {
    const sel = document.createElement('select');
    sel.id = 'editor-language';
    sel.className = 'form-control';

    // "All" (*) is always available, exactly as in Joomla's article form.
    const allOpt = document.createElement('option');
    allOpt.value = '*';
    allOpt.textContent = 'All (*)';
    if (!selectedLang || selectedLang === '*') allOpt.selected = true;
    sel.appendChild(allOpt);

    // The site's published content languages (#__languages: lang_code/title).
    (contentLanguages || [])
        .filter(lang => lang.published === undefined || Number(lang.published) === 1)
        .forEach(lang => {
            const code = lang.lang_code;
            if (!code) return;
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = `${lang.title || code} (${code})`;
            if (selectedLang === code) opt.selected = true;
            sel.appendChild(opt);
        });

    return sel;
}

function buildTagsInput(availableTags, selectedTags) {
    const wrapper = document.createElement('div');
    wrapper.className = 'tags-input-wrapper';
    wrapper.id = 'tags-input-wrapper';

    const tagSet = new Set(selectedTags || []);

    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.className = 'tags-input-field';
    textInput.placeholder = 'Add tag…';
    textInput.setAttribute('list', 'tags-datalist-id');

    const datalist = document.createElement('datalist');
    datalist.id = 'tags-datalist-id';
    availableTags.forEach(tagItem => {
        const opt = document.createElement('option');
        opt.value = tagItem.title;
        datalist.appendChild(opt);
    });

    function renderChips() {
        // Remove existing chips
        wrapper.querySelectorAll('.tag-chip').forEach(chip => chip.remove());
        // Re-add chips before the text input
        tagSet.forEach(tagVal => {
            const chip = el('span', 'tag-chip', tagVal);
            const removeBtn = el('button', 'tag-chip-remove');
            removeBtn.type = 'button';
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', () => {
                tagSet.delete(tagVal);
                renderChips();
            });
            chip.appendChild(removeBtn);
            wrapper.insertBefore(chip, textInput);
        });
    }

    function addTag(val) {
        const trimmed = val.trim().replace(/,$/, '');
        if (trimmed) {
            tagSet.add(trimmed);
            textInput.value = '';
            renderChips();
        }
    }

    textInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(textInput.value);
        } else if (e.key === 'Backspace' && !textInput.value) {
            const arr = [...tagSet];
            if (arr.length) { tagSet.delete(arr[arr.length - 1]); renderChips(); }
        }
    });

    textInput.addEventListener('change', () => {
        if (textInput.value.trim()) addTag(textInput.value);
    });

    wrapper.appendChild(textInput);
    wrapper.appendChild(datalist);
    renderChips();

    wrapper._getTags = () => [...tagSet];
    return wrapper;
}

function buildFieldInput(field, currentValue) {
    const name = `field-${field.name}`;
    const fp = field.fieldparams || {};

    switch (field.type) {
        case 'calendar': {
            const inp = document.createElement('input');
            inp.type = 'date';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue || '';
            return inp;
        }
        case 'checkboxes': {
            const wrap = el('div', 'checkbox-group');
            wrap.id = name;
            (fp.options || []).forEach(opt => {
                const lbl = el('label', 'form-check');
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = opt.value || opt.name;
                const vals = Array.isArray(currentValue) ? currentValue
                    : (currentValue ? [currentValue] : []);
                cb.checked = vals.includes(cb.value);
                lbl.appendChild(cb);
                lbl.appendChild(txt(opt.label || opt.name));
                wrap.appendChild(lbl);
            });
            return wrap;
        }
        case 'color': {
            const inp = document.createElement('input');
            inp.type = 'color';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue || '#000000';
            return inp;
        }
        case 'integer': {
            const inp = document.createElement('input');
            inp.type = 'number';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue !== undefined && currentValue !== null ? currentValue : '';
            return inp;
        }
        case 'list': {
            const sel = document.createElement('select');
            sel.className = 'form-control';
            sel.id = name;
            const emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '—';
            sel.appendChild(emptyOpt);
            (fp.options || []).forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value || opt.name;
                o.textContent = opt.label || opt.name;
                if (currentValue == o.value) o.selected = true;
                sel.appendChild(o);
            });
            return sel;
        }
        case 'radio': {
            const wrap = el('div', 'radio-group');
            wrap.id = name;
            (fp.options || []).forEach(opt => {
                const lbl = el('label', 'form-check');
                const rb = document.createElement('input');
                rb.type = 'radio';
                rb.name = name;
                rb.value = opt.value || opt.name;
                rb.checked = currentValue == rb.value;
                lbl.appendChild(rb);
                lbl.appendChild(txt(opt.label || opt.name));
                wrap.appendChild(lbl);
            });
            return wrap;
        }
        case 'text': {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue || '';
            return inp;
        }
        case 'textarea': {
            const ta = document.createElement('textarea');
            ta.className = 'form-control';
            ta.id = name;
            ta.rows = 3;
            ta.value = currentValue || '';
            return ta;
        }
        case 'url': {
            const inp = document.createElement('input');
            inp.type = 'url';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue || '';
            return inp;
        }
        default: {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control';
            inp.id = name;
            inp.value = currentValue || '';
            return inp;
        }
    }
}

// --------------------------------------------------------
//  TinyMCE init
// --------------------------------------------------------

async function initTinyMCE(draft) {
    if (State.tinyMCEEditor) {
        try { State.tinyMCEEditor.remove(); } catch {}
        State.tinyMCEEditor = null;
    }

    const wrapper = document.getElementById('tinymce-wrapper');
    clearNode(wrapper);
    const ta = document.createElement('textarea');
    ta.id = 'tinymce-editor';
    wrapper.appendChild(ta);

    if (typeof tinymce === 'undefined') {
        clearNode(wrapper);
        const errDiv = el('div', 'alert alert-error');
        errDiv.style.margin = '16px';
        errDiv.textContent = 'TinyMCE could not be loaded. Please ensure /js/tinymce/tinymce.min.js is available.';
        wrapper.appendChild(errDiv);
        return;
    }

    const cssOpts = [];
    if (State.editorCss) {
        try {
            const blob = new Blob([State.editorCss], { type: 'text/css' });
            cssOpts.push(URL.createObjectURL(blob));
        } catch {}
    }

    const editorSiteId = State.currentDraft ? State.currentDraft.siteId : State.currentSiteId;
    const site = State.sites.find(s => s.id === editorSiteId);
    const baseUrl = site ? (site.baseUrl || '').replace(/\/?$/, '/') : '';

    await tinymce.init({
        selector: '#tinymce-editor',
        height: '100%',
        resize: false,
        promotion: false,
        branding: false,
        skin: editorSkin(),
        // The editor UI always follows the app theme; the editing surface only
        // switches to the dark built-in CSS when the site supplies no editor.css.
        content_css: cssOpts.length ? cssOpts : editorContentCss(),
        document_base_url: baseUrl,
        // Keep the offline-image tag (data-grafida-media-id) in the editor output
        // so it survives save/getContent and reaches PublishService.
        extended_valid_elements: 'img[src|alt|title|class|style|width|height|loading|data-grafida-media-id]',
        menubar: 'file edit view insert format tools table',
        // The built-in "code" plugin opens raw HTML in a plain textarea; we
        // replace it with our own CodeMirror-backed "sourcecode" item (registered
        // in setup) for syntax highlighting, so the plugin is intentionally absent.
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount',
        ],
        // Tools menu: our "sourcecode" item replaces the dropped "code" item.
        menu: {
            tools: { title: 'Tools', items: 'sourcecode wordcount' },
        },
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
                 'alignleft aligncenter alignright alignjustify | ' +
                 'bullist numlist outdent indent | removeformat | ' +
                 'readmore | link image | sourcecode',
        // Wrap the toolbar onto multiple rows so no button (notably "readmore")
        // is ever hidden inside the overflow menu on a narrow window.
        toolbar_mode: 'wrap',
        // Make the read-more break clearly visible inside the editor: a thick
        // dashed coloured line that reads on both light and dark site CSS.
        // (::before/::after can't be used here — <hr> is a void element.)
        content_style:
            'hr.readmore {' +
            '  height: 0;' +
            '  border: 0;' +
            '  border-top: 3px dashed #ff7a45;' +
            '  margin: 1.6em 0;' +
            '  cursor: pointer;' +
            '}',
        setup: (editor) => {
            State.tinyMCEEditor = editor;

            editor.ui.registry.addIcon('readmore',
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" ' +
                'stroke="currentColor" stroke-width="1.6" stroke-linecap="round">' +
                '<path d="M4 4h16"/><path d="M4 8h10"/>' +
                '<path d="M4 12h16" stroke-dasharray="2 2.5"/>' +
                '<path d="M4 16h16"/><path d="M4 20h10"/></svg>');

            editor.ui.registry.addButton('readmore', {
                icon: 'readmore',
                text: t('GRAFIDA_BTN_INSERT_READMORE'),
                tooltip: t('GRAFIDA_BTN_INSERT_READMORE'),
                onAction: () => {
                    const existing = editor.dom.select('hr.readmore');
                    if (existing.length >= 1) {
                        editor.notificationManager.open({
                            text: 'A "Read more" separator already exists in this article.',
                            type: 'warning',
                            timeout: 3000,
                        });
                        return;
                    }
                    editor.insertContent('<hr class="readmore">');
                },
            });

            // Source-code editing: a CodeMirror modal with HTML syntax
            // highlighting, replacing the stock "code" plugin's plain textarea.
            // Exposed both on the toolbar and in the Tools menu.
            editor.ui.registry.addButton('sourcecode', {
                icon: 'sourcecode',
                tooltip: t('GRAFIDA_LBL_SOURCE_CODE'),
                onAction: () => openSourceCodeEditor(editor),
            });
            editor.ui.registry.addMenuItem('sourcecode', {
                icon: 'sourcecode',
                text: t('GRAFIDA_LBL_SOURCE_CODE'),
                onAction: () => openSourceCodeEditor(editor),
            });

            editor.on('init', () => {
                editor.setContent(draft.html || '');
            });

            // Tag offline images (data: URIs uploaded this session) with their
            // blob id so PublishService uploads them and rewrites the src. Runs
            // after the picker inserts, after paste/drag, and after setContent.
            editor.on('SetContent NodeChange', () => {
                editor.dom.select('img[src^="data:"]').forEach(img => {
                    if (img.getAttribute(GRAFIDA_MEDIA_ATTR)) return;
                    const id = State.inlineMediaByUri[img.getAttribute('src')];
                    if (id) editor.dom.setAttrib(img, GRAFIDA_MEDIA_ATTR, String(id));
                });
            });
        },
        // Pasted / dragged images: store offline and remember the blob id so the
        // inserted <img data:…> gets tagged for upload on publish.
        images_upload_handler: async (blobInfo) => {
            const filename = blobInfo.filename() || 'image.png';
            const mime = blobInfo.blob().type || 'image/png';
            const dataBase64 = blobInfo.base64();
            try {
                const result = await api.uploadMedia(editorSiteId, {
                    filename, mime, dataBase64, draftId: State.currentDraftId,
                });
                State.inlineMediaByUri[result.dataUri] = result.id;
                return result.dataUri;
            } catch (err) {
                throw new Error(err.message);
            }
        },
        // The Insert/Edit Image dialog's "browse" button opens the unified picker:
        // browse the site's Media Manager, or "Choose file…" to upload a local one.
        file_picker_callback: (callback, _value, meta) => {
            if (meta.filetype !== 'image') return;
            openMediaBrowser(editorSiteId, { allowUpload: true }).then(entry => {
                if (!entry || typeof entry.url !== 'string') return;
                if (entry.mediaId) {
                    State.inlineMediaByUri[entry.url] = entry.mediaId;
                }
                callback(entry.url, { alt: entry.name || '' });
            });
        },
    });
}

// --------------------------------------------------------
//  Article images (intro / full text)
// --------------------------------------------------------

// The Joomla `images` subfields, kept in a stable order so the working copy
// serialises deterministically for change detection. `*_alt_empty` marks a
// decorative image whose alt text is intentionally empty.
const IMAGE_KEYS = [
    'image_intro', 'image_intro_alt', 'image_intro_alt_empty', 'float_intro', 'image_intro_caption',
    'image_fulltext', 'image_fulltext_alt', 'image_fulltext_alt_empty', 'float_fulltext', 'image_fulltext_caption',
];

// An image picked from a local file is stored as this sentinel until publish,
// when its offline blob is uploaded and the value swapped for the public URL.
const MEDIA_REF_PREFIX = 'grafida-media://';

// Attribute tagging an inline (in-article) offline image with its blob id; must
// match InlineMedia::ATTRIBUTE on the PHP side. PublishService uploads these.
const GRAFIDA_MEDIA_ATTR = 'data-grafida-media-id';

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp'];

/** Coerce a raw images object into all eight subfields, each a string. */
function normalizeImages(raw) {
    const src = raw && typeof raw === 'object' ? raw : {};
    const out = {};
    IMAGE_KEYS.forEach(k => { out[k] = typeof src[k] === 'string' ? src[k] : ''; });
    return out;
}

/** Snapshot the working image copy (kept current by the section's inputs). */
function collectImages() {
    return normalizeImages(State.editorImages);
}

/** Base URL (no trailing slash) of the site the open draft belongs to. */
function siteBaseUrl(siteId) {
    const site = State.sites.find(s => s.id === siteId);
    return site ? (site.baseUrl || '').replace(/\/+$/, '') : '';
}

/**
 * Resolve an image field value to a displayable URL, or null when there is
 * nothing to show yet. Offline blobs resolve from the preview cache; absolute
 * and data: URLs are used as-is; site-relative paths are made absolute.
 */
function imagePreviewUrl(value, siteId) {
    if (!value) return null;
    if (value.startsWith(MEDIA_REF_PREFIX)) return State.mediaPreviews[value] || null;
    const clean = value.split('#')[0];
    if (/^https?:\/\//i.test(clean) || clean.startsWith('data:')) return clean;
    return siteBaseUrl(siteId) + '/' + clean.replace(/^\/+/, '');
}

/** Turn an absolute media URL into a path relative to the site root if possible. */
function relativeImagePath(url, siteId) {
    const base = siteBaseUrl(siteId);
    return base && url.startsWith(base + '/') ? url.slice(base.length + 1) : url;
}

/** Whether a Media Manager entry looks like an image we can use. */
function isImageEntry(entry) {
    const mime = String(entry.mime_type || entry.mimeType || '').toLowerCase();
    if (mime.startsWith('image/')) return true;
    const ext = String(entry.extension || '').toLowerCase();
    return IMAGE_EXTENSIONS.includes(ext);
}

/** The Media Manager path one level up from `path` (or root). */
function parentMediaPath(path) {
    const idx = path.lastIndexOf('/');
    if (idx <= 0) return '';
    const parent = path.slice(0, idx);
    return parent.endsWith(':') ? parent + '/' : parent;
}

/** Build the editor's Images section (intro + full-text blocks). */
function renderImagesSection(siteId) {
    const sec = el('div', 'images-section');
    sec.id = 'editor-images-section';
    sec.appendChild(el('div', 'section-title', t('GRAFIDA_LBL_IMAGES')));
    sec.appendChild(buildImageBlock('intro', siteId));
    sec.appendChild(buildImageBlock('fulltext', siteId));
    return sec;
}

/** Replace the Images section in place (after a pick / browse / clear). */
function rerenderImagesSection(siteId) {
    const old = document.getElementById('editor-images-section');
    if (old) old.replaceWith(renderImagesSection(siteId));
}

/** Set the intro/full-text image value and re-render the section. */
function setImageValue(kind, value, siteId) {
    State.editorImages['image_' + kind] = value;
    rerenderImagesSection(siteId);
}

function buildImageBlock(kind, siteId) {
    const imgKey = 'image_' + kind;
    const floatKey = 'float_' + kind;
    const altKey = 'image_' + kind + '_alt';
    const capKey = 'image_' + kind + '_caption';
    const labelKey = kind === 'intro' ? 'GRAFIDA_LBL_INTRO_IMAGE' : 'GRAFIDA_LBL_FULLTEXT_IMAGE';
    const value = State.editorImages[imgKey] || '';

    const block = el('div', 'image-block');
    block.appendChild(el('div', 'image-block-label', t(labelKey)));

    // Preview thumbnail with a placeholder fallback. showPreview() toggles
    // between the two so typing a URL updates the preview without a re-render.
    const preview = el('div', 'image-preview');
    const img = document.createElement('img');
    img.alt = '';
    const empty = el('span', 'image-preview-empty', t('GRAFIDA_MSG_NO_IMAGE'));
    const showPreview = (src) => {
        if (src) {
            img.src = src;
            img.style.display = '';
            empty.style.display = 'none';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
            empty.style.display = '';
        }
    };
    preview.appendChild(img);
    preview.appendChild(empty);

    const url = imagePreviewUrl(value, siteId);
    if (url) {
        showPreview(url);
    } else if (value && value.startsWith(MEDIA_REF_PREFIX)) {
        // Offline blob whose preview is not cached yet — fetch then fill in.
        showPreview(null);
        const id = parseInt(value.slice(MEDIA_REF_PREFIX.length), 10);
        api.getMediaBlob(id)
            .then(r => { State.mediaPreviews[value] = r.dataUri; showPreview(r.dataUri); })
            .catch(() => {});
    } else {
        showPreview(null);
    }
    block.appendChild(preview);

    // Action buttons: pick a local file, browse the site media, or clear.
    const actions = el('div', 'image-actions');
    const chooseBtn = iconBtn('upload', t('GRAFIDA_BTN_CHOOSE_FILE'), 'btn', 'btn-sm', 'btn-secondary');
    chooseBtn.addEventListener('click', () => chooseImageFile(kind, siteId));
    const browseBtn = iconBtn('folder-open', t('GRAFIDA_BTN_BROWSE_MEDIA'), 'btn', 'btn-sm', 'btn-secondary');
    browseBtn.addEventListener('click', () => browseImageMedia(kind, siteId));
    actions.appendChild(chooseBtn);
    actions.appendChild(browseBtn);
    if (value) {
        const clearBtn = iconBtn('xmark', t('GRAFIDA_BTN_CLEAR_IMAGE'), 'btn', 'btn-sm', 'btn-secondary');
        clearBtn.addEventListener('click', () => setImageValue(kind, '', siteId));
        actions.appendChild(clearBtn);
    }
    block.appendChild(actions);

    // Editable URL / path (a pasted image address, or a browsed media path). An
    // offline blob shows blank here; typing replaces it with the typed address.
    const urlInput = document.createElement('input');
    urlInput.type = 'text';
    urlInput.className = 'form-control';
    urlInput.value = value.startsWith(MEDIA_REF_PREFIX) ? '' : value;
    urlInput.addEventListener('input', () => {
        const v = urlInput.value.trim();
        State.editorImages[imgKey] = v;
        showPreview(imagePreviewUrl(v, siteId));
    });
    block.appendChild(formGroup(t('GRAFIDA_LBL_IMAGE_URL'), urlInput));

    // Alt text, the "decorative" toggle (Joomla's image_*_alt_empty), caption and
    // image CSS class — all write straight back to the working copy. (Joomla's
    // float_intro/float_fulltext is a free-text CSS class field.)
    const altInput = boundImageInput(altKey);
    const altEmptyKey = 'image_' + kind + '_alt_empty';
    altInput.disabled = State.editorImages[altEmptyKey] === '1';
    block.appendChild(formGroup(t('GRAFIDA_LBL_IMAGE_ALT'), altInput));
    block.appendChild(buildDecorativeToggle(altEmptyKey, altInput));
    block.appendChild(formGroup(t('GRAFIDA_LBL_IMAGE_CAPTION'), boundImageInput(capKey)));
    block.appendChild(formGroup(t('GRAFIDA_LBL_IMAGE_CLASS'), boundImageInput(floatKey)));

    return block;
}

/**
 * A checkbox for Joomla's image_*_alt_empty: when ticked the image is decorative
 * and its alt is intentionally empty, so the alt input is disabled.
 */
function buildDecorativeToggle(key, altInput) {
    const wrap = el('label', 'image-decorative');
    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.checked = State.editorImages[key] === '1';
    cb.addEventListener('change', () => {
        State.editorImages[key] = cb.checked ? '1' : '';
        altInput.disabled = cb.checked;
    });
    wrap.appendChild(cb);
    wrap.appendChild(el('span', null, t('GRAFIDA_LBL_IMAGE_DECORATIVE')));
    return wrap;
}

/** A text input bound to one image subfield in the working copy. */
function boundImageInput(key) {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.value = State.editorImages[key] || '';
    input.addEventListener('input', () => { State.editorImages[key] = input.value; });
    return input;
}

/**
 * Prompt for a local image file, store it as an offline blob, and call back with
 * `{url: <dataUri>, name, mediaId}` (or null on cancel/failure). Shared by the
 * intro/full-text picker and the in-editor image picker.
 */
function uploadLocalImage(siteId, cb) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = () => {
        const file = input.files[0];
        if (!file) { cb(null); return; }
        const reader = new FileReader();
        reader.onload = async (e) => {
            const dataBase64 = String(e.target.result).split(',')[1];
            try {
                const result = await api.uploadMedia(siteId, {
                    filename: file.name, mime: file.type || 'image/png',
                    dataBase64, draftId: State.currentDraftId,
                });
                cb({ url: result.dataUri, name: file.name, mediaId: result.id });
            } catch (err) {
                showToast(err.message, 'error');
                cb(null);
            }
        };
        reader.readAsDataURL(file);
    };
    input.click();
}

/** Pick a local image file, store it offline, and set it as the kind's image. */
function chooseImageFile(kind, siteId) {
    uploadLocalImage(siteId, (entry) => {
        if (!entry) return;
        const ref = MEDIA_REF_PREFIX + entry.mediaId;
        State.mediaPreviews[ref] = entry.url;
        setImageValue(kind, ref, siteId);
    });
}

/** Browse the site's Media Manager and adopt the chosen file as the image. */
async function browseImageMedia(kind, siteId) {
    const file = await openMediaBrowser(siteId);
    const url = file && typeof file.url === 'string' ? file.url : '';
    if (url) setImageValue(kind, relativeImagePath(url, siteId), siteId);
}

/**
 * Open the article HTML in a CodeMirror source-code editor (a modal), replacing
 * TinyMCE's stock "code" plugin so raw HTML gets syntax highlighting, line
 * numbers and bracket/tag matching. On Save the edited source is written back
 * into TinyMCE as a single undo step; Cancel (or the backdrop / Escape) discards.
 */
function openSourceCodeEditor(editor) {
    const host = el('div', 'cm-source-host');

    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    const saveBtn = iconBtn('check', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
    cancelBtn.addEventListener('click', closeModal);
    saveBtn.addEventListener('click', () => {
        editor.focus();
        editor.undoManager.transact(() => {
            editor.setContent(cm.getValue(), { source_view: true });
        });
        editor.selection.setCursorLocation();
        editor.nodeChanged();
        closeModal();
    });

    showModal(t('GRAFIDA_LBL_SOURCE_CODE'), host, [cancelBtn, saveBtn]);

    const cm = CodeMirror(host, {
        value: editor.getContent({ source_view: true }),
        mode: 'htmlmixed',
        theme: State.resolvedTheme === 'dark' ? 'material-darker' : 'default',
        lineNumbers: true,
        lineWrapping: true,
        autoCloseTags: true,
        matchBrackets: true,
        indentUnit: 2,
        tabSize: 2,
    });
    // CodeMirror mis-measures while the modal is laid out; refresh once visible.
    setTimeout(() => { cm.refresh(); cm.focus(); }, 0);
}

/**
 * Modal browser over the site's Media Manager. Resolves with the chosen file
 * entry, or null if the user cancels / closes the dialog.
 *
 * With `opts.allowUpload`, a "Choose file…" button uploads a local image as an
 * offline blob and resolves with a synthetic entry `{url: <dataUri>, name,
 * mediaId}` — the caller tags it so it is uploaded to the site on publish.
 */
function openMediaBrowser(siteId, opts = {}) {
    return new Promise(resolve => {
        const crumb = el('div', 'media-browser-path');
        const grid = el('div', 'media-browser-grid');
        const container = el('div', 'media-browser', crumb, grid);

        let settled = false;
        const escHandler = (e) => { if (e.key === 'Escape') finish(null); };
        const finish = (val) => {
            if (settled) return;
            settled = true;
            document.removeEventListener('keydown', escHandler, true);
            closeModal();
            resolve(val);
        };

        async function load(path) {
            clearNode(grid);
            grid.appendChild(el('div', 'media-browser-loading', '…'));
            let data;
            try {
                data = await api.browseMedia(siteId, path);
            } catch (err) {
                clearNode(grid);
                grid.appendChild(el('div', 'media-browser-error', err.message));
                return;
            }

            clearNode(crumb);
            if (path) {
                const up = iconBtn('arrow-up', t('GRAFIDA_BTN_MEDIA_UP'), 'btn', 'btn-sm', 'btn-secondary');
                up.addEventListener('click', () => load(parentMediaPath(path)));
                crumb.appendChild(up);
            }
            crumb.appendChild(el('span', 'media-browser-current', path || '/'));

            clearNode(grid);
            const entries = Array.isArray(data.entries) ? data.entries : [];
            const dirs = entries.filter(en => en.type === 'dir');
            const files = entries.filter(en => en.type === 'file' && isImageEntry(en));

            if (dirs.length === 0 && files.length === 0) {
                grid.appendChild(el('div', 'media-browser-empty', t('GRAFIDA_MSG_MEDIA_EMPTY')));
                return;
            }

            dirs.forEach(d => {
                const item = el('button', 'media-item media-item-dir', icon('folder'), el('span', 'media-item-name', d.name || ''));
                item.type = 'button';
                item.addEventListener('click', () => load(typeof d.path === 'string' ? d.path : ''));
                grid.appendChild(item);
            });
            files.forEach(f => {
                const item = el('button', 'media-item media-item-file');
                item.type = 'button';
                if (typeof f.url === 'string') {
                    const im = document.createElement('img');
                    im.src = f.url;
                    im.alt = '';
                    item.appendChild(im);
                }
                item.appendChild(el('span', 'media-item-name', f.name || ''));
                item.addEventListener('click', () => finish(f));
                grid.appendChild(item);
            });
        }

        const footer = [];
        if (opts.allowUpload) {
            const uploadBtn = iconBtn('upload', t('GRAFIDA_BTN_CHOOSE_FILE'), 'btn', 'btn-secondary');
            uploadBtn.addEventListener('click', () => {
                uploadLocalImage(siteId, (entry) => { if (entry) finish(entry); });
            });
            footer.push(uploadBtn);
        }
        const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
        cancelBtn.addEventListener('click', () => finish(null));
        footer.push(cancelBtn);

        document.addEventListener('keydown', escHandler, true);
        showModal(t('GRAFIDA_LBL_MEDIA_BROWSER'), container, footer);
        const overlay = document.getElementById('modal-overlay');
        overlay.onclick = (e) => { if (e.target === overlay) finish(null); };

        load('');
    });
}

// --------------------------------------------------------
//  Save draft
// --------------------------------------------------------

/**
 * Read the current editor + sidebar state into a plain draft body object.
 * Used both for saving and for change detection.
 */
function collectDraftFormData() {
    const editor = State.tinyMCEEditor;
    const refs = State.references || { fields: { supported: [] } };

    const fields = {};
    (refs.fields.supported || []).forEach(field => {
        const name = `field-${field.name}`;
        const fieldEl = document.getElementById(name);
        if (!fieldEl) return;
        if (field.type === 'checkboxes') {
            fields[field.name] = [...fieldEl.querySelectorAll('input[type="checkbox"]:checked')].map(cb => cb.value);
        } else if (field.type === 'radio') {
            const checked = fieldEl.querySelector('input[type="radio"]:checked');
            fields[field.name] = checked ? checked.value : '';
        } else {
            fields[field.name] = fieldEl.value;
        }
    });

    const tagsWrapper = document.getElementById('tags-input-wrapper');
    const tags = tagsWrapper && tagsWrapper._getTags ? tagsWrapper._getTags() : [];

    const catEl = document.getElementById('editor-catid');
    const accessEl = document.getElementById('editor-access');
    const langEl = document.getElementById('editor-language');
    const stateEl = document.getElementById('editor-state');
    const metadescEl = document.getElementById('editor-metadesc');
    const metakeyEl = document.getElementById('editor-metakey');
    const titleInputEl = document.getElementById('editor-title-input');

    return {
        title: titleInputEl ? titleInputEl.value.trim() : '',
        catid: catEl && catEl.value ? parseInt(catEl.value, 10) : null,
        access: accessEl ? parseInt(accessEl.value, 10) : 1,
        language: langEl ? langEl.value : '*',
        state: stateEl ? parseInt(stateEl.value, 10) : 1,
        html: editor ? editor.getContent() : '',
        fields,
        tags,
        images: collectImages(),
        metadesc: metadescEl ? metadescEl.value : '',
        metakey: metakeyEl ? metakeyEl.value : '',
    };
}

/** True when the editor form differs from the last saved/loaded snapshot. */
function isEditorDirty() {
    if (State.editorForceDirty) return true;
    if (State.editorBaseline === null) return false;
    return JSON.stringify(collectDraftFormData()) !== State.editorBaseline;
}

/**
 * Build the full save payload: the editable form plus the working draft's
 * site/remote link and the fields not exposed in the form (alias). The article
 * images are part of collectDraftFormData() (the editor's Images section).
 */
function buildDraftSaveBody() {
    const draft = State.currentDraft || {};
    return {
        ...collectDraftFormData(),
        siteId: draft.siteId,
        remoteId: draft.remoteId ?? null,
        alias: draft.alias || '',
    };
}

async function saveDraft() {
    if (!State.currentDraft) return;
    if (!State.tinyMCEEditor) return;

    const body = buildDraftSaveBody();

    try {
        // First save of a new or remote-imported article inserts the row; later
        // saves update it. This is why nothing is persisted until the user saves.
        const saved = State.currentDraftId == null
            ? await api.createDraft(body.siteId, body)
            : await api.saveDraft(State.currentDraftId, body);

        State.currentDraft = saved;
        State.currentDraftId = saved.id;
        State.editorForceDirty = false;
        State.editorSavedSiteId = saved.siteId;
        State.editorBaseline = JSON.stringify(collectDraftFormData());
        showToast(t('GRAFIDA_MSG_SAVED'), 'success');
        return saved;
    } catch (err) {
        showToast(err.message, 'error');
        throw err;
    }
}

// --------------------------------------------------------
//  Leaving the editor (Back button)
// --------------------------------------------------------

/** Tear down the editor state and return to the articles list. */
function leaveEditor() {
    // A draft may have been re-pointed at (and saved to) another site while
    // editing; surface that site in the list so the saved draft stays visible.
    const savedSiteId = State.editorSavedSiteId;
    if (savedSiteId && savedSiteId !== State.currentSiteId) {
        selectSite(savedSiteId);
    }

    State.currentDraft = null;
    State.currentDraftId = null;
    State.editorBaseline = null;
    State.editorForceDirty = false;
    State.editorSavedSiteId = null;
    showScreen('articles');
    loadArticlesScreen();
}

/**
 * Handle the editor Back button: leave straight away when nothing changed (an
 * untouched new or remote draft was never persisted), or prompt to save / keep
 * editing / discard when there are unsaved changes.
 */
async function handleEditorBack() {
    if (isEditorDirty()) {
        showUnsavedChangesDialog();
        return;
    }
    leaveEditor();
}

function showUnsavedChangesDialog() {
    const msgP = el('p', null, t('GRAFIDA_MSG_UNSAVED_CHANGES'));

    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE_AND_BACK'), 'btn', 'btn-success');
    saveBtn.addEventListener('click', async () => {
        try {
            await saveDraft();
        } catch {
            return; // Save failed — keep the editor open so nothing is lost.
        }
        closeModal();
        leaveEditor();
    });

    const keepBtn = iconBtn('pen', t('GRAFIDA_BTN_KEEP_EDITING'), 'btn', 'btn-info');
    keepBtn.addEventListener('click', closeModal);

    const discardBtn = iconBtn('trash', t('GRAFIDA_BTN_DISCARD_CHANGES'), 'btn', 'btn-danger');
    discardBtn.addEventListener('click', () => {
        closeModal();
        leaveEditor();
    });

    showModal(t('GRAFIDA_MSG_UNSAVED_TITLE'), [msgP], [saveBtn, keepBtn, discardBtn]);
    saveBtn.focus();
}

// --------------------------------------------------------
//  Publish draft
// --------------------------------------------------------

async function publishDraft() {
    let saved;
    try { saved = await saveDraft(); } catch { return; }
    if (!saved || State.currentDraftId == null) return;

    try {
        const result = await api.publishDraft(State.currentDraftId);
        // Publishing a new article assigns it a remote ID; keep the open draft in
        // sync so a subsequent publish updates that article instead of recreating.
        if (result && result.remoteId && State.currentDraft) {
            State.currentDraft.remoteId = result.remoteId;
        }
        showToast(t('GRAFIDA_MSG_PUBLISH_OK'), 'success');
        showPostPublishDialog();
    } catch (err) {
        if (err.code === 'publish_blocked') {
            const bodyNodes = [];

            const msgP = el('p', null, t('GRAFIDA_MSG_PUBLISH_BLOCKED'));
            bodyNodes.push(msgP);

            if (err.fieldLabels && err.fieldLabels.length) {
                const list = el('ul', null);
                err.fieldLabels.forEach(label => {
                    list.appendChild(el('li', null, String(label)));
                });
                bodyNodes.push(list);
            }

            const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
            cancelBtn.addEventListener('click', closeModal);

            const copyBtn = iconBtn('copy', t('GRAFIDA_BTN_COPY_HTML'), 'btn', 'btn-secondary');
            copyBtn.id = 'btn-copy-html';
            copyBtn.addEventListener('click', () => {
                const html = State.tinyMCEEditor ? State.tinyMCEEditor.getContent() : '';
                navigator.clipboard.writeText(html).then(() => {
                    showToast('HTML copied to clipboard.', 'success');
                    closeModal();
                }).catch(() => {
                    showToast('Could not access clipboard.', 'error');
                });
            });

            showModal('Publish blocked', bodyNodes, [cancelBtn, copyBtn]);
        } else {
            showToast(err.message, 'error');
        }
    }
}

/**
 * After a successful publish, ask what to do with the local draft. Deleting it
 * (the default action) removes the draft and returns to the articles list — the
 * published article remains available in the remote list. Keeping it leaves the
 * editor open so the draft can be edited and re-published later.
 */
function showPostPublishDialog() {
    if (State.currentDraftId == null) return;
    const draftId = State.currentDraftId;

    const msgP = el('p', null, t('GRAFIDA_MSG_POST_PUBLISH_PROMPT'));

    const deleteBtn = iconBtn('trash', t('GRAFIDA_BTN_DELETE_DRAFT'), 'btn', 'btn-danger');
    deleteBtn.addEventListener('click', async () => {
        try {
            await api.deleteDraft(draftId);
        } catch (err) {
            showToast(err.message, 'error');
            return; // Deletion failed — keep the editor open so nothing is lost.
        }
        State.drafts = State.drafts.filter(d => d.id !== draftId);
        closeModal();
        showToast(t('GRAFIDA_MSG_DRAFT_DELETED'), 'success');
        leaveEditor();
    });

    const keepBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_KEEP_DRAFT'), 'btn', 'btn-info');
    keepBtn.addEventListener('click', closeModal);

    showModal(t('GRAFIDA_MSG_POST_PUBLISH_TITLE'), [msgP], [deleteBtn, keepBtn]);
    deleteBtn.focus();
}

// --------------------------------------------------------
//  Import Markdown
// --------------------------------------------------------

function importMarkdown() {
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = '.md,.markdown,text/markdown,text/plain';
    fileInput.onchange = async () => {
        const file = fileInput.files[0];
        if (!file) return;
        const text = await file.text();
        try {
            const result = await api.convertMarkdown(text);
            if (State.tinyMCEEditor) {
                State.tinyMCEEditor.setContent(result.html);
                showToast('Markdown imported.', 'success');
            }
        } catch (err) {
            showToast(err.message, 'error');
        }
    };
    fileInput.click();
}

// ============================================================
//  SETTINGS SCREEN
// ============================================================

function renderSettingsScreen() {
    const sel = document.getElementById('settings-language-select');
    if (!sel) return;
    clearNode(sel);

    const autoOpt = document.createElement('option');
    autoOpt.value = 'auto';
    autoOpt.textContent = t('GRAFIDA_OPT_AUTO');
    if (State.languageOverride === 'auto') autoOpt.selected = true;
    sel.appendChild(autoOpt);

    Object.entries(State.availableLanguages || {}).forEach(([tag, endonym]) => {
        const opt = document.createElement('option');
        opt.value = tag;
        opt.textContent = `${endonym} (${tag})`;
        if (State.languageOverride === tag) opt.selected = true;
        sel.appendChild(opt);
    });

    renderDisplayModeSetting();
    renderStorageSettings();
}

/** Populates the display-mode selector, reflecting the stored preference. */
function renderDisplayModeSetting() {
    const sel = document.getElementById('settings-display-mode-select');
    if (!sel) return;
    clearNode(sel);

    const modes = [
        ['auto', 'GRAFIDA_OPT_DISPLAY_AUTO'],
        ['light', 'GRAFIDA_OPT_DISPLAY_LIGHT'],
        ['dark', 'GRAFIDA_OPT_DISPLAY_DARK'],
    ];

    modes.forEach(([mode, key]) => {
        const opt = document.createElement('option');
        opt.value = mode;
        opt.textContent = t(key);
        if ((State.displayMode || 'auto') === mode) opt.selected = true;
        sel.appendChild(opt);
    });
}

/**
 * Builds the local-storage card actions (open folder / reset) and fetches the
 * SQLite database path to display. Rebuilt on every settings render so button
 * labels track the current interface language.
 */
function renderStorageSettings() {
    const openBox = document.getElementById('settings-storage-actions');
    if (openBox) {
        clearNode(openBox);
        const openBtn = iconBtn('folder-open', t('GRAFIDA_BTN_OPEN_FOLDER'), 'btn', 'btn-secondary');
        openBtn.addEventListener('click', openStorageFolder);
        openBox.appendChild(openBtn);
    }

    const resetBox = document.getElementById('settings-reset-actions');
    if (resetBox) {
        clearNode(resetBox);
        const resetBtn = iconBtn('trash', t('GRAFIDA_BTN_RESET_STORAGE'), 'btn', 'btn-danger');
        resetBtn.addEventListener('click', resetStorage);
        resetBox.appendChild(resetBtn);
    }

    const pathEl = document.getElementById('settings-db-path');
    if (pathEl) {
        api.getStorageInfo()
            .then(info => { pathEl.textContent = info.path; })
            .catch(() => { pathEl.textContent = '—'; });
    }
}

async function openStorageFolder() {
    try {
        await api.openStorageFolder();
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function resetStorage() {
    const ok = await confirmYesNo(
        t('GRAFIDA_LBL_RESET_STORAGE'),
        [el('p', null, t('GRAFIDA_MSG_RESET_STORAGE_CONFIRM'))]
    );
    if (!ok) return;

    try {
        await api.resetStorage();
        showToast(t('GRAFIDA_MSG_RESET_STORAGE_DONE'), 'success');
        await bootstrap();
        showScreen('settings');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function applyLanguageChange(tag) {
    try {
        const result = await api.setLanguage(tag);
        State.language = result.language;
        State.strings = result.strings;
        State.languageOverride = tag;
        applyStrings();
        showToast(t('GRAFIDA_MSG_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function applyStrings() {
    document.querySelectorAll('[data-i18n]').forEach(node => {
        node.textContent = t(node.dataset.i18n);
    });
    renderSiteSelector();
    renderSettingsScreen();
}

// ============================================================
//  About dialog
// ============================================================

/** Set the version label shown at the bottom of the sidebar. */
function renderSidebarFooter() {
    const label = document.getElementById('sidebar-version');
    if (!label) return;
    const version = State.app.version || '';
    label.textContent = version ? t('GRAFIDA_LBL_VERSION') + ' ' + version : t('GRAFIDA_BTN_ABOUT');
}

/** Open the licence text in the user's default web browser. */
async function openLicenseUrl() {
    const url = State.app.licenseUrl;
    if (!url) return;
    try {
        await api.openUrl(url);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Show the About dialog with app identity, version, licence and disclaimers. */
function showAboutDialog() {
    const app = State.app;

    const nameEl = el('p', 'about-name', app.name || 'Grafida');

    const versionEl = el('p', 'about-version',
        ...formatNodes(t('GRAFIDA_LBL_VERSION') + ' %s', app.version || ''));

    const copyrightEl = app.copyright ? el('p', 'about-copyright', app.copyright) : null;

    const licenseLine = el('p', 'about-license',
        ...formatNodes(t('GRAFIDA_LBL_LICENSE') + ': %s', app.license || ''));

    const licenseLink = iconBtn('up-right-from-square', t('GRAFIDA_ABOUT_VIEW_LICENSE'), 'btn', 'btn-link');
    licenseLink.addEventListener('click', openLicenseUrl);

    // Joomla! trademark disclaimer — displayed verbatim, never translated.
    const disclaimerEl = app.disclaimer ? el('p', 'about-disclaimer', app.disclaimer) : null;

    const closeBtn = iconBtn('xmark', t('GRAFIDA_BTN_CLOSE'), 'btn', 'btn-secondary');
    closeBtn.addEventListener('click', closeModal);

    showModal(
        t('GRAFIDA_LBL_ABOUT'),
        [nameEl, versionEl, copyrightEl, licenseLine, licenseLink, disclaimerEl],
        [closeBtn]
    );
}

// ============================================================
//  Display mode / theme
// ============================================================

/** The OS-level media query used to resolve the "auto" display mode. */
const darkModeQuery = window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

function systemPrefersDark() {
    // The back-end probes the OS appearance directly because Boson's webview
    // does not report `prefers-color-scheme` reliably; trust it when known and
    // only fall back to the media query when the OS preference is undetectable.
    if (State.systemPrefersDark !== null && State.systemPrefersDark !== undefined) {
        return State.systemPrefersDark;
    }
    return darkModeQuery ? darkModeQuery.matches : true;
}

/** Resolves State.displayMode ('auto'|'light'|'dark') to a concrete theme. */
function resolveTheme() {
    const mode = State.displayMode || 'auto';
    if (mode === 'light' || mode === 'dark') return mode;
    return systemPrefersDark() ? 'dark' : 'light';
}

/**
 * Applies the resolved theme to the document (CSS variables key off the
 * `data-theme` attribute on <html>). When `reinitEditor` is true and the
 * TinyMCE editor is open, it is re-created so its skin and built-in content
 * CSS follow the new theme — unless the site supplies its own editor.css,
 * in which case the editor content keeps the site's styling.
 */
function applyTheme(reinitEditor = false) {
    const resolved = resolveTheme();
    const changed = resolved !== State.resolvedTheme;
    State.resolvedTheme = resolved;
    document.documentElement.setAttribute('data-theme', resolved);

    if (reinitEditor && changed && State.tinyMCEEditor && State.currentDraft) {
        const html = State.tinyMCEEditor.getContent();
        initTinyMCE({ ...State.currentDraft, html });
    }
}

/** Returns the TinyMCE UI skin matching the current resolved theme. */
function editorSkin() {
    return State.resolvedTheme === 'dark' ? 'oxide-dark' : 'oxide';
}

/** Returns the built-in TinyMCE content CSS matching the resolved theme. */
function editorContentCss() {
    return State.resolvedTheme === 'dark' ? 'dark' : 'default';
}

async function applyDisplayModeChange(mode) {
    try {
        const result = await api.setDisplayMode(mode);
        State.displayMode = result.displayMode || mode;
        applyTheme(true);
        showToast(t('GRAFIDA_MSG_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

// Keep "auto" mode in step with the OS as it changes at runtime.
const onSystemThemeChange = () => {
    if ((State.displayMode || 'auto') === 'auto') applyTheme(true);
};
if (darkModeQuery) {
    if (darkModeQuery.addEventListener) {
        darkModeQuery.addEventListener('change', onSystemThemeChange);
    } else if (darkModeQuery.addListener) {
        darkModeQuery.addListener(onSystemThemeChange);
    }
}

// Boson's webview doesn't fire the media-query change reliably, so when the
// window regains focus we re-probe the OS appearance via the back-end and
// re-apply the theme if "auto" is in effect and the preference flipped.
window.addEventListener('focus', async () => {
    if ((State.displayMode || 'auto') !== 'auto') return;
    try {
        const { systemPrefersDark } = await api.systemTheme();
        const value = typeof systemPrefersDark === 'boolean' ? systemPrefersDark : null;
        if (value !== State.systemPrefersDark) {
            State.systemPrefersDark = value;
            applyTheme(true);
        }
    } catch (err) {
        // Best-effort; keep the current theme on failure.
    }
});

// ============================================================
//  App bootstrap
// ============================================================

async function bootstrap() {
    document.getElementById('app').style.opacity = '0.5';

    try {
        const data = await api.bootstrap();
        State.strings = data.strings || {};
        State.language = data.language || 'en-GB';
        State.languageOverride = data.languageOverride || 'auto';
        State.availableLanguages = data.availableLanguages || {};
        State.displayMode = data.displayMode || 'auto';
        State.systemPrefersDark = typeof data.systemPrefersDark === 'boolean'
            ? data.systemPrefersDark
            : null;
        State.secureStore = data.secureStore !== false;
        State.supportedFieldTypes = data.supportedFieldTypes || [];
        State.app = data.app || {};
        State.sites = data.sites || [];
    } catch (err) {
        console.error('Bootstrap failed:', err);
    }

    document.getElementById('app').style.opacity = '1';
    applyTheme();
    applyStrings();
    renderSidebarFooter();
    // Capture the remembered site *before* renderSiteSelector() persists a fallback.
    const remembered = recallLastSite();

    renderSiteSelector();
    renderSitesScreen();
    renderSettingsScreen();

    // Default to the Articles page when we have a site and a remembered last active one.
    const hasRememberedSite = State.sites.length > 0
        && remembered && State.sites.some(s => s.id === remembered);
    if (hasRememberedSite) {
        showScreen('articles');
        loadArticlesScreen();
    } else {
        showScreen('sites');
    }
}

// ============================================================
//  Wire up DOM once loaded
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('nav#main-nav a[data-screen]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            if (link.classList.contains('disabled')) return;
            const screen = link.dataset.screen;
            showScreen(screen);
            if (screen === 'articles') loadArticlesScreen();
            if (screen === 'settings') renderSettingsScreen();
        });
    });

    const siteSel = document.getElementById('site-select');
    if (siteSel) {
        siteSel.addEventListener('change', () => {
            const val = siteSel.value;
            State.currentSiteId = val ? parseInt(val, 10) : null;
            rememberLastSite(State.currentSiteId);
            State.references = null;
            State.editorCss = null;
            renderSidebarFavicon();
            updateNewArticleButton();
            if (State.activeScreen === 'articles') loadArticlesScreen();
        });
    }

    const sidebarFooter = document.getElementById('sidebar-footer');
    if (sidebarFooter) sidebarFooter.addEventListener('click', showAboutDialog);

    const btnAddSite = document.getElementById('btn-add-site');
    if (btnAddSite) btnAddSite.addEventListener('click', openAddSiteModal);

    const btnNewArticle = document.getElementById('btn-new-article');
    if (btnNewArticle) btnNewArticle.addEventListener('click', openNewArticle);

    const btnSaveDraft = document.getElementById('btn-save-draft');
    if (btnSaveDraft) btnSaveDraft.addEventListener('click', saveDraft);

    const btnPublish = document.getElementById('btn-publish');
    if (btnPublish) btnPublish.addEventListener('click', publishDraft);

    const btnImportMd = document.getElementById('btn-import-md');
    if (btnImportMd) btnImportMd.addEventListener('click', importMarkdown);

    const btnBack = document.getElementById('btn-back-to-articles');
    if (btnBack) {
        btnBack.addEventListener('click', () => { handleEditorBack(); });
    }

    const langSel = document.getElementById('settings-language-select');
    if (langSel) {
        langSel.addEventListener('change', () => applyLanguageChange(langSel.value));
    }

    const displayModeSel = document.getElementById('settings-display-mode-select');
    if (displayModeSel) {
        displayModeSel.addEventListener('change', () => applyDisplayModeChange(displayModeSel.value));
    }

    const btnModalClose = document.getElementById('btn-modal-close');
    if (btnModalClose) btnModalClose.addEventListener('click', closeModal);

    bootstrap();
});
