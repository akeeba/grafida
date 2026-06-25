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
    secureStore: true,
    supportedFieldTypes: [],
    sites: [],
    currentSiteId: null,
    currentDraftId: null,
    drafts: [],
    remoteArticles: [],
    references: null,
    editorCss: null,
    tinyMCEEditor: null,
    activeScreen: 'sites',
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

/** Create a button with textContent and class names. */
function btn(labelKey, ...classes) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = classes.join(' ');
    b.textContent = t(labelKey);
    return b;
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
    getRemoteArticles: (siteId) => apiFetch('GET', `/api/sites/${siteId}/articles`),
    getDrafts: (siteId) => apiFetch('GET', `/api/sites/${siteId}/drafts`),
    createDraft: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/drafts`, body),
    getDraft: (id) => apiFetch('GET', `/api/drafts/${id}`),
    saveDraft: (id, body) => apiFetch('PUT', `/api/drafts/${id}`, body),
    deleteDraft: (id) => apiFetch('DELETE', `/api/drafts/${id}`),
    publishDraft: (id) => apiFetch('POST', `/api/drafts/${id}/publish`),
    uploadMedia: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media`, body),
    convertMarkdown: (markdown) => apiFetch('POST', '/api/markdown', { markdown }),
    setLanguage: (tag) => apiFetch('POST', '/api/settings/language', { tag }),
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

function renderSiteSelector() {
    const sel = document.getElementById('site-select');
    clearNode(sel);

    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = `— ${t('GRAFIDA_MSG_NO_SITES')} —`;
    sel.appendChild(defaultOpt);

    State.sites.forEach(site => {
        const opt = document.createElement('option');
        opt.value = site.id;
        opt.textContent = site.title;
        sel.appendChild(opt);
    });

    if (State.currentSiteId && State.sites.find(s => s.id === State.currentSiteId)) {
        sel.value = State.currentSiteId;
    } else if (State.sites.length === 1) {
        sel.value = State.sites[0].id;
        State.currentSiteId = State.sites[0].id;
    }
    updateNewArticleButton();
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

function buildSiteItem(site) {
    const info = el('div', 'site-item-info',
        el('div', 'site-item-title', site.title || ''),
        el('div', 'site-item-url', site.baseUrl || site.url || '')
    );

    const btnEdit = el('button', 'btn btn-sm btn-secondary');
    btnEdit.type = 'button';
    btnEdit.textContent = t('GRAFIDA_BTN_EDIT');
    btnEdit.addEventListener('click', () => openEditSiteModal(site.id));

    const btnDel = el('button', 'btn btn-sm btn-danger');
    btnDel.type = 'button';
    btnDel.textContent = t('GRAFIDA_BTN_DELETE');
    btnDel.addEventListener('click', () => confirmDeleteSite(site.id));

    const actions = el('div', 'site-item-actions', btnEdit, btnDel);
    return el('div', 'site-item', info, actions);
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
    const testBtn = el('button', 'btn btn-secondary');
    testBtn.type = 'button';
    testBtn.id = 'btn-test-connection';
    testBtn.textContent = t('GRAFIDA_BTN_TEST_CONNECTION');
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
    const cancelBtn = el('button', 'btn btn-secondary');
    cancelBtn.type = 'button';
    cancelBtn.textContent = t('GRAFIDA_BTN_CANCEL');
    cancelBtn.addEventListener('click', closeModal);

    const saveBtn = el('button', 'btn btn-primary');
    saveBtn.type = 'button';
    saveBtn.id = 'btn-save-site';
    saveBtn.textContent = t('GRAFIDA_BTN_SAVE');
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

        const declineBtn = el('button', 'btn btn-secondary');
        declineBtn.type = 'button';
        declineBtn.textContent = t('GRAFIDA_BTN_CANCEL');
        declineBtn.id = 'btn-insecure-decline';

        const acceptBtn = el('button', 'btn btn-primary');
        acceptBtn.type = 'button';
        acceptBtn.textContent = t('GRAFIDA_BTN_SAVE');
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
    const msgP = el('p', null,
        txt('Delete site '),
        siteNameStrong,
        txt('? This cannot be undone.')
    );

    const cancelBtn = el('button', 'btn btn-secondary');
    cancelBtn.type = 'button';
    cancelBtn.textContent = t('GRAFIDA_BTN_CANCEL');
    cancelBtn.addEventListener('click', closeModal);

    const delBtn = el('button', 'btn btn-danger');
    delBtn.type = 'button';
    delBtn.id = 'btn-confirm-delete';
    delBtn.textContent = t('GRAFIDA_BTN_DELETE');
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

async function loadArticlesScreen() {
    const container = document.getElementById('articles-container');

    if (!State.currentSiteId) {
        clearNode(container);
        container.appendChild(el('div', 'empty-state', el('p', null, 'Please select a site.')));
        return;
    }

    clearNode(container);
    const spinnerDiv = el('div', 'loading-row',
        el('div', 'spinner'),
        txt(' Loading…')
    );
    container.appendChild(spinnerDiv);

    try {
        const [drafts, remoteArticles] = await Promise.all([
            api.getDrafts(State.currentSiteId),
            api.getRemoteArticles(State.currentSiteId).catch(() => []),
        ]);
        State.drafts = drafts || [];
        State.remoteArticles = Array.isArray(remoteArticles) ? remoteArticles : [];
        renderArticlesList();
    } catch (err) {
        clearNode(container);
        const errDiv = el('div', 'alert alert-error', String(err.message));
        container.appendChild(errDiv);
    }
}

function renderArticlesList() {
    const container = document.getElementById('articles-container');
    clearNode(container);

    if (State.drafts.length > 0) {
        const section = el('div', 'article-list-section');
        const heading = el('h3', null, 'Local Drafts');
        section.appendChild(heading);
        State.drafts.forEach(draft => section.appendChild(buildArticleItem(draft, 'draft')));
        container.appendChild(section);
    }

    if (State.remoteArticles.length > 0) {
        const section = el('div', 'article-list-section');
        const heading = el('h3', null, 'Remote Articles');
        section.appendChild(heading);
        State.remoteArticles.forEach(article => section.appendChild(buildArticleItem(article, 'remote')));
        container.appendChild(section);
    }

    if (!State.drafts.length && !State.remoteArticles.length) {
        container.appendChild(el('div', 'empty-state', el('p', null, 'No articles found.')));
    }
}

function buildArticleItem(article, type) {
    const item = el('div', 'article-item');

    const titleDiv = el('div', 'article-item-title', article.title || '(Untitled)');
    const infoDiv = el('div', 'article-item-info', titleDiv);
    if (article.alias) {
        infoDiv.appendChild(el('div', 'article-item-meta', article.alias));
    }

    const badgeClass = type === 'draft' ? 'badge-draft' : 'badge-remote';
    const badgeText = type === 'draft' ? 'Draft' : 'Remote';
    const badge = el('span', `article-badge ${badgeClass}`, badgeText);

    item.appendChild(infoDiv);
    item.appendChild(badge);
    item.addEventListener('click', () => openEditorFor(article, type));
    return item;
}

async function openEditorFor(article, type) {
    let draft = null;

    if (type === 'draft') {
        draft = article;
    } else {
        draft = {
            siteId: State.currentSiteId,
            remoteId: article.id,
            title: article.title || '',
            alias: article.alias || '',
            catid: article.catid || null,
            access: article.access || 1,
            language: article.language || '*',
            state: article.state ?? 1,
            html: article.introtext || article.body || '',
            fields: {},
            tags: [],
            images: {},
            metadesc: article.metadesc || '',
            metakey: article.metakey || '',
        };
        try {
            const saved = await api.createDraft(State.currentSiteId, draft);
            draft = saved;
            State.drafts.push(draft);
        } catch (err) {
            showToast(err.message, 'error');
            return;
        }
    }

    State.currentDraftId = draft.id;
    await openEditorScreen(draft);
}

async function openNewArticle() {
    if (!State.currentSiteId) return;
    const draft = {
        siteId: State.currentSiteId,
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
    };
    try {
        const saved = await api.createDraft(State.currentSiteId, draft);
        State.currentDraftId = saved.id;
        await openEditorScreen(saved);
    } catch (err) {
        showToast(err.message, 'error');
    }
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
        promises.push(api.getReferences(State.currentSiteId).then(r => { State.references = r; }));
    }
    if (needCss) {
        promises.push(
            api.getEditorCss(State.currentSiteId)
                .then(r => { State.editorCss = r.css; })
                .catch(() => { State.editorCss = null; })
        );
    }

    if (promises.length > 0) {
        try { await Promise.all(promises); } catch {}
    }

    renderEditorSidebar(draft);
    await initTinyMCE(draft);
}

function renderEditorSidebar(draft) {
    const sidebar = document.getElementById('editor-sidebar-inner');
    clearNode(sidebar);

    const refs = State.references || {
        categories: [], tags: [], levels: [], languages: [],
        fields: { supported: [], unsupported: [] },
    };

    // Title in main area
    const titleInput = document.getElementById('editor-title-input');
    if (titleInput) titleInput.value = draft.title || '';

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
        appendCategoryOptions(sel, categories, 0, selectedCatid);
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

function appendCategoryOptions(sel, categories, parentId, selectedCatid) {
    categories
        .filter(c => (c.parent_id || 0) == parentId || (parentId === 0 && !c.parent_id))
        .sort((a, b) => (a.lft || 0) - (b.lft || 0))
        .forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.id;
            const depth = cat.level || 1;
            opt.textContent = ' '.repeat((depth - 1) * 4) + cat.title;
            if (cat.id == selectedCatid) opt.selected = true;
            sel.appendChild(opt);
            appendCategoryOptions(sel, categories, cat.id, selectedCatid);
        });
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

    const site = State.sites.find(s => s.id === State.currentSiteId);
    const baseUrl = site ? (site.baseUrl || '').replace(/\/?$/, '/') : '';

    await tinymce.init({
        selector: '#tinymce-editor',
        height: '100%',
        resize: false,
        promotion: false,
        branding: false,
        skin: 'oxide-dark',
        content_css: cssOpts.length ? cssOpts : 'dark',
        document_base_url: baseUrl,
        menubar: 'file edit view insert format tools table',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount',
        ],
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | ' +
                 'alignleft aligncenter alignright alignjustify | ' +
                 'bullist numlist outdent indent | removeformat | ' +
                 'link image | code | readmore',
        setup: (editor) => {
            State.tinyMCEEditor = editor;

            editor.ui.registry.addButton('readmore', {
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

            editor.on('init', () => {
                editor.setContent(draft.html || '');
            });
        },
        images_upload_handler: async (blobInfo) => {
            const filename = blobInfo.filename() || 'image.png';
            const mime = blobInfo.blob().type || 'image/png';
            const dataBase64 = blobInfo.base64();
            try {
                const result = await api.uploadMedia(State.currentSiteId, {
                    filename, mime, dataBase64, draftId: State.currentDraftId,
                });
                return result.dataUri;
            } catch (err) {
                throw new Error(err.message);
            }
        },
        file_picker_callback: (callback, _value, meta) => {
            if (meta.filetype !== 'image') return;
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.onchange = () => {
                const file = fileInput.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = async (e) => {
                    const dataUrl = e.target.result;
                    const dataBase64 = dataUrl.split(',')[1];
                    const mime = file.type || 'image/png';
                    try {
                        const result = await api.uploadMedia(State.currentSiteId, {
                            filename: file.name, mime, dataBase64,
                            draftId: State.currentDraftId,
                        });
                        callback(result.dataUri, { title: file.name, alt: file.name });
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                };
                reader.readAsDataURL(file);
            };
            fileInput.click();
        },
    });
}

// --------------------------------------------------------
//  Save draft
// --------------------------------------------------------

async function saveDraft() {
    if (!State.currentDraftId) return;
    const editor = State.tinyMCEEditor;
    if (!editor) return;

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

    const body = {
        title: titleInputEl ? titleInputEl.value.trim() : '',
        catid: catEl && catEl.value ? parseInt(catEl.value, 10) : null,
        access: accessEl ? parseInt(accessEl.value, 10) : 1,
        language: langEl ? langEl.value : '*',
        state: stateEl ? parseInt(stateEl.value, 10) : 1,
        html: editor.getContent(),
        fields,
        tags,
        metadesc: metadescEl ? metadescEl.value : '',
        metakey: metakeyEl ? metakeyEl.value : '',
    };

    try {
        const saved = await api.saveDraft(State.currentDraftId, body);
        showToast(t('GRAFIDA_MSG_SAVED'), 'success');
        return saved;
    } catch (err) {
        showToast(err.message, 'error');
        throw err;
    }
}

// --------------------------------------------------------
//  Publish draft
// --------------------------------------------------------

async function publishDraft() {
    try { await saveDraft(); } catch { return; }

    try {
        await api.publishDraft(State.currentDraftId);
        showToast(t('GRAFIDA_MSG_PUBLISH_OK'), 'success');
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

            const cancelBtn = el('button', 'btn btn-secondary');
            cancelBtn.type = 'button';
            cancelBtn.textContent = t('GRAFIDA_BTN_CANCEL');
            cancelBtn.addEventListener('click', closeModal);

            const copyBtn = el('button', 'btn btn-secondary');
            copyBtn.type = 'button';
            copyBtn.id = 'btn-copy-html';
            copyBtn.textContent = t('GRAFIDA_BTN_COPY_HTML');
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
        State.secureStore = data.secureStore !== false;
        State.supportedFieldTypes = data.supportedFieldTypes || [];
        State.sites = data.sites || [];
    } catch (err) {
        console.error('Bootstrap failed:', err);
    }

    document.getElementById('app').style.opacity = '1';
    applyStrings();
    renderSiteSelector();
    renderSitesScreen();
    renderSettingsScreen();
    showScreen('sites');
}

// ============================================================
//  Wire up DOM once loaded
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('nav#main-nav a[data-screen]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
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
            State.references = null;
            State.editorCss = null;
            updateNewArticleButton();
            if (State.activeScreen === 'articles') loadArticlesScreen();
        });
    }

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

    const btnRefreshRefs = document.getElementById('btn-refresh-refs');
    if (btnRefreshRefs) {
        btnRefreshRefs.addEventListener('click', async () => {
            if (!State.currentSiteId) return;
            try {
                State.references = await api.refreshReferences(State.currentSiteId);
                showToast('References refreshed.', 'success');
                if (State.currentDraftId) {
                    const draft = await api.getDraft(State.currentDraftId).catch(() => null);
                    if (draft) renderEditorSidebar(draft);
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        });
    }

    const btnBack = document.getElementById('btn-back-to-articles');
    if (btnBack) {
        btnBack.addEventListener('click', () => {
            showScreen('articles');
            loadArticlesScreen();
        });
    }

    const langSel = document.getElementById('settings-language-select');
    if (langSel) {
        langSel.addEventListener('change', () => applyLanguageChange(langSel.value));
    }

    const btnModalClose = document.getElementById('btn-modal-close');
    if (btnModalClose) btnModalClose.addEventListener('click', closeModal);

    bootstrap();
});
