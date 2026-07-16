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
    // The latest update-check result: {available, version, infoURL, download} or null.
    update: null,
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
    // The local-drafts browse state. Drafts are loaded in full per visit and
    // then searched / sorted / filtered / paginated entirely client-side (see
    // filteredSortedDrafts / renderDraftsTab); the query is reset with the
    // remote one whenever the active site changes.
    draftQuery: null,
    draftPaging: { page: 1, totalPages: 1 },
    // Which Articles-page tab is showing: 'drafts' (Local Drafts) or 'remote'.
    articlesTab: 'drafts',
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
    // Media Manager screen state: the site whose adapters are loaded, the list of
    // adapters (filesystems), the current adapter-qualified folder path, and the
    // last-loaded folder entries. Reset when the active site changes.
    mediaSiteId: null,
    mediaAdapters: null,
    mediaPath: '',
    mediaEntries: [],
    // Display→source scale of the canvas in the open image editor, used to map a
    // crop selection (display pixels) back to source pixels.
    imgEditorScale: 1,
    // AI: configured services (no keys), default service id, provider presets, and
    // the enabled-only tool list sent in the bootstrap payload (used by Steps 6-7).
    // secureStoreAi mirrors the AI keychain availability (separate from sites).
    aiServices: [],
    aiDefaultServiceId: null,
    aiProviders: {},
    aiTools: [],
    secureStoreAi: true,
    // Full tool list (all tools including disabled), system prompt and tone map —
    // populated lazily when the Settings screen's AI Tools card is first loaded.
    aiAllTools: [],
    aiSystemPrompt: '',
    aiTones: {},
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

/**
 * Plain-string counterpart of formatNodes(), for places (like toasts) that
 * need a single string rather than DOM nodes.
 *
 * @param {string} template — localized string with `%s` placeholders
 * @param {...string} subs — substitutions, in placeholder order
 * @returns {string}
 */
function formatText(template, ...subs) {
    let i = 0;
    return String(template).replace(/%s/g, () => (subs[i++] ?? ''));
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
 * The inline font style needed to render a FontAwesome glyph inside TinyMCE's
 * toolbar/menu UI, harvested once from the loaded FontAwesome stylesheet.
 *
 * TinyMCE's icon registry (`editor.ui.registry.addIcon`) accepts an arbitrary
 * HTML string, not only an <svg>. We exploit that to render our FA webfont
 * icons as a bare <span class="fa-solid fa-…"> — but TinyMCE's UI does not
 * inherit FontAwesome's font-family/weight, so the glyph would not appear.
 * We read the resolved font properties off a throwaway element's ::before (the
 * same values the app's own icons already render with) and inline them into the
 * span. (Technique ported from the AITiny Joomla plugin.)
 */
let _faIconStyle = null;
function faIconInlineStyle() {
    if (_faIconStyle !== null) return _faIconStyle;
    const probe = document.createElement('i');
    probe.className = 'fa-solid fa-check';
    probe.style.position = 'absolute';
    probe.style.visibility = 'hidden';
    document.body.appendChild(probe);
    const cs = window.getComputedStyle(probe, '::before');
    let family = (cs.fontFamily || '').replace(/"/g, "'");
    if (!family || family === 'inherit') family = "'Font Awesome 7 Free'";
    const weight = cs.fontWeight || '900';
    probe.remove();
    _faIconStyle = 'font-family: ' + family + '; font-weight: ' + weight +
        '; font-style: normal; line-height: 1';
    return _faIconStyle;
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

/**
 * The names of every FontAwesome icon we can render, discovered at runtime from
 * the shipped stylesheet.
 *
 * FontAwesome is NPM-managed and gitignored, so a hard-coded list would rot on
 * every version bump. Instead we read the icon-name -> glyph map straight out of
 * `fontawesome.min.css`, where each icon is a `.fa-<name>{--fa:"\f0c5"}` rule.
 * That file carries only the names we ship a webfont for (solid; brands and the
 * other styles live in stylesheets we do not copy), so every discovered name is
 * renderable as `fa-solid fa-<name>`.
 *
 * A rule may carry several comma-separated selectors, one per alias of the same
 * glyph (`.fa-dollar-sign,.fa-usd{--fa:"\24"}`), so we take every name in the
 * group. The aliases are worth keeping: they are what a user is likely to search
 * for (`home` for `house`, `dollar-sign` for `usd`), and the alphabetical
 * selector order gives no way to tell an alias from the canonical name anyway.
 *
 * @returns {Promise<string[]>} alphabetical icon names, without the `fa-` prefix
 */
let _iconCatalog = null;
function iconCatalog() {
    if (_iconCatalog) return _iconCatalog;
    _iconCatalog = (async () => {
        try {
            const res = await fetch('/css/fontawesome.min.css');
            if (!res.ok) return [];
            const css = await res.text();
            const names = new Set();
            const re = /((?:\.fa-[a-z0-9-]+\s*,\s*)*\.fa-[a-z0-9-]+)\s*\{\s*--fa\s*:/g;
            let m;
            while ((m = re.exec(css)) !== null) {
                m[1].split(',').forEach(sel => names.add(sel.trim().slice(4)));
            }
            return Array.from(names).sort();
        } catch (err) {
            return [];
        }
    })();
    return _iconCatalog;
}

/**
 * Build a searchable FontAwesome icon picker.
 *
 * Renders as a trigger button showing the current icon and its name; clicking it
 * drops down a search box over a grid of every available icon. The selected name
 * is kept in a hidden input carrying `inputId`, so callers read the value exactly
 * as they would a plain text field.
 *
 * @param {string} inputId — id for the hidden value input
 * @param {string} value — currently selected icon name (no `fa-` prefix)
 * @returns {HTMLElement} the picker wrapper
 */
function iconPicker(inputId, value) {
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.id = inputId;
    hidden.value = value || '';

    const preview = el('span', 'icon-picker-preview');
    const label = el('span', 'icon-picker-label');

    const trigger = el('button', 'form-control icon-picker-trigger',
        preview, label, el('span', 'icon-picker-caret', icon('chevron-down')));
    trigger.type = 'button';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    const clearBtn = iconBtn('xmark', t('GRAFIDA_BTN_AI_TOOL_ICON_CLEAR'),
        'btn', 'btn-sm', 'btn-secondary', 'icon-picker-clear');

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control icon-picker-search';
    search.autocomplete = 'off';
    search.placeholder = t('GRAFIDA_PLACEHOLDER_AI_TOOL_ICON_SEARCH');

    const grid = el('div', 'icon-picker-grid');
    grid.setAttribute('role', 'listbox');
    const panel = el('div', 'icon-picker-panel hidden', search, grid);
    const wrap = el('div', 'icon-picker', hidden, el('div', 'icon-picker-row', trigger, clearBtn), panel);

    const syncTrigger = () => {
        clearNode(preview);
        const name = hidden.value;
        if (name) preview.appendChild(icon(name));
        label.textContent = name || t('GRAFIDA_BTN_AI_TOOL_ICON_CHOOSE');
        label.classList.toggle('text-muted', !name);
        clearBtn.classList.toggle('hidden', !name);
    };

    const select = (name) => {
        hidden.value = name;
        syncTrigger();
        close();
        trigger.focus();
    };

    const buildCell = (name) => {
        const cell = el('button', 'icon-picker-cell', icon(name));
        cell.type = 'button';
        cell.title = name;
        cell.setAttribute('aria-label', name);
        cell.setAttribute('role', 'option');
        if (name === hidden.value) {
            cell.classList.add('selected');
            cell.setAttribute('aria-selected', 'true');
        }
        cell.addEventListener('click', () => select(name));
        return cell;
    };

    // FontAwesome ships ~2000 names, so the grid fills in pages as it scrolls
    // rather than laying out every cell up front — otherwise each keystroke in
    // the search box would re-render the whole catalogue.
    const PAGE = 240;
    let matches = [];
    let shown = 0;

    const renderPage = () => {
        const frag = document.createDocumentFragment();
        const upto = Math.min(shown + PAGE, matches.length);
        for (; shown < upto; shown++) frag.appendChild(buildCell(matches[shown]));
        grid.appendChild(frag);
    };

    const renderGrid = (names, query) => {
        clearNode(grid);
        shown = 0;
        const q = query.trim().toLowerCase();
        matches = q ? names.filter(n => n.includes(q)) : names;
        if (!matches.length) {
            grid.appendChild(el('p', 'text-muted icon-picker-empty', t('GRAFIDA_MSG_NO_AI_TOOL_ICONS')));
            return;
        }
        renderPage();
    };

    grid.addEventListener('scroll', () => {
        if (shown >= matches.length) return;
        if (grid.scrollTop + grid.clientHeight >= grid.scrollHeight - 80) renderPage();
    });

    function close() {
        panel.classList.add('hidden');
        trigger.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', onDocClick, true);
    }

    function onDocClick(e) {
        if (!wrap.contains(e.target)) close();
    }

    const open = async () => {
        panel.classList.remove('hidden');
        trigger.setAttribute('aria-expanded', 'true');
        document.addEventListener('click', onDocClick, true);
        const names = await iconCatalog();
        renderGrid(names, search.value);
        // Re-opening on an existing choice should show it: page in far enough to
        // reach it (it may sit well past the first page), then scroll to it.
        const at = hidden.value ? matches.indexOf(hidden.value) : -1;
        if (at >= 0) {
            while (shown <= at) renderPage();
            const sel = grid.querySelector('.selected');
            if (sel) sel.scrollIntoView({ block: 'center' });
        }
        search.focus();
        search.select();
    };

    trigger.addEventListener('click', () => {
        if (panel.classList.contains('hidden')) open(); else close();
    });

    clearBtn.addEventListener('click', () => {
        hidden.value = '';
        syncTrigger();
        trigger.focus();
    });

    search.addEventListener('input', async () => renderGrid(await iconCatalog(), search.value));

    // ESC closes the dropdown only — the modal's own ESC handler must not fire
    // and take the whole dialog down with it.
    wrap.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || panel.classList.contains('hidden')) return;
        e.stopPropagation();
        close();
        trigger.focus();
    });

    // ENTER in the search box picks the first match — the common case when the
    // user knows roughly what the icon is called.
    search.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = grid.querySelector('.icon-picker-cell');
        if (first) first.click();
    });

    syncTrigger();
    return wrap;
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

// Count of in-flight apiFetch() calls; while > 0 the Articles page shows a
// network-activity indicator so it is clear data is still loading.
let netActivityCount = 0;

/** Reflects the current in-flight request count on the Articles page indicator. */
function updateNetActivityIndicator() {
    const ind = document.getElementById('articles-net-indicator');
    if (ind) ind.classList.toggle('active', netActivityCount > 0);
}

async function apiFetch(method, path, body = null) {
    const opts = {
        method,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    };
    if (body !== null) {
        opts.body = JSON.stringify(body);
    }
    netActivityCount++;
    updateNetActivityIndicator();
    try {
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
    } finally {
        netActivityCount = Math.max(0, netActivityCount - 1);
        updateNetActivityIndicator();
    }
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
    exportDraft: (id, directory) => apiFetch('POST', `/api/drafts/${id}/export`, { directory }),
    importDraft: (siteId, payload) => apiFetch('POST', '/api/drafts/import', { siteId, payload }),
    importDraftInto: (id, payload) => apiFetch('POST', `/api/drafts/${id}/import`, { payload }),
    selectDirectory: () => apiFetch('POST', '/api/dialog/select-directory'),
    uploadMedia: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media`, body),
    openFile: (filter) => apiFetch('POST', '/api/dialog/open-file', { filter }),
    browseMedia: (siteId, path = '') => apiFetch('GET', `/api/sites/${siteId}/media?path=${encodeURIComponent(path)}`),
    getMediaBlob: (id) => apiFetch('GET', `/api/media/${id}`),
    getMediaAdapters: (siteId) => apiFetch('GET', `/api/sites/${siteId}/media/adapters`),
    getMediaFile: (siteId, path) => apiFetch('GET', `/api/sites/${siteId}/media/file?path=${encodeURIComponent(path)}`),
    getSiteImage: (siteId, url) => apiFetch('GET', `/api/sites/${siteId}/image?url=${encodeURIComponent(url)}`),
    uploadSiteMedia: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media/files`, body),
    createMediaFolder: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media/folder`, body),
    renameMedia: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media/rename`, body),
    updateMediaContent: (siteId, body) => apiFetch('POST', `/api/sites/${siteId}/media/content`, body),
    deleteSiteMedia: (siteId, path) => apiFetch('DELETE', `/api/sites/${siteId}/media?path=${encodeURIComponent(path)}`),
    convertMarkdown: (markdown) => apiFetch('POST', '/api/markdown', { markdown }),
    setLanguage: (tag) => apiFetch('POST', '/api/settings/language', { tag }),
    setDisplayMode: (mode) => apiFetch('POST', '/api/settings/display-mode', { mode }),
    systemTheme: () => apiFetch('GET', '/api/settings/system-theme'),
    checkUpdate: () => apiFetch('GET', '/api/update'),
    getStorageInfo: () => apiFetch('GET', '/api/settings/storage'),
    openStorageFolder: () => apiFetch('POST', '/api/settings/storage/open'),
    resetStorage: () => apiFetch('POST', '/api/settings/storage/reset'),
    openUrl: (url) => apiFetch('POST', '/api/open-url', { url }),
    // AI Services
    listAiServices: () => apiFetch('GET', '/api/ai/services'),
    getAiService: (id) => apiFetch('GET', `/api/ai/services/${id}`),
    createAiService: (body) => apiFetch('POST', '/api/ai/services', body),
    updateAiService: (id, body) => apiFetch('PATCH', `/api/ai/services/${id}`, body),
    deleteAiService: (id) => apiFetch('DELETE', `/api/ai/services/${id}`),
    setAiServiceDefault: (id) => apiFetch('POST', `/api/ai/services/${id}/default`),
    resolvedAiService: (id, toolKey) => {
        const q = toolKey ? '?tool=' + encodeURIComponent(toolKey) : '';
        return apiFetch('GET', `/api/ai/services/${id}/resolved${q}`);
    },
    // AI Tools
    listAiTools: () => apiFetch('GET', '/api/ai/tools'),
    setSystemPrompt: (body) => apiFetch('PUT', '/api/ai/system-prompt', body),
    updateAiTool: (key, body) => apiFetch('PATCH', `/api/ai/tools/${key}`, body),
    createAiTool: (body) => apiFetch('POST', '/api/ai/tools', body),
    deleteAiTool: (key) => apiFetch('DELETE', `/api/ai/tools/${key}`),
    aiProxy: (body) => apiFetch('POST', '/api/ai/proxy', body),
    aiRender: (content, format) => apiFetch('POST', '/api/ai/render', { content, format: format || 'auto' }),
    // AI Chats (Step 8)
    getDraftChats: (draftId) => apiFetch('GET', `/api/drafts/${draftId}/chats`),
    createAiChat: (body) => apiFetch('POST', '/api/ai/chats', body),
    getAiChat: (id) => apiFetch('GET', `/api/ai/chats/${id}`),
    updateAiChat: (id, body) => apiFetch('PATCH', `/api/ai/chats/${id}`, body),
    deleteAiChat: (id) => apiFetch('DELETE', `/api/ai/chats/${id}`),
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
    // Static backdrop: a click outside the modal never closes it. Dragging a text
    // selection out of the body ends its click on the overlay, which used to
    // discard the dialog mid-edit. Escape and the footer buttons are the ways out.
    overlay.onclick = null;
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
 * option is active; ESC cancels (No). Clicking either button resolves
 * accordingly.
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

/**
 * Refresh the favicon — and the "visit the site" button below it — shown under
 * the sidebar site dropdown. Both only exist while a site is selected.
 */
function renderSidebarFavicon() {
    const box = document.getElementById('sidebar-site-favicon');
    if (!box) return;
    clearNode(box);

    const site = State.currentSiteId
        ? State.sites.find(s => s.id === State.currentSiteId)
        : null;

    if (!site) return;

    box.appendChild(siteFaviconEl(site));

    if (!site.baseUrl) return;

    const visitBtn = iconBtn(
        'arrow-up-right-from-square', t('GRAFIDA_BTN_OPEN_SITE'),
        'btn', 'btn-sm', 'btn-secondary'
    );
    visitBtn.addEventListener('click', async () => {
        try {
            await api.openUrl(site.baseUrl);
        } catch (err) {
            showToast(err.message, 'error');
        }
    });
    box.appendChild(visitBtn);
}

function renderSiteSelector() {
    const sel = document.getElementById('site-select');
    clearNode(sel);

    // The placeholder is only meaningful when there is nothing to select.
    if (!State.sites.length) {
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = `— ${t('GRAFIDA_MSG_NO_SITES_SHORT')} —`;
        sel.appendChild(defaultOpt);
        sel.title = t('GRAFIDA_MSG_NO_SITES');
        sel.disabled = true;
        State.currentSiteId = null;
        renderSidebarFavicon();
        updateNewArticleButton();
        updateNavState();
        return;
    }

    sel.removeAttribute('title');

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
    const btnImport = document.getElementById('btn-import-draft');
    if (btnImport) btnImport.disabled = !State.currentSiteId;
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
        State.draftQuery = defaultDraftQuery();
        State.articleListRefs = null;
    }
    if (!State.draftQuery) State.draftQuery = defaultDraftQuery();

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
        container.appendChild(buildArticlesTabs());

        // Local-drafts tab: filter/sort toolbar + list + pagination.
        const draftsPanel = el('div', 'article-list-section articles-tab-panel');
        draftsPanel.id = 'articles-tab-drafts';
        draftsPanel.appendChild(buildDraftFilterBar());
        const draftsList = el('div', null);
        draftsList.id = 'articles-drafts-list';
        draftsPanel.appendChild(draftsList);
        const draftsPager = el('div', 'articles-pagination');
        draftsPager.id = 'articles-drafts-pagination';
        draftsPanel.appendChild(draftsPager);
        container.appendChild(draftsPanel);

        // Remote-articles tab: filter/sort toolbar + list + pagination.
        const remoteSection = el('div', 'article-list-section articles-tab-panel');
        remoteSection.id = 'articles-tab-remote';
        remoteSection.appendChild(buildArticleFilterBar());
        const list = el('div', null);
        list.id = 'articles-remote-list';
        remoteSection.appendChild(list);
        const pager = el('div', 'articles-pagination');
        pager.id = 'articles-remote-pagination';
        remoteSection.appendChild(pager);
        container.appendChild(remoteSection);

        applyArticlesTab();
        renderDraftsTab();
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

/** Builds the Local Drafts / Remote Articles tab strip. */
function buildArticlesTabs() {
    const tabs = el('div', 'articles-tabs');
    const mk = (id, key) => {
        const btn = el('button', 'articles-tab', t(key));
        btn.type = 'button';
        btn.dataset.tab = id;
        if (State.articlesTab === id) btn.classList.add('active');
        btn.addEventListener('click', () => {
            if (State.articlesTab === id) return;
            State.articlesTab = id;
            applyArticlesTab();
        });
        return btn;
    };
    tabs.appendChild(mk('drafts', 'GRAFIDA_LBL_LOCAL_DRAFTS'));
    tabs.appendChild(mk('remote', 'GRAFIDA_LBL_REMOTE_ARTICLES'));

    // Network-activity indicator: visible (via updateNetActivityIndicator) only
    // while one or more apiFetch() requests are in flight.
    const indicator = el('span', 'articles-net-indicator',
        el('span', 'spinner'),
        el('span', null, t('GRAFIDA_MSG_LOADING')));
    indicator.id = 'articles-net-indicator';
    tabs.appendChild(indicator);
    updateNetActivityIndicator();

    return tabs;
}

/** Shows the active tab's panel (and highlights its button), hides the other. */
function applyArticlesTab() {
    const active = State.articlesTab;
    const draftsPanel = document.getElementById('articles-tab-drafts');
    const remotePanel = document.getElementById('articles-tab-remote');
    if (draftsPanel) draftsPanel.classList.toggle('hidden', active !== 'drafts');
    if (remotePanel) remotePanel.classList.toggle('hidden', active !== 'remote');
    document.querySelectorAll('.articles-tabs .articles-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === active);
    });
}

// The columns the local-drafts list may be sorted by — only the fields a draft
// actually carries (no hits/author/dates as Joomla's remote list has).
// Deliberately NO id column, unlike ARTICLE_SORT_COLUMNS. The id a local row
// shows is the Joomla id of the article it mirrors, which a draft only has once
// it has been published — so ordering by it would sort half the list by a value
// the other half does not have.
const DRAFT_SORT_COLUMNS = [
    ['modified', 'GRAFIDA_SORT_MODIFIED'],
    ['created', 'GRAFIDA_SORT_CREATED'],
    ['title', 'GRAFIDA_SORT_TITLE'],
    ['category', 'GRAFIDA_SORT_CATEGORY'],
    ['language', 'GRAFIDA_SORT_LANGUAGE'],
    ['state', 'GRAFIDA_SORT_STATUS'],
];

/**
 * The default filter/sort/page state for the local-drafts list.
 *
 * Most-recently-edited first: this is a working list, so what you touched last
 * is what you are most likely to want again. It also matches the order
 * `DraftRepository::listBySite()` already returns rows in (`updated_at DESC`).
 */
function defaultDraftQuery() {
    return {
        search: '', ordering: 'modified', direction: 'desc',
        category: '', tag: '', language: '', state: '',
        limit: 20, page: 1,
    };
}

let _draftSearchTimer = null;

/** Builds the search / sort / filter toolbar for local drafts. */
function buildDraftFilterBar() {
    const q = State.draftQuery;
    const bar = el('div', 'articles-filter-bar');

    // Search box (debounced, title + alias) + Enter to search immediately.
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control';
    search.placeholder = t('GRAFIDA_PLACEHOLDER_SEARCH');
    search.value = q.search;
    search.setAttribute('aria-label', t('GRAFIDA_PLACEHOLDER_SEARCH'));
    search.addEventListener('input', () => {
        clearTimeout(_draftSearchTimer);
        _draftSearchTimer = setTimeout(() => setDraftQuery({ search: search.value }), 250);
    });
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { clearTimeout(_draftSearchTimer); setDraftQuery({ search: search.value }); }
    });
    bar.appendChild(el('div', 'articles-filter-search', search));

    // Sort column + direction.
    bar.appendChild(filterSelect('GRAFIDA_LBL_SORT_BY',
        DRAFT_SORT_COLUMNS.map(([v, k]) => [v, t(k)]), q.ordering, false,
        (v) => setDraftQuery({ ordering: v })));
    bar.appendChild(filterSelect('GRAFIDA_LBL_DIRECTION', [
        ['desc', t('GRAFIDA_SORT_DIR_DESC')],
        ['asc', t('GRAFIDA_SORT_DIR_ASC')],
    ], q.direction, false, (v) => setDraftQuery({ direction: v })));

    // Category / Tag / Language filters from the cached reference data. Drafts
    // store tag *titles*, so the tag filter matches on title rather than id.
    const refs = State.articleListRefs || { categories: [], tags: [], languages: [] };
    bar.appendChild(filterSelect('GRAFIDA_FILTER_CATEGORY_ANY',
        categoryFilterOptions(refs.categories), q.category, true,
        (v) => setDraftQuery({ category: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_TAG_ANY',
        (refs.tags || []).map(tg => [tg.title, tg.title]), q.tag, true,
        (v) => setDraftQuery({ tag: v })));
    bar.appendChild(filterSelect('GRAFIDA_FILTER_LANGUAGE_ANY',
        languageFilterOptions(refs.languages), q.language, true,
        (v) => setDraftQuery({ language: v })));

    // Published state filter (no featured/checked-out — drafts have neither).
    bar.appendChild(filterSelect('GRAFIDA_FILTER_STATE_ANY', [
        ['1', t('GRAFIDA_OPT_PUBLISHED')],
        ['0', t('GRAFIDA_OPT_UNPUBLISHED')],
        ['2', t('GRAFIDA_OPT_ARCHIVED')],
        ['-2', t('GRAFIDA_OPT_TRASHED')],
    ], q.state, true, (v) => setDraftQuery({ state: v })));

    // Per-page limit.
    bar.appendChild(filterSelect('GRAFIDA_LBL_PER_PAGE',
        ARTICLE_PER_PAGE.map(n => [String(n), String(n)]), String(q.limit), false,
        (v) => setDraftQuery({ limit: Number(v) })));

    // Clear filters.
    const clearBtn = iconBtn('rotate-left', t('GRAFIDA_BTN_CLEAR_FILTERS'), 'btn', 'btn-secondary', 'btn-sm');
    clearBtn.addEventListener('click', () => {
        State.draftQuery = defaultDraftQuery();
        const panel = document.getElementById('articles-tab-drafts');
        const oldBar = panel.querySelector('.articles-filter-bar');
        panel.replaceChild(buildDraftFilterBar(), oldBar);
        renderDraftsTab();
    });
    bar.appendChild(el('div', 'articles-filter-clear', clearBtn));

    return bar;
}

/** Applies the current draft query's filters + sort to State.drafts. */
function filteredSortedDrafts() {
    const q = State.draftQuery;
    let list = State.drafts.slice();

    const search = q.search.trim().toLowerCase();
    if (search) {
        list = list.filter(d =>
            (d.title || '').toLowerCase().includes(search)
            || (d.alias || '').toLowerCase().includes(search));
    }
    if (q.category !== '') list = list.filter(d => String(d.catid) === String(q.category));
    if (q.tag !== '') list = list.filter(d => Array.isArray(d.tags) && d.tags.includes(q.tag));
    if (q.language !== '') list = list.filter(d => String(d.language) === String(q.language));
    if (q.state !== '') list = list.filter(d => Number(d.state) === Number(q.state));

    const dir = q.direction === 'asc' ? 1 : -1;
    list.sort((a, b) => dir * compareDrafts(a, b, q.ordering));
    return list;
}

/** Comparator for the local-drafts sort (ascending; caller flips for desc). */
function compareDrafts(a, b, ordering) {
    switch (ordering) {
        // The timestamps are naive UTC 'Y-m-d H:i:s', which sorts
        // lexicographically in chronological order — so compare the strings and
        // skip Date.parse() entirely, which WKWebView mishandles for this form.
        case 'modified': return (a.updatedAt || '').localeCompare(b.updatedAt || '');
        case 'created':  return (a.createdAt || '').localeCompare(b.createdAt || '');
        case 'title':    return (a.title || '').localeCompare(b.title || '');
        case 'category': return (a.categoryTitle || '').localeCompare(b.categoryTitle || '');
        case 'language': return (a.language || '').localeCompare(b.language || '');
        case 'state':    return (Number(a.state) || 0) - (Number(b.state) || 0);
        // Not user-selectable — just a stable fallback for an unrecognised
        // ordering, by the local row key (i.e. creation order).
        default:         return (Number(a.id) || 0) - (Number(b.id) || 0);
    }
}

/** Renders the local-drafts list page + pagination (used on load, filter, delete). */
function renderDraftsTab() {
    const list = document.getElementById('articles-drafts-list');
    const pager = document.getElementById('articles-drafts-pagination');
    if (!list || !pager) return;
    clearNode(list);
    clearNode(pager);

    const all = filteredSortedDrafts();
    const limit = State.draftQuery.limit;
    const totalPages = Math.max(1, Math.ceil(all.length / limit));
    const page = Math.min(State.draftQuery.page, totalPages);
    State.draftQuery.page = page;
    State.draftPaging = { page, totalPages };

    if (!all.length) {
        list.appendChild(buildDraftsEmptyState());
        return;
    }

    const start = (page - 1) * limit;
    all.slice(start, start + limit).forEach(draft => list.appendChild(buildArticleItem(draft, 'draft')));

    if (totalPages <= 1) return;

    const prev = iconBtn('chevron-left', t('GRAFIDA_BTN_PREV_PAGE'), 'btn', 'btn-secondary', 'btn-sm');
    prev.disabled = page <= 1;
    prev.addEventListener('click', () => gotoDraftPage(page - 1));

    const next = iconBtn('chevron-right', t('GRAFIDA_BTN_NEXT_PAGE'), 'btn', 'btn-secondary', 'btn-sm');
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => gotoDraftPage(page + 1));

    const info = el('span', 'articles-pagination-info',
        ...formatNodes(t('GRAFIDA_PAGINATION_INFO'), String(page), String(totalPages)));

    pager.appendChild(prev);
    pager.appendChild(info);
    pager.appendChild(next);
}

/**
 * The empty local-articles list. An empty result because of the filters only
 * needs the "nothing matches" line; a genuinely empty list is a dead end, so it
 * points at the two ways out (write one, or look at what the site already has).
 */
function buildDraftsEmptyState() {
    if (State.drafts.length) {
        return el('div', 'empty-state', el('p', null, t('GRAFIDA_MSG_NO_DRAFTS')));
    }

    const newBtn = iconBtn('file-circle-plus', t('GRAFIDA_BTN_NEW_ARTICLE'), 'btn', 'btn-primary');
    newBtn.addEventListener('click', openNewArticle);

    const remoteBtn = iconBtn('cloud-arrow-down', t('GRAFIDA_BTN_LIST_SITE_ARTICLES'), 'btn', 'btn-secondary');
    remoteBtn.addEventListener('click', () => {
        State.articlesTab = 'remote';
        applyArticlesTab();
    });

    return el('div', 'empty-state',
        el('p', null, t('GRAFIDA_MSG_NO_DRAFTS_YET')),
        el('div', 'empty-state-actions', newBtn, remoteBtn));
}

/** Applies a patch to the draft query (resetting to page 1) and re-renders. */
function setDraftQuery(patch) {
    State.draftQuery = { ...State.draftQuery, ...patch, page: 1 };
    renderDraftsTab();
}

/** Moves to a specific local-drafts page (keeping all filters) and re-renders. */
function gotoDraftPage(page) {
    const total = State.draftPaging.totalPages || 1;
    State.draftQuery = { ...State.draftQuery, page: Math.max(1, Math.min(total, page)) };
    renderDraftsTab();
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
    const pager = document.getElementById('articles-remote-pagination');
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
    const pager = document.getElementById('articles-remote-pagination');
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

// The publish-state icon shown before an article's title. Colour follows
// Joomla's semantics (green published, red unpublished); the icon itself is
// what distinguishes the states for anyone who cannot tell the colours apart.
const ARTICLE_STATE_ICONS = {
    1:  { icon: 'check',       cls: 'state-published',   key: 'GRAFIDA_OPT_PUBLISHED' },
    0:  { icon: 'xmark',       cls: 'state-unpublished', key: 'GRAFIDA_OPT_UNPUBLISHED' },
    2:  { icon: 'box-archive', cls: 'state-archived',    key: 'GRAFIDA_OPT_ARCHIVED' },
    '-2': { icon: 'trash',     cls: 'state-trashed',     key: 'GRAFIDA_OPT_TRASHED' },
};

/** The fixed-width, colour-coded publish-state icon for an article list row. */
function articleStateIcon(state) {
    const info = ARTICLE_STATE_ICONS[Number(state ?? 1)] || ARTICLE_STATE_ICONS[0];
    const label = t(info.key);
    const glyph = icon(info.icon);
    glyph.classList.add('fa-fw');

    const wrap = el('span', `article-state-icon ${info.cls}`, glyph);
    wrap.title = label;
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', label);
    return wrap;
}

/**
 * The Joomla article ID a row stands for, or null when it has none.
 *
 * A remote row IS a Joomla article, so its own id is the answer. A local row is
 * a draft, whose `id` is a key in our own `drafts` table — an internal number
 * that means nothing on the site — so the article ID is the `remoteId` of the
 * article it mirrors, and a draft that has never been published has no article
 * ID at all.
 */
function articleJoomlaId(article, type) {
    const id = type === 'remote' ? article.id : article.remoteId;

    return id == null ? null : id;
}

function buildArticleItem(article, type) {
    const item = el('div', 'article-item');

    const titleDiv = el('div', 'article-item-title',
        articleStateIcon(article.state), article.title || '(Untitled)');

    const joomlaId = articleJoomlaId(article, type);
    if (joomlaId != null) {
        const idEl = el('span', 'article-item-id', '#' + joomlaId);
        idEl.title = t('GRAFIDA_LBL_ARTICLE_ID');
        idEl.setAttribute('aria-label', t('GRAFIDA_LBL_ARTICLE_ID') + ' ' + joomlaId);
        // After the state icon, before the title: the icon leads every row (a
        // clean left rail of state glyphs), and the id belongs with the title
        // it identifies rather than out on the margin.
        titleDiv.insertBefore(idEl, titleDiv.childNodes[1] || null);
    }
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
        renderDraftsTab();
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

    // Notify the AI panel that the editor has (re)initialised. The panel resets
    // its conversation state and hides itself so each article starts with a
    // clean slate. panel.js may not be loaded in tests, so guard the call.
    if (typeof GrafidaAIPanel !== 'undefined') GrafidaAIPanel.onEditorOpen();
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

    // Alias (URL slug) directly below the title.
    const aliasInput = document.getElementById('editor-alias-input');
    if (aliasInput) aliasInput.value = draft.alias || '';

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
            ...formatNodes(t('GRAFIDA_MSG_UNSUPPORTED_FIELDS'), names)
        );
        sidebar.appendChild(notice);
    }

    // Meta description
    const metadescEl = document.createElement('textarea');
    metadescEl.id = 'editor-metadesc';
    metadescEl.className = 'form-control';
    metadescEl.rows = 3;
    metadescEl.value = draft.metadesc || '';
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_METADESC'), metadescEl));

    // Keywords (Joomla 4+ dropped the "Meta" prefix on this label)
    const metakeyEl = document.createElement('input');
    metakeyEl.id = 'editor-metakey';
    metakeyEl.type = 'text';
    metakeyEl.className = 'form-control';
    metakeyEl.value = draft.metakey || '';
    sidebar.appendChild(formGroup(t('GRAFIDA_LBL_METAKEY'), metakeyEl));

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

// A small built-in fallback set of broadly-useful CSS classes, shown in the
// editor's "Styles" drop-down alongside any classes discovered in the site's
// editor.css (and used on their own when the site supplies no editor.css).
const EDITOR_CLASS_DEFAULTS = [
    'lead', 'text-muted', 'text-center', 'text-end', 'float-start', 'float-end',
    'img-fluid', 'rounded', 'border', 'table', 'table-striped',
];

/**
 * Discover CSS class names from a stylesheet's selectors. Comments and
 * declaration blocks are stripped first so only selector text is scanned,
 * then every `.class` token is collected (deduped, alphabetical).
 */
function parseEditorCssClasses(css) {
    if (!css || typeof css !== 'string') return [];
    const selectors = css
        .replace(/\/\*[\s\S]*?\*\//g, ' ') // drop comments
        .replace(/\{[^}]*\}/g, ' ');       // drop declaration blocks, keep selectors
    const found = new Set();
    const re = /\.(-?[A-Za-z_][\w-]*)/g;
    let m;
    while ((m = re.exec(selectors)) !== null) found.add(m[1]);
    return [...found].sort((a, b) => a.localeCompare(b));
}

/** The class list offered by the Styles drop-down: editor.css classes + defaults. */
function editorStyleClasses() {
    const parsed = parseEditorCssClasses(State.editorCss);
    return [...new Set([...parsed, ...EDITOR_CLASS_DEFAULTS])]
        .sort((a, b) => a.localeCompare(b));
}

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

    // CSS classes offered by the "Styles" drop-down, and the inline/block format
    // pair backing each one (see the styleselect button in setup). The block
    // variant is a *selector* format so it only sets the class on an existing
    // block (it never changes the tag); the inline variant wraps a <span>.
    const editorClasses = editorStyleClasses();
    const BLOCK_FORMAT_SELECTOR =
        'p,h1,h2,h3,h4,h5,h6,div,blockquote,pre,ul,ol,li,table,td,th,figure,img,a';
    const styleFormats = {};
    editorClasses.forEach((cls, i) => {
        styleFormats['grafidaInline_' + i] = { inline: 'span', classes: cls };
        styleFormats['grafidaBlock_' + i] = { selector: BLOCK_FORMAT_SELECTOR, classes: cls };
    });

    // The AI Tools / AI Assistant buttons are only offered once at least one AI
    // service is configured: with no provider connection there is nothing for
    // them to talk to, so showing them would be a dead end.
    const hasAiService = State.aiServices.length > 0;
    const aiToolbarSegment = hasAiService ? ' | aitools aiassistant' : '';

    await tinymce.init({
        formats: styleFormats,
        selector: '#tinymce-editor',
        height: '100%',
        resize: false,
        promotion: false,
        branding: false,
        skin: editorSkin(),
        // The editor UI follows the interface language: load the matching pack
        // from js/tinymce/langs/ (en-GB has none — TinyMCE's default UI is English).
        ...(editorLanguage()
            ? { language: editorLanguage(), language_url: '/js/tinymce/langs/' + editorLanguage() + '.js' }
            : {}),
        // Use the native webview spell checker (the old TinyMCE spellchecker
        // plugin was removed in v6+). This sets spellcheck="true" on the editing
        // body so WKWebView/WebKitGTK/Edge underline misspellings; suggestions
        // appear in the native context menu (Ctrl/Cmd + right-click, since
        // TinyMCE's own context menu otherwise intercepts the right-click).
        browser_spellcheck: true,
        // The editor UI always follows the app theme; the editing surface only
        // switches to the dark built-in CSS when the site supplies no editor.css.
        content_css: cssOpts.length ? cssOpts : editorContentCss(),
        document_base_url: baseUrl,
        // Keep the offline-image tag (data-grafida-media-id) in the editor output
        // so it survives save/getContent and reaches PublishService.
        extended_valid_elements: 'img[src|alt|title|class|style|width|height|loading|data-path|data-grafida-media-id]',
        menubar: 'file edit view insert format tools table',
        // The built-in "code" plugin opens raw HTML in a plain textarea; we
        // replace it with our own CodeMirror-backed "sourcecode" item (registered
        // in setup) for syntax highlighting, so the plugin is intentionally absent.
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'fullscreen', 'accordion',
            'insertdatetime', 'media', 'table', 'help', 'wordcount', 'quickbars'
        ],
        // Tools menu: our "sourcecode" item replaces the dropped "code" item.
        menu: {
            tools: { title: 'Tools', items: 'sourcecode wordcount' },
        },
        toolbar: 'undo redo | blocks styleselect | bold italic underline strikethrough | ' +
                 'alignleft aligncenter alignright alignjustify | ' +
                 'bullist numlist outdent indent | removeformat | ' +
                 'readmore | link image | sourcecode' + aiToolbarSegment,
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
            '}' +
            // Constrain images to the editing surface. Joomla bakes a photo's full
            // intrinsic size into the <img> (e.g. width="4032"), and a site editor.css
            // that omits a max-width rule leaves it that big in the editor: it then
            // overflows the editor's scroll box, and WebView (WKWebView) hit-testing
            // on the overflowing image breaks — the picture can't be clicked, selected
            // or double-clicked to edit. Scaling it to fit keeps it interactive. Only
            // the editor view is affected; the published width/height are untouched.
            'img {' +
            '  max-width: 100%;' +
            '  height: auto;' +
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

            // "Styles" drop-down: apply a CSS class to the selection, the way
            // Joomla's editor does. The class list is editorClasses (site
            // editor.css + built-in defaults). Application is automatic: a text
            // selection is wrapped in a <span class="…"> (inline format), while a
            // mere cursor sets the class on the enclosing block (selector format).
            // Each item is a toggle that reflects, and removes, an active class.
            const classFormatName = (cls, collapsed) => {
                const i = editorClasses.indexOf(cls);
                if (i < 0) return null;
                return (collapsed ? 'grafidaBlock_' : 'grafidaInline_') + i;
            };
            const classIsActive = (cls) => {
                const i = editorClasses.indexOf(cls);
                if (i < 0) return false;
                return editor.formatter.match('grafidaInline_' + i) ||
                       editor.formatter.match('grafidaBlock_' + i);
            };
            editor.ui.registry.addMenuButton('styleselect', {
                text: t('GRAFIDA_LBL_STYLES'),
                tooltip: t('GRAFIDA_LBL_STYLES'),
                fetch: (done) => {
                    done(editorClasses.map(cls => ({
                        type: 'togglemenuitem',
                        text: cls,
                        onAction: () => {
                            const name = classFormatName(cls, editor.selection.isCollapsed());
                            if (!name) return;
                            editor.undoManager.transact(() => editor.formatter.toggle(name));
                            editor.nodeChanged();
                        },
                        onSetup: (api) => {
                            const update = () => api.setActive(classIsActive(cls));
                            update();
                            editor.on('NodeChange', update);
                            return () => editor.off('NodeChange', update);
                        },
                    })));
                },
            });

            // "CSS class…" action: set any CSS class(es) on the selected image.
            // TinyMCE's image dialog has no free-text class field, so we add a
            // small prompt that pre-fills the image's current class and writes it
            // back in one undo step. Empty input clears the attribute.
            editor.ui.registry.addButton('imageclass', {
                text: t('GRAFIDA_BTN_IMAGE_CLASS'),
                tooltip: t('GRAFIDA_BTN_IMAGE_CLASS'),
                onAction: () => {
                    const node = editor.selection.getNode();
                    if (!node || node.nodeName.toLowerCase() !== 'img') return;
                    editor.windowManager.open({
                        title: t('GRAFIDA_LBL_IMAGE_CLASS'),
                        body: {
                            type: 'panel',
                            items: [{ type: 'input', name: 'cls', label: t('GRAFIDA_LBL_IMAGE_CLASS') }],
                        },
                        initialData: { cls: node.getAttribute('class') || '' },
                        buttons: [
                            { type: 'cancel', text: t('GRAFIDA_BTN_CANCEL') },
                            { type: 'submit', text: t('GRAFIDA_BTN_SAVE'), primary: true },
                        ],
                        onSubmit: (dialog) => {
                            const cls = (dialog.getData().cls || '').trim().replace(/\s+/g, ' ');
                            editor.undoManager.transact(() => {
                                editor.dom.setAttrib(node, 'class', cls || null);
                            });
                            editor.nodeChanged();
                            dialog.close();
                        },
                    });
                },
            });

            // Floating toolbar shown when an image is selected, so editing an
            // image's properties (size, alt, alignment, class) is discoverable: the
            // "image" item re-opens TinyMCE's Insert/Edit Image dialog — where the
            // Dimensions (width/height), description and Advanced (CSS, border,
            // spacing) fields live — and "imageclass" sets free-text CSS classes.
            editor.ui.registry.addContextToolbar('grafidaImageTools', {
                predicate: (node) => node.nodeName.toLowerCase() === 'img',
                position: 'node',
                scope: 'node',
                items: 'image imageclass | alignleft aligncenter alignright',
            });

            // ------------------------------------------------------------------
            //  AI toolbar buttons
            //
            //  'aiassistant' — toggles the #ai-panel (empty new chat).
            //  'aitools'     — a drop-down menu listing each enabled tool;
            //                  clicking an item opens the panel and immediately
            //                  runs that tool against the current document.
            //
            //  The menu button approach (addMenuButton) is used for per-tool
            //  entries because the number of configured tools is unbounded and
            //  each tool would otherwise add its own toolbar button, overflowing
            //  the toolbar. A single 'aitools' menu keeps the toolbar tidy.
            // ------------------------------------------------------------------

            // Custom sparkle icon for the AI assistant button.
            editor.ui.registry.addIcon('aiassistant',
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">' +
                // Large 4-pointed star
                '<path d="M12 2 L14 10 L22 12 L14 14 L12 22 L10 14 L2 12 L10 10 Z"/>' +
                // Small upper-right sparkle
                '<path d="M19 2 L19.8 4.5 L22 5.5 L19.8 6.5 L19 9 L18.2 6.5 L16 5.5 L18.2 4.5 Z"/>' +
                '</svg>'
            );

            editor.ui.registry.addButton('aiassistant', {
                icon: 'aiassistant',
                tooltip: t('GRAFIDA_BTN_AI_ASSISTANT'),
                onAction: () => {
                    if (typeof GrafidaAIPanel !== 'undefined') GrafidaAIPanel.toggle();
                },
            });

            // Per-tool menu button. The fetch callback runs at menu-open time so
            // it always reflects the current State.aiTools list.
            editor.ui.registry.addMenuButton('aitools', {
                text: t('GRAFIDA_BTN_AI_TOOLS'),
                tooltip: t('GRAFIDA_BTN_AI_TOOLS'),
                fetch: (done) => {
                    // The "Custom…" item is always offered (even with no tools
                    // configured): it opens the panel for a free-form prompt, so
                    // a new user discovers that they may ask anything, not just
                    // run a preset tool.
                    const customItem = {
                        type: 'menuitem',
                        text: t('GRAFIDA_MENU_AI_CUSTOM_PROMPT'),
                        icon: 'aiassistant',
                        onAction: () => {
                            if (typeof GrafidaAIPanel !== 'undefined') {
                                GrafidaAIPanel.openCustom();
                            }
                        },
                    };
                    if (!State.aiTools.length) {
                        done([
                            {
                                type: 'menuitem',
                                text: t('GRAFIDA_MSG_NO_AI_TOOLS'),
                                enabled: false,
                            },
                            { type: 'separator' },
                            customItem,
                        ]);
                        return;
                    }
                    done([
                        ...State.aiTools.map(tool => {
                            // Render each tool's FontAwesome icon by registering a
                            // TinyMCE icon whose "SVG" is really an <i> carrying the
                            // FA class (see faIconInlineStyle). Lazy + idempotent so
                            // it stays correct for custom tools / list changes.
                            // Guard the (user-editable) icon name to a strict FA
                            // slug before it reaches an HTML string, so a stray
                            // value cannot inject markup into the TinyMCE UI.
                            let iconName;
                            if (tool.icon && /^[a-z0-9-]+$/i.test(tool.icon)) {
                                iconName = 'grafida-fa-' + tool.icon;
                                editor.ui.registry.addIcon(
                                    iconName,
                                    '<span class="fa-solid fa-' + tool.icon +
                                    '" aria-hidden="true" style="' +
                                    faIconInlineStyle() + '"></span>'
                                );
                            }
                            return {
                                type: 'menuitem',
                                icon: iconName,
                                text: tool.title,
                                onAction: () => {
                                    if (typeof GrafidaAIPanel !== 'undefined') {
                                        GrafidaAIPanel.openWithTool(tool);
                                    }
                                },
                            };
                        }),
                        { type: 'separator' },
                        customItem,
                    ]);
                },
            });

            // Keyboard shortcuts for the Format ▸ Formats entries that lack one:
            // inline Code, and the Pre / Blockquote blocks.
            editor.addShortcut('ctrl+shift+c', 'Inline code format', () => {
                editor.execCommand('mceToggleFormat', false, 'code');
            });
            editor.addShortcut('ctrl+shift+p', 'Preformatted block', () => {
                editor.execCommand('mceToggleFormat', false, 'pre');
            });
            editor.addShortcut('ctrl+shift+q', 'Blockquote block', () => {
                editor.execCommand('mceToggleFormat', false, 'blockquote');
            });
            // meta+s is TinyMCE's own alias for ctrl+s on Windows/Linux and
            // cmd+s on macOS. The editor's iframe has its own document, so the
            // document-level Ctrl/Cmd+S listener in app.js never fires while
            // it has focus — this is the editor-focused half of that shortcut.
            editor.addShortcut('meta+s', 'Save draft', () => {
                saveDraft();
            });

            // Ctrl/Cmd+, opens Settings. The editor iframe has its own document,
            // so the app.js document-level listener never fires while it has
            // focus — this is the editor-focused half of that shortcut. Comma is
            // awkward for addShortcut, so match the native keydown directly.
            editor.on('keydown', (e) => {
                if (!isSettingsShortcut(e)) return;
                e.preventDefault();
                navigateToSettings();
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
        // TinyMCE's own "Upload" tab dropzone ("Browse for an image") creates a
        // plain <input type="file"> and clicks it — which Boson's webview never
        // opens (no native file-input open-panel callback). Disable that dead tab;
        // local files are uploaded through the Source field's browse button below,
        // which routes to our native picker via the media browser's "Choose file…".
        image_uploadtab: false,
        // Show the dialog's Dimensions fields (width/height + constrain) and the
        // Advanced tab (CSS class, inline style, border, vertical/horizontal space)
        // so an image's properties can be edited after it is inserted.
        image_dimensions: true,
        image_advtab: true,
        file_picker_types: 'image',
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
async function uploadLocalImage(siteId, cb) {
    let picked;
    try {
        picked = await api.openFile('image');
    } catch (err) {
        showToast(err.message, 'error');
        cb(null);
        return;
    }
    if (!picked || picked.cancelled) { cb(null); return; }
    try {
        const result = await api.uploadMedia(siteId, {
            filename: picked.name, mime: picked.mime || 'image/png',
            dataBase64: picked.dataBase64, draftId: State.currentDraftId,
        });
        cb({ url: result.dataUri, name: picked.name, mediaId: result.id });
    } catch (err) {
        showToast(err.message, 'error');
        cb(null);
    }
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
 * into TinyMCE as a single undo step; Cancel (or Escape) discards.
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

        load('');
    });
}

// ============================================================
//  Media Manager screen
// ============================================================

/** Raster image extensions Grafida's in-app editor can open and re-save. */
const EDITABLE_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

/** The file-name extension of a media entry, lower-cased. */
function mediaEntryExt(entry) {
    const ext = String(entry.extension || '').toLowerCase();
    if (ext) return ext;
    const name = String(entry.name || '');
    const dot = name.lastIndexOf('.');
    return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
}

/** Whether an entry is a raster image the in-app editor can edit. */
function isEditableImage(entry) {
    return EDITABLE_IMAGE_EXTENSIONS.includes(mediaEntryExt(entry));
}

/** Join an adapter folder path and a child name into a full media path. */
function joinMediaPath(dir, name) {
    if (!dir) return name;
    return dir.replace(/\/+$/, '') + '/' + name;
}

/** A human-readable byte size (e.g. "1.4 MB"). */
function formatBytes(bytes) {
    const n = Number(bytes);
    if (!isFinite(n) || n <= 0) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let v = n, i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return (i === 0 ? v : v.toFixed(1)) + ' ' + units[i];
}

/**
 * A small modal prompting for a single line of text. Resolves with the trimmed
 * value, or null if cancelled (Escape / Cancel).
 */
function promptText(title, label, initial = '') {
    return new Promise(resolve => {
        let settled = false;
        const input = el('input', 'form-control');
        input.type = 'text';
        input.value = initial;
        const body = el('div', 'form-group', el('label', null, label), input);

        const finish = (val) => {
            if (settled) return;
            settled = true;
            document.removeEventListener('keydown', onKey, true);
            closeModal();
            resolve(val);
        };
        const submit = () => finish(input.value.trim() || null);

        function onKey(e) {
            if (e.key === 'Enter') { e.preventDefault(); submit(); }
            else if (e.key === 'Escape') { e.preventDefault(); finish(null); }
        }

        const okBtn = iconBtn('check', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
        const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
        okBtn.addEventListener('click', submit);
        cancelBtn.addEventListener('click', () => finish(null));

        document.addEventListener('keydown', onKey, true);
        showModal(title, body, [cancelBtn, okBtn]);
        setTimeout(() => { input.focus(); input.select(); }, 0);
    });
}

/** Entry point for the Media Manager screen (nav click / site change). */
async function loadMediaScreen() {
    const container = document.getElementById('media-container');
    if (!container) return;
    renderMediaHeaderActions();

    if (!State.currentSiteId) {
        clearNode(container);
        container.appendChild(el('div', 'empty-state', el('p', null, t('GRAFIDA_MSG_SELECT_SITE'))));
        return;
    }

    // (Re)load the site's adapters when the active site changed. The first adapter
    // is the default filesystem; its `path` (e.g. "local-images:/") is the root.
    if (State.mediaSiteId !== State.currentSiteId || !State.mediaAdapters) {
        const siteId = State.currentSiteId;
        State.mediaSiteId = siteId;
        State.mediaAdapters = null;
        clearNode(container);
        container.appendChild(el('div', 'loading-row', el('div', 'spinner'), txt(' ' + t('GRAFIDA_MSG_LOADING'))));
        let adapters;
        try {
            const data = await api.getMediaAdapters(siteId);
            adapters = Array.isArray(data.adapters) ? data.adapters : [];
        } catch (err) {
            if (State.currentSiteId !== siteId) return;
            clearNode(container);
            container.appendChild(el('div', 'alert alert-error', String(err.message)));
            return;
        }
        if (State.currentSiteId !== siteId) return;
        State.mediaAdapters = adapters;
        const first = adapters[0];
        State.mediaPath = first && typeof first.path === 'string' ? first.path : '';
    }

    clearNode(container);
    const toolbar = el('div', 'media-mgr-toolbar');
    toolbar.id = 'media-mgr-toolbar';
    container.appendChild(toolbar);
    const grid = el('div', 'media-mgr-grid');
    grid.id = 'media-mgr-grid';
    container.appendChild(grid);

    await reloadMediaManager(State.mediaPath);
}

/** Builds the screen-header Upload / New Folder actions (rebuilt per language). */
function renderMediaHeaderActions() {
    const box = document.getElementById('media-header-actions');
    if (!box) return;
    clearNode(box);
    if (!State.currentSiteId) return;

    const uploadBtn = iconBtn('upload', t('GRAFIDA_BTN_UPLOAD'), 'btn', 'btn-primary', 'btn-sm');
    uploadBtn.addEventListener('click', mediaManagerUpload);
    const folderBtn = iconBtn('folder-plus', t('GRAFIDA_BTN_NEW_FOLDER'), 'btn', 'btn-secondary', 'btn-sm');
    folderBtn.addEventListener('click', mediaManagerNewFolder);
    const refreshBtn = iconBtn('rotate', t('GRAFIDA_BTN_REFRESH'), 'btn', 'btn-secondary', 'btn-sm');
    refreshBtn.addEventListener('click', () => reloadMediaManager(State.mediaPath));

    box.appendChild(uploadBtn);
    box.appendChild(folderBtn);
    box.appendChild(refreshBtn);
}

/** Loads and renders the contents of a media folder. */
async function reloadMediaManager(path) {
    State.mediaPath = path;
    const grid = document.getElementById('media-mgr-grid');
    const toolbar = document.getElementById('media-mgr-toolbar');
    if (!grid || !toolbar) return;

    renderMediaToolbar(toolbar, path);
    clearNode(grid);
    grid.appendChild(el('div', 'loading-row', el('div', 'spinner'), txt(' ' + t('GRAFIDA_MSG_LOADING'))));

    const siteId = State.currentSiteId;
    let data;
    try {
        data = await api.browseMedia(siteId, path);
    } catch (err) {
        if (State.currentSiteId !== siteId || State.mediaPath !== path) return;
        clearNode(grid);
        grid.appendChild(el('div', 'alert alert-error', String(err.message)));
        return;
    }
    if (State.currentSiteId !== siteId || State.mediaPath !== path) return;

    State.mediaEntries = Array.isArray(data.entries) ? data.entries : [];
    renderMediaGrid(grid, State.mediaEntries);
}

/** Renders the breadcrumb / navigation toolbar for the current folder. */
function renderMediaToolbar(toolbar, path) {
    clearNode(toolbar);
    const crumbs = el('div', 'media-mgr-crumbs');

    // An adapter-qualified path looks like "adapter:/a/b". Split off the adapter
    // root so it can be a clickable crumb, then each sub-folder segment.
    const sepIdx = path.indexOf(':/');
    const adapter = sepIdx >= 0 ? path.slice(0, sepIdx) : '';
    const adapterRoot = adapter ? adapter + ':/' : '';
    const rest = sepIdx >= 0 ? path.slice(sepIdx + 2) : path;

    const addCrumb = (label, target, isCurrent) => {
        if (isCurrent) {
            crumbs.appendChild(el('span', 'media-mgr-crumb current', label));
        } else {
            const b = el('button', 'media-mgr-crumb', label);
            b.type = 'button';
            b.addEventListener('click', () => reloadMediaManager(target));
            crumbs.appendChild(b);
        }
        crumbs.appendChild(el('span', 'media-mgr-crumb-sep', '/'));
    };

    const segments = rest.split('/').filter(Boolean);
    addCrumb(adapter || '/', adapterRoot, segments.length === 0);
    let acc = adapterRoot;
    segments.forEach((seg, i) => {
        acc = joinMediaPath(acc, seg);
        addCrumb(seg, acc, i === segments.length - 1);
    });

    toolbar.appendChild(crumbs);

    // Adapter switcher (only when the site exposes more than one filesystem).
    const adapters = State.mediaAdapters || [];
    if (adapters.length > 1) {
        const sel = el('select', 'form-control media-mgr-adapter');
        adapters.forEach(a => {
            const opt = document.createElement('option');
            opt.value = String(a.path || '');
            opt.textContent = String(a.name || a.path || '');
            if (String(a.path || '') === adapterRoot) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', () => reloadMediaManager(sel.value));
        toolbar.appendChild(sel);
    }
}

/** Renders the folder/file grid. */
function renderMediaGrid(grid, entries) {
    clearNode(grid);
    const dirs = entries.filter(en => en.type === 'dir');
    const files = entries.filter(en => en.type === 'file');

    if (dirs.length === 0 && files.length === 0) {
        grid.appendChild(el('div', 'media-mgr-empty', t('GRAFIDA_MSG_MEDIA_EMPTY')));
        return;
    }

    dirs.forEach(d => grid.appendChild(buildMediaCard(d, true)));
    files.forEach(f => grid.appendChild(buildMediaCard(f, false)));
}

/** A small icon-only action button used on a media card. */
function mediaActionBtn(iconName, title, handler) {
    const b = el('button', 'media-mgr-action', icon(iconName));
    b.type = 'button';
    b.title = title;
    b.setAttribute('aria-label', title);
    b.addEventListener('click', (e) => { e.stopPropagation(); handler(); });
    return b;
}

/** Builds a card for one folder or file. */
function buildMediaCard(entry, isDir) {
    const card = el('div', 'media-mgr-item' + (isDir ? ' media-mgr-item-dir' : ''));

    const thumb = el('div', 'media-mgr-thumb');
    if (isDir) {
        thumb.appendChild(icon('folder'));
    } else if (isImageEntry(entry) && typeof entry.url === 'string') {
        const im = document.createElement('img');
        im.src = entry.url;
        im.alt = '';
        im.loading = 'lazy';
        thumb.appendChild(im);
    } else {
        thumb.appendChild(icon('file'));
    }
    card.appendChild(thumb);

    card.appendChild(el('div', 'media-mgr-name', entry.name || ''));
    const metaText = isDir ? '' : [mediaEntryExt(entry).toUpperCase(), formatBytes(entry.size)].filter(Boolean).join(' · ');
    card.appendChild(el('div', 'media-mgr-meta', metaText || ' '));

    const actions = el('div', 'media-mgr-actions');
    if (!isDir && isEditableImage(entry)) {
        actions.appendChild(mediaActionBtn('crop-simple', t('GRAFIDA_BTN_EDIT_IMAGE'), () => openImageEditor(entry)));
    }
    actions.appendChild(mediaActionBtn('pen', t('GRAFIDA_BTN_RENAME'), () => mediaManagerRename(entry)));
    actions.appendChild(mediaActionBtn('trash', t('GRAFIDA_BTN_DELETE'), () => mediaManagerDelete(entry, isDir)));
    card.appendChild(actions);

    if (isDir) {
        card.classList.add('clickable');
        card.addEventListener('click', () => reloadMediaManager(typeof entry.path === 'string' ? entry.path : ''));
    } else if (isImageEntry(entry) && typeof entry.url === 'string') {
        card.classList.add('clickable');
        card.addEventListener('click', () => openMediaPreview(entry));
    }

    return card;
}

/** A simple full-size image preview modal. */
function openMediaPreview(entry) {
    const img = document.createElement('img');
    img.src = entry.url;
    img.alt = entry.name || '';
    img.className = 'media-mgr-preview-img';
    const body = el('div', 'media-mgr-preview', img);
    const closeBtn = iconBtn('xmark', t('GRAFIDA_BTN_CLOSE'), 'btn', 'btn-secondary');
    closeBtn.addEventListener('click', closeModal);
    showModal(entry.name || t('GRAFIDA_LBL_MEDIA_PREVIEW'), body, [closeBtn]);
}

/** Picks a local file via the native dialog and uploads it to the current folder. */
async function mediaManagerUpload() {
    const siteId = State.currentSiteId;
    if (!siteId) return;
    let picked;
    try {
        picked = await api.openFile('any');
    } catch (err) {
        showToast(err.message, 'error');
        return;
    }
    if (!picked || picked.cancelled) return;
    await doMediaUpload(joinMediaPath(State.mediaPath, picked.name), picked, false);
}

async function doMediaUpload(path, picked, override) {
    const siteId = State.currentSiteId;
    try {
        await api.uploadSiteMedia(siteId, {
            path, mime: picked.mime || 'application/octet-stream',
            dataBase64: picked.dataBase64, override,
        });
        showToast(t('GRAFIDA_MSG_MEDIA_UPLOADED'), 'success');
        reloadMediaManager(State.mediaPath);
    } catch (err) {
        // Offer to overwrite when the destination already exists.
        if (!override && /exist/i.test(String(err.message))) {
            const ok = await confirmYesNo(
                t('GRAFIDA_MSG_MEDIA_OVERWRITE_TITLE'),
                el('p', null, ...formatNodes(t('GRAFIDA_MSG_MEDIA_OVERWRITE_CONFIRM'), picked.name)),
            );
            if (ok) await doMediaUpload(path, picked, true);
            return;
        }
        showToast(err.message, 'error');
    }
}

/** Prompts for a name and creates a sub-folder in the current folder. */
async function mediaManagerNewFolder() {
    const siteId = State.currentSiteId;
    if (!siteId) return;
    const name = await promptText(t('GRAFIDA_LBL_NEW_FOLDER'), t('GRAFIDA_LBL_FOLDER_NAME'), '');
    if (!name) return;
    try {
        await api.createMediaFolder(siteId, { path: joinMediaPath(State.mediaPath, name) });
        showToast(t('GRAFIDA_MSG_MEDIA_FOLDER_CREATED'), 'success');
        reloadMediaManager(State.mediaPath);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Prompts for a new name and renames a file/folder in place. */
async function mediaManagerRename(entry) {
    const siteId = State.currentSiteId;
    if (!siteId) return;
    const name = await promptText(t('GRAFIDA_LBL_RENAME'), t('GRAFIDA_LBL_NEW_NAME'), entry.name || '');
    if (!name || name === entry.name) return;
    try {
        await api.renameMedia(siteId, { oldPath: entry.path, newName: name });
        showToast(t('GRAFIDA_MSG_MEDIA_RENAMED'), 'success');
        reloadMediaManager(State.mediaPath);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Confirms and deletes a file/folder. */
async function mediaManagerDelete(entry, isDir) {
    const siteId = State.currentSiteId;
    if (!siteId) return;
    const msgKey = isDir ? 'GRAFIDA_MSG_DELETE_FOLDER_CONFIRM' : 'GRAFIDA_MSG_DELETE_MEDIA_CONFIRM';
    const ok = await confirmYesNo(
        t('GRAFIDA_MSG_DELETE_MEDIA_TITLE'),
        el('p', null, ...formatNodes(t(msgKey), entry.name || '')),
    );
    if (!ok) return;
    try {
        await api.deleteSiteMedia(siteId, entry.path);
        showToast(t('GRAFIDA_MSG_MEDIA_DELETED'), 'success');
        reloadMediaManager(State.mediaPath);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

// --------------------------------------------------------
//  Image editor (crop / resize / rotate / flip)
// --------------------------------------------------------

/** Opens the in-app image editor for an editable raster image entry. */
async function openImageEditor(entry) {
    const siteId = State.currentSiteId;
    let dataUri;
    try {
        // Load the bytes through the backend (a same-origin data: URI) so drawing
        // onto a canvas does not taint it — a cross-origin <img> would block save.
        const res = await api.getMediaFile(siteId, entry.path);
        dataUri = res.dataUri;
    } catch (err) {
        showToast(err.message, 'error');
        return;
    }
    const img = new Image();
    img.onload = () => buildImageEditor(entry, img);
    img.onerror = () => showToast(t('GRAFIDA_MSG_MEDIA_EDIT_LOAD_FAIL'), 'error');
    img.src = dataUri;
}

function buildImageEditor(entry, img) {
    // `work` is an offscreen canvas holding the current edited bitmap.
    let work = document.createElement('canvas');
    work.width = img.naturalWidth;
    work.height = img.naturalHeight;
    work.getContext('2d').drawImage(img, 0, 0);

    const canvas = el('canvas', 'img-editor-canvas');
    const selBox = el('div', 'img-editor-selection hidden');
    const stage = el('div', 'img-editor-stage', canvas, selBox);

    const dims = el('div', 'img-editor-dims');
    const wIn = el('input', 'form-control img-editor-num');
    wIn.type = 'number'; wIn.min = '1';
    const hIn = el('input', 'form-control img-editor-num');
    hIn.type = 'number'; hIn.min = '1';
    const lock = document.createElement('input');
    lock.type = 'checkbox'; lock.checked = true;

    let cropping = false;
    // Current crop selection in *display* pixels, or null.
    let sel = null;

    function clearSelection() {
        sel = null;
        selBox.classList.add('hidden');
    }

    function render() {
        const maxW = 620, maxH = 420;
        const scale = Math.min(1, maxW / work.width, maxH / work.height);
        State.imgEditorScale = scale;

        canvas.width = work.width;
        canvas.height = work.height;
        canvas.getContext('2d').drawImage(work, 0, 0);
        const dispW = Math.round(work.width * scale);
        const dispH = Math.round(work.height * scale);
        canvas.style.width = dispW + 'px';
        canvas.style.height = dispH + 'px';
        stage.style.width = dispW + 'px';
        stage.style.height = dispH + 'px';

        dims.textContent = work.width + ' × ' + work.height;
        wIn.value = String(work.width);
        hIn.value = String(work.height);
        clearSelection();
    }

    // ---- Crop selection (mouse drag over the canvas) ----
    let dragStart = null;
    function pointInStage(e) {
        const r = stage.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(r.width, e.clientX - r.left)),
            y: Math.max(0, Math.min(r.height, e.clientY - r.top)),
        };
    }
    function drawSel() {
        if (!sel) { selBox.classList.add('hidden'); return; }
        selBox.classList.remove('hidden');
        selBox.style.left = sel.x + 'px';
        selBox.style.top = sel.y + 'px';
        selBox.style.width = sel.w + 'px';
        selBox.style.height = sel.h + 'px';
    }
    stage.addEventListener('mousedown', (e) => {
        if (!cropping) return;
        e.preventDefault();
        dragStart = pointInStage(e);
        sel = { x: dragStart.x, y: dragStart.y, w: 0, h: 0 };
        drawSel();
    });
    window.addEventListener('mousemove', onStageDrag);
    function onStageDrag(e) {
        // Self-clean if the modal was closed by another path (e.g. Escape).
        if (!stage.isConnected) { window.removeEventListener('mousemove', onStageDrag); return; }
        if (!cropping || !dragStart) return;
        const p = pointInStage(e);
        sel = {
            x: Math.min(dragStart.x, p.x),
            y: Math.min(dragStart.y, p.y),
            w: Math.abs(p.x - dragStart.x),
            h: Math.abs(p.y - dragStart.y),
        };
        drawSel();
    }
    function onStageDragEnd() {
        if (!stage.isConnected) { window.removeEventListener('mouseup', onStageDragEnd); return; }
        dragStart = null;
    }
    window.addEventListener('mouseup', onStageDragEnd);

    // ---- Operations ----
    function rotate(deg) {
        const c = document.createElement('canvas');
        if (deg === 90 || deg === 270) { c.width = work.height; c.height = work.width; }
        else { c.width = work.width; c.height = work.height; }
        const ctx = c.getContext('2d');
        ctx.translate(c.width / 2, c.height / 2);
        ctx.rotate(deg * Math.PI / 180);
        ctx.drawImage(work, -work.width / 2, -work.height / 2);
        work = c;
        render();
    }
    function flip(horizontal) {
        const c = document.createElement('canvas');
        c.width = work.width; c.height = work.height;
        const ctx = c.getContext('2d');
        if (horizontal) { ctx.translate(c.width, 0); ctx.scale(-1, 1); }
        else { ctx.translate(0, c.height); ctx.scale(1, -1); }
        ctx.drawImage(work, 0, 0);
        work = c;
        render();
    }
    function applyResize() {
        const w = parseInt(wIn.value, 10);
        const h = parseInt(hIn.value, 10);
        if (!(w > 0 && h > 0) || (w === work.width && h === work.height)) return;
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(work, 0, 0, w, h);
        work = c;
        render();
    }
    function applyCrop() {
        if (!sel || sel.w < 2 || sel.h < 2) return;
        const scale = State.imgEditorScale || 1;
        const x = Math.round(sel.x / scale);
        const y = Math.round(sel.y / scale);
        const w = Math.round(sel.w / scale);
        const h = Math.round(sel.h / scale);
        if (w <= 0 || h <= 0) return;
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(work, x, y, w, h, 0, 0, w, h);
        work = c;
        cropping = false;
        stage.classList.remove('cropping');
        cropBtn.classList.remove('active');
        render();
    }

    // ---- Resize aspect lock ----
    wIn.addEventListener('input', () => {
        if (!lock.checked) return;
        const w = parseInt(wIn.value, 10);
        if (w > 0) hIn.value = String(Math.max(1, Math.round(w * work.height / work.width)));
    });
    hIn.addEventListener('input', () => {
        if (!lock.checked) return;
        const h = parseInt(hIn.value, 10);
        if (h > 0) wIn.value = String(Math.max(1, Math.round(h * work.width / work.height)));
    });

    // ---- Toolbar ----
    const mkTool = (iconName, label, handler) => {
        const b = iconBtn(iconName, label, 'btn', 'btn-secondary', 'btn-sm');
        b.addEventListener('click', handler);
        return b;
    };
    const cropBtn = mkTool('crop-simple', t('GRAFIDA_BTN_CROP'), () => {
        cropping = !cropping;
        stage.classList.toggle('cropping', cropping);
        cropBtn.classList.toggle('active', cropping);
        if (!cropping) clearSelection();
    });
    const applyCropBtn = mkTool('check', t('GRAFIDA_BTN_APPLY_CROP'), applyCrop);

    const transformRow = el('div', 'img-editor-row',
        mkTool('rotate-left', t('GRAFIDA_BTN_ROTATE_LEFT'), () => rotate(270)),
        mkTool('rotate-right', t('GRAFIDA_BTN_ROTATE_RIGHT'), () => rotate(90)),
        mkTool('arrows-left-right', t('GRAFIDA_BTN_FLIP_H'), () => flip(true)),
        mkTool('arrows-up-down', t('GRAFIDA_BTN_FLIP_V'), () => flip(false)),
        cropBtn, applyCropBtn,
    );

    const resizeBtn = mkTool('expand', t('GRAFIDA_BTN_RESIZE'), applyResize);
    const lockLabel = el('label', 'img-editor-lock', lock, txt(' ' + t('GRAFIDA_LBL_LOCK_ASPECT')));
    const resizeRow = el('div', 'img-editor-row',
        el('span', 'img-editor-field', el('label', null, t('GRAFIDA_LBL_WIDTH')), wIn),
        el('span', 'img-editor-field', el('label', null, t('GRAFIDA_LBL_HEIGHT')), hIn),
        lockLabel, resizeBtn,
    );

    const hint = el('div', 'img-editor-hint', t('GRAFIDA_MSG_CROP_HINT'));
    const controls = el('div', 'img-editor-controls', transformRow, resizeRow, el('div', 'img-editor-statusbar', dims, hint));
    const body = el('div', 'img-editor', stage, controls);

    // ---- Footer (Save / Cancel) ----
    const cleanup = () => {
        window.removeEventListener('mousemove', onStageDrag);
        window.removeEventListener('mouseup', onStageDragEnd);
    };
    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    cancelBtn.addEventListener('click', () => { cleanup(); closeModal(); });
    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
    saveBtn.addEventListener('click', () => {
        const ext = mediaEntryExt(entry);
        const mime = ext === 'png' ? 'image/png' : ext === 'webp' ? 'image/webp' : 'image/jpeg';
        saveBtn.disabled = true;
        work.toBlob(async (blob) => {
            if (!blob) { saveBtn.disabled = false; showToast(t('GRAFIDA_MSG_MEDIA_EDIT_LOAD_FAIL'), 'error'); return; }
            const dataBase64 = await blobToBase64(blob);
            try {
                await api.updateMediaContent(State.currentSiteId, { path: entry.path, dataBase64 });
                cleanup();
                closeModal();
                showToast(t('GRAFIDA_MSG_MEDIA_SAVED'), 'success');
                reloadMediaManager(State.mediaPath);
            } catch (err) {
                saveBtn.disabled = false;
                showToast(err.message, 'error');
            }
        }, mime, 0.92);
    });

    showModal(t('GRAFIDA_LBL_IMAGE_EDITOR'), body, [cancelBtn, saveBtn]);
    render();
}

/** Reads a Blob into a bare base64 string (no data: prefix). */
function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = String(reader.result || '');
            const comma = result.indexOf(',');
            resolve(comma >= 0 ? result.slice(comma + 1) : result);
        };
        reader.onerror = () => reject(new Error('Could not read image data'));
        reader.readAsDataURL(blob);
    });
}

// --------------------------------------------------------
//  Alias (URL slug)
// --------------------------------------------------------

/**
 * Turn a string into a URL-safe alias, mirroring Joomla's
 * ApplicationHelper::stringUrlSafe(), which picks one of two algorithms based
 * on the site's "Unicode Aliases" Global Configuration option (`unicodeslugs`)
 * — hence the flag, read from the site's cached configuration. When nothing
 * usable survives either algorithm, Joomla falls back to a timestamp — so do
 * we, keeping the same Y-m-d-H-i-s shape. The published article is re-slugified
 * by Joomla anyway, so this only needs to be a faithful preview of the result.
 */
function makeAlias(text) {
    const str = aliasSlug(text, !!(State.references && State.references.unicodeSlugs));

    // Joomla considers an alias of nothing but dashes as empty too.
    if (str.replace(/-/g, '').trim() !== '') return str;

    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
        + `-${p(d.getHours())}-${p(d.getMinutes())}-${p(d.getSeconds())}`;
}

/**
 * The slug itself, without Joomla's empty-result timestamp fallback.
 *
 * Transliterating (`unicodeslugs` off, Joomla's default — OutputFilter::
 * stringURLSafe): dashes become spaces, accented Latin letters are
 * transliterated to ASCII (via Unicode NFKD decomposition + combining-mark
 * stripping, a close approximation of Joomla's default transliterator), the
 * result is lower-cased, every run of whitespace becomes a single dash and any
 * remaining non-[a-z0-9-] character is dropped. A title with no Latin letters
 * at all — "Καλημέρα κόσμε" — survives this as nothing.
 *
 * Unicode (`unicodeslugs` on — OutputFilter::stringUrlUnicodeSlug): the letters
 * are kept as they are, so that title becomes "καλημέρα-κόσμε". Only the
 * characters that would break a URL are replaced by spaces (question marks are
 * dropped outright), the result is lower-cased and each run of spaces becomes a
 * single dash. Note it is *spaces* Joomla collapses here, not whitespace at
 * large, and that it never trims leading/trailing dashes.
 */
function aliasSlug(text, unicodeSlugs) {
    let str = String(text || '');

    if (unicodeSlugs) {
        // Ideographic space (East Asian languages) to a plain one.
        str = str.replace(/　/g, ' ');
        str = str.replace(/-/g, ' ');
        str = str.replace(/[:#*"@+=;!><&.%()\]\/'\\|\[]/g, ' ');
        str = str.replace(/\?/g, '');
        str = str.trim().toLowerCase();

        return str.replace(/ +/g, '-');
    }

    str = str.replace(/-/g, ' ');
    // Transliterate: decompose accented characters and strip the combining
    // diacritical marks (Unicode U+0300–U+036F) that decomposition leaves behind.
    str = str.normalize('NFKD').replace(/[̀-ͯ]/g, '');
    str = str.trim().toLowerCase();
    str = str.replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');

    return str.replace(/^-+|-+$/g, '');
}

/**
 * Regenerate the alias field from the current title. When force is false (the
 * title-blur path) the alias is only filled in if it is currently empty, so a
 * hand-edited alias is never clobbered; the regenerate button passes force.
 */
function regenerateAlias(force) {
    const titleInput = document.getElementById('editor-title-input');
    const aliasInput = document.getElementById('editor-alias-input');
    if (!titleInput || !aliasInput) return;
    if (!force && aliasInput.value.trim() !== '') return;

    aliasInput.value = makeAlias(titleInput.value);
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
    const aliasInputEl = document.getElementById('editor-alias-input');

    return {
        title: titleInputEl ? titleInputEl.value.trim() : '',
        alias: aliasInputEl ? aliasInputEl.value.trim() : '',
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
 * Build the full save payload: the editable form (which now includes the alias)
 * plus the working draft's site/remote link. The article images are part of
 * collectDraftFormData() (the editor's Images section).
 */
function buildDraftSaveBody() {
    const draft = State.currentDraft || {};
    return {
        ...collectDraftFormData(),
        siteId: draft.siteId,
        remoteId: draft.remoteId ?? null,
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

/**
 * Tear down the editor state and go where `after` says — by default back to the
 * articles list. `after` lets a caller (e.g. the Cmd/Ctrl+, Settings shortcut)
 * navigate elsewhere once the editor is safely left.
 */
function leaveEditor(after) {
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

    if (after) {
        after();
    } else {
        showScreen('articles');
        loadArticlesScreen();
    }
}

/**
 * Handle leaving the editor (Back button, or the Settings shortcut): leave
 * straight away when nothing changed (an untouched new or remote draft was
 * never persisted), or prompt to save / keep editing / discard when there are
 * unsaved changes. `after`, when given, is where to go instead of the articles
 * list once the editor is left.
 */
async function handleEditorBack(after) {
    if (isEditorDirty()) {
        showUnsavedChangesDialog(after);
        return;
    }
    leaveEditor(after);
}

function showUnsavedChangesDialog(after) {
    const msgP = el('p', null, t('GRAFIDA_MSG_UNSAVED_CHANGES'));

    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE_AND_BACK'), 'btn', 'btn-success');
    saveBtn.addEventListener('click', async () => {
        try {
            await saveDraft();
        } catch {
            return; // Save failed — keep the editor open so nothing is lost.
        }
        closeModal();
        leaveEditor(after);
    });

    const keepBtn = iconBtn('pen', t('GRAFIDA_BTN_KEEP_EDITING'), 'btn', 'btn-info');
    keepBtn.addEventListener('click', closeModal);

    const discardBtn = iconBtn('trash', t('GRAFIDA_BTN_DISCARD_CHANGES'), 'btn', 'btn-danger');
    discardBtn.addEventListener('click', () => {
        closeModal();
        leaveEditor(after);
    });

    showModal(t('GRAFIDA_MSG_UNSAVED_TITLE'), [msgP], [saveBtn, keepBtn, discardBtn]);
    saveBtn.focus();
}

/** Open the Settings screen (mirrors the sidebar nav-link behaviour). */
function goToSettings() {
    showScreen('settings');
    renderSettingsScreen();
}

/**
 * Navigate to Settings from anywhere. When an article is open in the editor,
 * route through the unsaved-changes flow first so nothing is lost; otherwise go
 * straight there. Backs the Cmd/Ctrl+, keyboard shortcut.
 */
function navigateToSettings() {
    if (State.activeScreen === 'editor') {
        handleEditorBack(goToSettings);
        return;
    }
    goToSettings();
}

/**
 * Recognise the "open Settings" chord: Ctrl/Cmd + comma, with no other
 * modifiers. Matches on both `key` and `keyCode` so it fires regardless of
 * keyboard layout (in the editor iframe the native event is what we get).
 */
function isSettingsShortcut(e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey) return false;
    return e.key === ',' || e.keyCode === 188;
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

async function importMarkdown() {
    let picked;
    try {
        picked = await api.openFile('markdown');
    } catch (err) {
        showToast(err.message, 'error');
        return;
    }
    if (!picked || picked.cancelled) return;
    // The native picker hands the file back as base64; decode it as UTF-8 text.
    const bytes = Uint8Array.from(atob(picked.dataBase64), c => c.charCodeAt(0));
    const text = new TextDecoder().decode(bytes);
    try {
        const result = await api.convertMarkdown(text);
        if (State.tinyMCEEditor) {
            State.tinyMCEEditor.setContent(result.html);
            showToast('Markdown imported.', 'success');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

// --------------------------------------------------------
//  Export / Import .grafida draft files
// --------------------------------------------------------

/** Decodes a native-picker result's base64 bytes as UTF-8 text. */
function decodePickedText(picked) {
    const bytes = Uint8Array.from(atob(picked.dataBase64), c => c.charCodeAt(0));
    return new TextDecoder().decode(bytes);
}

/**
 * Opens the native picker for a `.grafida` file and parses it as JSON.
 * Returns null on cancel or an unparsable file (toasting the error itself).
 */
async function pickGrafidaPayload() {
    let picked;
    try {
        picked = await api.openFile('grafida');
    } catch (err) {
        showToast(err.message, 'error');
        return null;
    }
    if (!picked || picked.cancelled) return null;

    try {
        return JSON.parse(decodePickedText(picked));
    } catch {
        showToast(t('GRAFIDA_MSG_INVALID_GRAFIDA_FILE'), 'error');
        return null;
    }
}

/**
 * Export the currently-open article as a `.grafida` file. The article is
 * saved first — offline images and saved AI chats only exist once it has
 * been persisted locally.
 */
async function exportCurrentDraft() {
    if (!State.currentDraft) return;

    let saved;
    try {
        saved = await saveDraft();
    } catch {
        return;
    }
    if (!saved) return;

    let dir;
    try {
        dir = await api.selectDirectory();
    } catch (err) {
        showToast(err.message, 'error');
        return;
    }
    if (!dir || dir.cancelled) return;

    try {
        const result = await api.exportDraft(saved.id, dir.path);
        showToast(formatText(t('GRAFIDA_MSG_DRAFT_EXPORTED'), result.path), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Import a `.grafida` file as a brand-new local article on the current site. */
async function importDraftAsNew() {
    if (!State.currentSiteId) return;

    const payload = await pickGrafidaPayload();
    if (!payload) return;

    try {
        const created = await api.importDraft(State.currentSiteId, payload);
        State.drafts.push(created);
        showToast(t('GRAFIDA_MSG_DRAFT_IMPORTED'), 'success');
        await openDraftInEditor(created);
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/**
 * Replace the currently-open article's content with an imported `.grafida`
 * file, keeping its own id/site/remote-article linkage untouched. The
 * article is saved first so the replace has a persisted row to act on.
 */
async function replaceDraftFromFile() {
    if (!State.currentDraft) return;

    const payload = await pickGrafidaPayload();
    if (!payload) return;

    const confirmed = await confirmYesNo(
        t('GRAFIDA_MSG_REPLACE_DRAFT_TITLE'),
        [el('p', null, t('GRAFIDA_MSG_REPLACE_DRAFT_CONFIRM'))]
    );
    if (!confirmed) return;

    let saved;
    try {
        saved = await saveDraft();
    } catch {
        return;
    }
    if (!saved) return;

    try {
        const replaced = await api.importDraftInto(saved.id, payload);
        State.currentDraft = replaced;
        State.currentDraftId = replaced.id;
        State.editorForceDirty = false;
        renderEditorSidebar(replaced);
        await initTinyMCE(replaced);
        State.editorBaseline = JSON.stringify(collectDraftFormData());
        if (typeof GrafidaAIPanel !== 'undefined') GrafidaAIPanel.onEditorOpen();
        showToast(t('GRAFIDA_MSG_DRAFT_REPLACED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
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
    renderAiServicesCard();
    loadAiToolsData();
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
    document.querySelectorAll('[data-i18n-title]').forEach(node => {
        const text = t(node.dataset.i18nTitle);
        node.title = text;
        node.setAttribute('aria-label', text);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(node => {
        node.placeholder = t(node.dataset.i18nPlaceholder);
    });
    syncSidebarTooltips();
    renderSiteSelector();
    renderSettingsScreen();
    renderSidebarFooter();
    renderUpdateNotice();
}

// ============================================================
//  AI SETTINGS — Services card
// ============================================================

/** Rebuild the AI Services card from State.aiServices. */
function renderAiServicesCard() {
    const actionsEl = document.getElementById('settings-ai-services-actions');
    if (actionsEl) {
        clearNode(actionsEl);
        const addBtn = iconBtn('plus', t('GRAFIDA_BTN_ADD_AI_SERVICE'), 'btn', 'btn-sm', 'btn-primary');
        addBtn.addEventListener('click', openAddAiServiceModal);
        actionsEl.appendChild(addBtn);
    }

    const list = document.getElementById('ai-services-list');
    if (!list) return;
    clearNode(list);

    if (!State.aiServices.length) {
        list.appendChild(el('p', 'text-muted', t('GRAFIDA_MSG_NO_AI_SERVICES')));
        return;
    }

    State.aiServices.forEach(svc => list.appendChild(buildAiServiceItem(svc)));
}

function buildAiServiceItem(svc) {
    const provPreset = State.aiProviders[svc.provider];
    const provName = provPreset ? provPreset.name : (svc.provider || '—');

    const nameLine = el('div', 'ai-service-name', txt(svc.name));
    if (svc.isDefault) {
        nameLine.appendChild(el('span', 'ai-badge badge-default', t('GRAFIDA_LBL_DEFAULT_AI_SERVICE')));
    }

    const meta = el('div', 'ai-service-meta', provName + ' · ' + (svc.model || '—'));
    const info = el('div', 'ai-service-info', nameLine, meta);

    const editBtn = iconBtn('pen', t('GRAFIDA_BTN_EDIT'), 'btn', 'btn-sm', 'btn-secondary');
    editBtn.addEventListener('click', () => openEditAiServiceModal(svc.id));

    const delBtn = iconBtn('trash', t('GRAFIDA_BTN_DELETE'), 'btn', 'btn-sm', 'btn-danger');
    delBtn.addEventListener('click', () => confirmDeleteAiService(svc.id));

    const actions = el('div', 'ai-service-actions', editBtn, delBtn);

    if (!svc.isDefault) {
        const defBtn = iconBtn('star', t('GRAFIDA_BTN_SET_DEFAULT'), 'btn', 'btn-sm', 'btn-secondary');
        defBtn.addEventListener('click', () => doSetAiServiceDefault(svc.id));
        actions.insertBefore(defBtn, editBtn);
    }

    return el('div', 'ai-service-item', info, actions);
}

/**
 * Build the body nodes for the add/edit AI service modal.
 * @param {object|null} svc — existing service (edit) or null (add)
 */
function buildAiServiceFormBody(svc) {
    // Name
    const nameIn = document.createElement('input');
    nameIn.id = 'modal-ai-svc-name';
    nameIn.type = 'text';
    nameIn.className = 'form-control';
    nameIn.autocomplete = 'off';
    if (svc) nameIn.value = svc.name || '';

    // Provider
    const provSel = document.createElement('select');
    provSel.id = 'modal-ai-svc-provider';
    provSel.className = 'form-control';
    const provBlank = document.createElement('option');
    provBlank.value = '';
    provBlank.textContent = '— ' + t('GRAFIDA_LBL_AI_PROVIDER') + ' —';
    provSel.appendChild(provBlank);
    Object.entries(State.aiProviders).forEach(([k, p]) => {
        const opt = document.createElement('option');
        opt.value = k;
        opt.textContent = p.name;
        if (svc && svc.provider === k) opt.selected = true;
        provSel.appendChild(opt);
    });

    // Endpoint
    const endpIn = document.createElement('input');
    endpIn.id = 'modal-ai-svc-endpoint';
    endpIn.type = 'text';
    endpIn.className = 'form-control';
    endpIn.autocomplete = 'off';
    endpIn.placeholder = 'https://api.example.com';
    if (svc) endpIn.value = svc.endpoint || '';

    // Pre-fill endpoint from provider preset when the endpoint field is blank
    // or still holds the previously-selected provider's preset (so switching
    // providers re-fills it) — but never clobber a hand-edited endpoint — and
    // show/hide the Responses-API-only Store/retention fields (see below).
    let lastPresetEndpoint = (svc && svc.provider) ? (State.aiProviders[svc.provider]?.endpoint || '') : '';
    provSel.addEventListener('change', () => {
        const preset = State.aiProviders[provSel.value];
        const current = endpIn.value.trim();
        if (preset && (current === '' || current === lastPresetEndpoint)) {
            endpIn.value = preset.endpoint || '';
        }
        lastPresetEndpoint = preset ? (preset.endpoint || '') : '';
        updateResponsesParamsVisibility();
    });

    // API key (write-only; password field with keep-existing placeholder in edit mode)
    const keyIn = document.createElement('input');
    keyIn.id = 'modal-ai-svc-key';
    keyIn.type = 'password';
    keyIn.className = 'form-control';
    keyIn.autocomplete = 'new-password';
    if (svc) keyIn.placeholder = t('GRAFIDA_MSG_AI_KEY_PLACEHOLDER');

    // Model: text input (always editable) + "Fetch models" button in edit mode only.
    const modelIn = document.createElement('input');
    modelIn.id = 'modal-ai-svc-model';
    modelIn.type = 'text';
    modelIn.className = 'form-control';
    modelIn.autocomplete = 'off';
    if (svc) modelIn.value = svc.model || '';

    const modelGroup = document.createElement('div');
    modelGroup.className = 'form-group';
    const modelLabel = document.createElement('label');
    modelLabel.textContent = t('GRAFIDA_LBL_AI_MODEL');
    modelGroup.appendChild(modelLabel);

    if (svc) {
        // Edit mode: wrap input + fetch button in a row.
        const fetchBtn = iconBtn('rotate', t('GRAFIDA_BTN_FETCH_MODELS'), 'btn', 'btn-secondary', 'btn-sm');
        fetchBtn.id = 'btn-ai-fetch-models';
        fetchBtn.addEventListener('click', () => fetchAndShowModels(svc.id));
        const modelRow = el('div', 'ai-model-row', modelIn, fetchBtn);
        modelGroup.appendChild(modelRow);
    } else {
        modelGroup.appendChild(modelIn);
    }

    // Insecure-store warning (shown when the OS keychain is unavailable)
    const insecureWarn = !State.secureStoreAi
        ? el('p', 'alert alert-warning', icon('triangle-exclamation'), txt(' ' + t('GRAFIDA_MSG_AI_INSECURE_WARNING')))
        : null;

    // Params
    const existParams = (svc && svc.params) ? svc.params : {};

    const tempIn = document.createElement('input');
    tempIn.id = 'modal-ai-param-temp';
    tempIn.type = 'number';
    tempIn.className = 'form-control';
    tempIn.step = '0.1'; tempIn.min = '0'; tempIn.max = '2';
    tempIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.temperature !== undefined) tempIn.value = String(existParams.temperature);

    const topPIn = document.createElement('input');
    topPIn.id = 'modal-ai-param-topp';
    topPIn.type = 'number';
    topPIn.className = 'form-control';
    topPIn.step = '0.05'; topPIn.min = '0'; topPIn.max = '1';
    topPIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.top_p !== undefined) topPIn.value = String(existParams.top_p);

    const maxTokIn = document.createElement('input');
    maxTokIn.id = 'modal-ai-param-maxtok';
    maxTokIn.type = 'number';
    maxTokIn.className = 'form-control';
    maxTokIn.step = '1'; maxTokIn.min = '1';
    maxTokIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.max_completion_tokens !== undefined) maxTokIn.value = String(existParams.max_completion_tokens);

    const streamSel = document.createElement('select');
    streamSel.id = 'modal-ai-param-stream';
    streamSel.className = 'form-control';
    [['', t('GRAFIDA_OPT_AUTO')], ['1', t('GRAFIDA_BTN_YES')], ['0', t('GRAFIDA_BTN_NO')]].forEach(([v, l]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        streamSel.appendChild(o);
    });
    if (existParams.stream !== undefined) streamSel.value = existParams.stream ? '1' : '0';

    // Multimodal. Unlike `stream`/`store` this defaults OFF: most models are
    // text-only and reject an image part outright, so it cannot be inferred —
    // the user tells us their model can see.
    const multimodalSel = document.createElement('select');
    multimodalSel.id = 'modal-ai-param-multimodal';
    multimodalSel.className = 'form-control';
    [['0', t('GRAFIDA_BTN_NO')], ['1', t('GRAFIDA_BTN_YES')]].forEach(([v, l]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        multimodalSel.appendChild(o);
    });
    multimodalSel.value = existParams.multimodal ? '1' : '0';

    const multimodalGroup = formGroup(t('GRAFIDA_LBL_AI_MULTIMODAL'), multimodalSel);
    multimodalGroup.appendChild(el('p', 'form-hint', t('GRAFIDA_MSG_AI_MULTIMODAL_HINT')));

    // Store + retention (Responses-API providers only: unset store means ON,
    // unset store_retention_days means 15 — see app-level docs).
    const storeSel = document.createElement('select');
    storeSel.id = 'modal-ai-param-store';
    storeSel.className = 'form-control';
    [['', t('GRAFIDA_OPT_AUTO')], ['1', t('GRAFIDA_BTN_YES')], ['0', t('GRAFIDA_BTN_NO')]].forEach(([v, l]) => {
        const o = document.createElement('option');
        o.value = v; o.textContent = l;
        storeSel.appendChild(o);
    });
    if (existParams.store !== undefined) storeSel.value = existParams.store ? '1' : '0';

    const retentionIn = document.createElement('input');
    retentionIn.id = 'modal-ai-param-store-days';
    retentionIn.type = 'number';
    retentionIn.className = 'form-control';
    retentionIn.step = '1'; retentionIn.min = '1';
    retentionIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.store_retention_days !== undefined) {
        retentionIn.value = String(existParams.store_retention_days);
    }

    const storeGroup = formGroup(t('GRAFIDA_LBL_AI_STORE'), storeSel);
    const retentionGroup = formGroup(t('GRAFIDA_LBL_AI_STORE_DAYS'), retentionIn);

    // Gate on the dialect (not the provider key) so this covers both `openai`
    // and `custom_responses` — and any future Responses-API provider — with no
    // extra code. State.aiProviders is the raw snake_case providers.json map.
    function updateResponsesParamsVisibility() {
        const isResponses = State.aiProviders[provSel.value]?.sse_dialect === 'openai_responses';
        storeGroup.classList.toggle('hidden', !isResponses);
        retentionGroup.classList.toggle('hidden', !isResponses);
    }
    updateResponsesParamsVisibility();

    const nodes = [
        formGroup(t('GRAFIDA_LBL_AI_NAME'), nameIn),
        formGroup(t('GRAFIDA_LBL_AI_PROVIDER'), provSel),
        formGroup(t('GRAFIDA_LBL_AI_ENDPOINT'), endpIn),
        formGroup(t('GRAFIDA_LBL_AI_KEY'), keyIn),
        modelGroup,
    ];

    if (insecureWarn) nodes.push(insecureWarn);

    nodes.push(
        el('p', 'card-title', t('GRAFIDA_LBL_AI_PARAMS')),
        formGroup(t('GRAFIDA_LBL_AI_TEMPERATURE'), tempIn),
        formGroup(t('GRAFIDA_LBL_AI_TOP_P'), topPIn),
        formGroup(t('GRAFIDA_LBL_AI_MAX_TOKENS'), maxTokIn),
        formGroup(t('GRAFIDA_LBL_AI_STREAM'), streamSel),
        multimodalGroup,
        storeGroup,
        retentionGroup,
    );

    return nodes;
}

function buildAiServiceFormFooter(saveHandler) {
    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    cancelBtn.addEventListener('click', closeModal);
    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
    saveBtn.addEventListener('click', saveHandler);
    return [cancelBtn, saveBtn];
}

function openAddAiServiceModal() {
    const body = buildAiServiceFormBody(null);
    const footer = buildAiServiceFormFooter(() => saveAiServiceHandler(null));
    showModal(t('GRAFIDA_BTN_ADD_AI_SERVICE'), body, footer);
}

function openEditAiServiceModal(id) {
    const svc = State.aiServices.find(s => s.id === id);
    if (!svc) return;
    const body = buildAiServiceFormBody(svc);
    const footer = buildAiServiceFormFooter(() => saveAiServiceHandler(id));
    showModal(t('GRAFIDA_BTN_EDIT'), body, footer);
}

async function saveAiServiceHandler(id) {
    const nameEl = document.getElementById('modal-ai-svc-name');
    const provEl = document.getElementById('modal-ai-svc-provider');
    const endpEl = document.getElementById('modal-ai-svc-endpoint');
    const keyEl = document.getElementById('modal-ai-svc-key');
    const modelEl = document.getElementById('modal-ai-svc-model');
    const tempEl = document.getElementById('modal-ai-param-temp');
    const topPEl = document.getElementById('modal-ai-param-topp');
    const maxTokEl = document.getElementById('modal-ai-param-maxtok');
    const streamEl = document.getElementById('modal-ai-param-stream');
    const multimodalEl = document.getElementById('modal-ai-param-multimodal');
    const storeEl = document.getElementById('modal-ai-param-store');
    const retentionEl = document.getElementById('modal-ai-param-store-days');

    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) {
        showToast(t('GRAFIDA_LBL_AI_NAME') + ' is required.', 'error');
        return;
    }

    const params = {};
    if (tempEl && tempEl.value !== '') params.temperature = parseFloat(tempEl.value);
    if (topPEl && topPEl.value !== '') params.top_p = parseFloat(topPEl.value);
    if (maxTokEl && maxTokEl.value !== '') params.max_completion_tokens = parseInt(maxTokEl.value, 10);
    if (streamEl && streamEl.value !== '') params.stream = streamEl.value === '1';
    // Only stored when on: an absent param already means "text only".
    if (multimodalEl && multimodalEl.value === '1') params.multimodal = true;
    if (storeEl && storeEl.value !== '') params.store = storeEl.value === '1';
    if (retentionEl && retentionEl.value !== '') params.store_retention_days = parseInt(retentionEl.value, 10);

    const body = {
        name,
        provider: provEl ? provEl.value : '',
        endpoint: endpEl ? endpEl.value.trim() : '',
        model: modelEl ? (modelEl.value || '').trim() : '',
        params,
    };
    const key = keyEl ? keyEl.value.trim() : '';
    if (key) body.key = key;

    try {
        if (id === null) {
            await createAiServiceWithInsecureFallback(body);
        } else {
            body.allowInsecure = false;
            await api.updateAiService(id, body);
        }
        closeModal();
        const svcs = await api.listAiServices();
        State.aiServices = svcs;
        renderAiServicesCard();
        showToast(t('GRAFIDA_MSG_AI_SERVICE_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function createAiServiceWithInsecureFallback(body) {
    body.allowInsecure = false;
    try {
        await api.createAiService(body);
    } catch (err) {
        if (err.code === 'secure_store_unavailable') {
            const ok = await confirmYesNo(
                t('GRAFIDA_LBL_AI_SERVICES'),
                [el('p', null, t('GRAFIDA_MSG_AI_INSECURE_WARNING'))]
            );
            if (!ok) return; // User cancelled; do not close the app for AI services.
            body.allowInsecure = true;
            await api.createAiService(body);
        } else {
            throw err;
        }
    }
}

async function confirmDeleteAiService(id) {
    const svc = State.aiServices.find(s => s.id === id);
    if (!svc) return;
    const nameStrong = el('strong', null, svc.name || '');
    const msgP = el('p', null, ...formatNodes(t('GRAFIDA_MSG_DELETE_AI_SERVICE_CONFIRM'), nameStrong));
    const ok = await confirmYesNo(t('GRAFIDA_BTN_DELETE'), [msgP]);
    if (!ok) return;
    try {
        await api.deleteAiService(id);
        const svcs = await api.listAiServices();
        State.aiServices = svcs;
        renderAiServicesCard();
        showToast(t('GRAFIDA_MSG_AI_SERVICE_DELETED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function doSetAiServiceDefault(id) {
    try {
        await api.setAiServiceDefault(id);
        const svcs = await api.listAiServices();
        State.aiServices = svcs;
        State.aiDefaultServiceId = id;
        renderAiServicesCard();
        showToast(t('GRAFIDA_MSG_AI_DEFAULT_SET'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/**
 * Fetch the model list for an existing service via the proxy and replace the
 * model text input with a populated select element.
 */
async function fetchAndShowModels(serviceId) {
    if (!serviceId) return;
    const fetchBtn = document.getElementById('btn-ai-fetch-models');
    if (fetchBtn) fetchBtn.disabled = true;

    try {
        const svc = State.aiServices.find(s => s.id === serviceId);
        if (!svc) return;

        const preset = State.aiProviders[svc.provider];
        const modelsPath = preset ? preset.models_path : null;
        if (!modelsPath) {
            showToast(t('GRAFIDA_MSG_AI_MODELS_FAIL'), 'warning');
            return;
        }

        // Resolve the service config (including the stored API key).
        const resolved = await api.resolvedAiService(serviceId, '');
        const modelsUrl = resolved.endpoint.replace(/\/+$/, '') + modelsPath;
        const authVal = resolved.authHeader === 'Authorization'
            ? 'Bearer ' + resolved.apiKey
            : resolved.apiKey;

        const proxyRes = await api.aiProxy({
            serviceId,
            url: modelsUrl,
            method: 'GET',
            headers: { [resolved.authHeader]: authVal },
            body: '',
        });

        // api.aiProxy resolves to { status, body } where body is the raw JSON
        // *string* from the provider (mirrors sendChat()'s proxy path) — it is
        // never { data: [...] } directly.
        if (proxyRes.status < 200 || proxyRes.status >= 300) {
            let errMsg = 'HTTP ' + proxyRes.status;
            try {
                const j = JSON.parse(proxyRes.body);
                errMsg = j.error?.message || j.message || errMsg;
            } catch {}
            showToast(t('GRAFIDA_MSG_AI_MODELS_FAIL') + ' — ' + errMsg, 'error');
            return;
        }

        let parsed;
        try {
            parsed = JSON.parse(proxyRes.body);
        } catch {
            showToast(t('GRAFIDA_MSG_AI_MODELS_FAIL'), 'warning');
            return;
        }

        // OpenAI-style response: { data: [{id, ...}, ...] }; some providers use
        // { models: [...] }; GitHub's /catalog/models returns a bare array.
        const rawList = Array.isArray(parsed) ? parsed : (parsed.data || parsed.models || []);
        const models = rawList
            .map(m => (typeof m === 'string' ? m : (m.id || '')))
            .filter(Boolean)
            .sort();

        if (!models.length) {
            showToast(t('GRAFIDA_MSG_AI_MODELS_FAIL'), 'warning');
            return;
        }

        const modelEl = document.getElementById('modal-ai-svc-model');
        if (!modelEl) return;
        const current = modelEl.value || '';

        const modelSel = document.createElement('select');
        modelSel.id = 'modal-ai-svc-model';
        modelSel.className = 'form-control';

        // Prepend the current value if it is not in the fetched list.
        if (current && !models.includes(current)) {
            const opt = document.createElement('option');
            opt.value = current; opt.textContent = current; opt.selected = true;
            modelSel.appendChild(opt);
        }

        models.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m; opt.textContent = m;
            if (m === current) opt.selected = true;
            modelSel.appendChild(opt);
        });

        modelEl.replaceWith(modelSel);
    } catch (err) {
        showToast(t('GRAFIDA_MSG_AI_MODELS_FAIL') + ' — ' + err.message, 'error');
    } finally {
        if (fetchBtn) fetchBtn.disabled = false;
    }
}

// ============================================================
//  AI SETTINGS — Tools card
// ============================================================

/**
 * Fetch the full tool list (all tools including disabled), system prompt and
 * tone map from the API, update State, then render the tools card.
 */
async function loadAiToolsData() {
    const list = document.getElementById('ai-tools-list');
    if (list) {
        clearNode(list);
        list.appendChild(el('p', 'text-muted', t('GRAFIDA_MSG_LOADING')));
    }

    try {
        const data = await api.listAiTools();
        State.aiAllTools = data.tools || [];
        State.aiSystemPrompt = data.systemPrompt || '';
        State.aiTones = data.tones || {};
    } catch (err) {
        if (list) {
            clearNode(list);
            list.appendChild(el('p', 'text-muted', err.message));
        }
        return;
    }

    buildSystemPromptSection();
    renderAiToolsList();
}

/** Rebuild the system-prompt sub-section from State.aiSystemPrompt. */
function buildSystemPromptSection() {
    const section = document.getElementById('ai-system-prompt-section');
    if (!section) return;
    clearNode(section);

    const ta = document.createElement('textarea');
    ta.id = 'ai-system-prompt-textarea';
    ta.className = 'form-control';
    ta.rows = 5;
    ta.value = State.aiSystemPrompt || '';

    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary', 'btn-sm');
    saveBtn.addEventListener('click', saveSystemPrompt);

    const restoreBtn = iconBtn('rotate-left', t('GRAFIDA_BTN_RESTORE_DEFAULT'), 'btn', 'btn-secondary', 'btn-sm');
    restoreBtn.addEventListener('click', restoreSystemPromptDefault);

    section.appendChild(formGroup(t('GRAFIDA_LBL_AI_SYSTEM_PROMPT'), ta));
    section.appendChild(el('div', 'ai-system-prompt-actions', saveBtn, restoreBtn));
}

async function saveSystemPrompt() {
    const ta = document.getElementById('ai-system-prompt-textarea');
    if (!ta) return;
    try {
        const result = await api.setSystemPrompt({ prompt: ta.value });
        State.aiSystemPrompt = result.systemPrompt || '';
        showToast(t('GRAFIDA_MSG_AI_SYSTEM_PROMPT_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function restoreSystemPromptDefault() {
    try {
        const result = await api.setSystemPrompt({ prompt: '' });
        State.aiSystemPrompt = result.systemPrompt || '';
        const ta = document.getElementById('ai-system-prompt-textarea');
        if (ta) ta.value = State.aiSystemPrompt;
        showToast(t('GRAFIDA_MSG_AI_SYSTEM_PROMPT_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Render the list of all tools (including disabled) from State.aiAllTools. */
function renderAiToolsList() {
    const list = document.getElementById('ai-tools-list');
    if (!list) return;
    clearNode(list);

    const header = el('div', 'ai-tools-header');
    const addBtn = iconBtn('plus', t('GRAFIDA_BTN_ADD_AI_TOOL'), 'btn', 'btn-sm', 'btn-primary');
    addBtn.addEventListener('click', openAddAiToolModal);
    header.appendChild(addBtn);
    list.appendChild(header);

    if (!State.aiAllTools.length) {
        list.appendChild(el('p', 'text-muted', t('GRAFIDA_MSG_NO_AI_TOOLS')));
        return;
    }

    State.aiAllTools.forEach(tool => list.appendChild(buildAiToolItem(tool)));
}

function buildAiToolItem(tool) {
    // Icon + title
    const titleEl = el('div', 'ai-tool-title');
    if (tool.icon) {
        const ico = el('i', 'fa-solid fa-' + tool.icon);
        ico.setAttribute('aria-hidden', 'true');
        titleEl.appendChild(ico);
        titleEl.appendChild(txt(' '));
    }
    titleEl.appendChild(txt(tool.title || tool.toolKey));
    if (tool.isCustom) {
        titleEl.appendChild(el('span', 'ai-badge badge-remote', t('GRAFIDA_LBL_AI_CUSTOM_TOOL')));
    }

    // Meta: tone and/or service name
    const metaParts = [];
    if (tool.tone) metaParts.push(tool.tone);
    if (tool.serviceId) {
        const svc = State.aiServices.find(s => s.id === tool.serviceId);
        if (svc) metaParts.push(svc.name);
    }
    const metaEl = el('div', 'ai-tool-meta', metaParts.join(' · ') || '—');
    const info = el('div', 'ai-tool-info', titleEl, metaEl);

    // Enable/disable toggle
    const toggleBtn = iconBtn(
        tool.enabled ? 'toggle-on' : 'toggle-off',
        tool.enabled ? t('GRAFIDA_OPT_PUBLISHED') : t('GRAFIDA_OPT_UNPUBLISHED'),
        'btn', 'btn-sm', tool.enabled ? 'btn-info' : 'btn-secondary'
    );
    toggleBtn.addEventListener('click', () => toggleAiTool(tool));

    const editBtn = iconBtn('pen', t('GRAFIDA_BTN_EDIT'), 'btn', 'btn-sm', 'btn-secondary');
    editBtn.addEventListener('click', () => openAiToolModal(tool));

    const actions = el('div', 'ai-tool-actions', toggleBtn, editBtn);

    // Show delete for custom tools or built-in tools that have a DB override.
    if (tool.isCustom || tool.id !== null) {
        const delBtn = iconBtn('trash', t('GRAFIDA_BTN_DELETE'), 'btn', 'btn-sm', 'btn-danger');
        delBtn.addEventListener('click', () => confirmDeleteAiTool(tool));
        actions.appendChild(delBtn);
    }

    const item = el('div', 'ai-tool-item', info, actions);
    if (!tool.enabled) item.classList.add('ai-tool-disabled');
    return item;
}

async function toggleAiTool(tool) {
    try {
        await api.updateAiTool(tool.toolKey, { enabled: !tool.enabled });
        const data = await api.listAiTools();
        State.aiAllTools = data.tools || [];
        renderAiToolsList();
    } catch (err) {
        showToast(err.message, 'error');
    }
}

/** Build the add/edit tool modal body. Pass null for tool to create a new custom tool. */
function buildAiToolFormBody(tool) {
    const nodes = [];

    // Key field — only for new custom tools.
    if (!tool) {
        const keyIn = document.createElement('input');
        keyIn.id = 'modal-ai-tool-key';
        keyIn.type = 'text';
        keyIn.className = 'form-control';
        keyIn.autocomplete = 'off';
        keyIn.placeholder = 'my_tool_key';
        nodes.push(formGroup(t('GRAFIDA_LBL_AI_TOOL_KEY'), keyIn));
    }

    // Title
    const titleIn = document.createElement('input');
    titleIn.id = 'modal-ai-tool-title';
    titleIn.type = 'text';
    titleIn.className = 'form-control';
    titleIn.autocomplete = 'off';
    if (tool) titleIn.value = tool.title || tool.toolKey || '';

    // Icon (FA solid icon name, without the "fa-" prefix)
    const iconIn = iconPicker('modal-ai-tool-icon', tool ? (tool.icon || '') : '');

    // Prompt
    const promptTa = document.createElement('textarea');
    promptTa.id = 'modal-ai-tool-prompt';
    promptTa.className = 'form-control';
    promptTa.rows = 4;
    if (tool) promptTa.value = tool.prompt || '';

    // Tone dropdown
    const toneSel = document.createElement('select');
    toneSel.id = 'modal-ai-tool-tone';
    toneSel.className = 'form-control';
    const toneBlank = document.createElement('option');
    toneBlank.value = '';
    toneBlank.textContent = t('GRAFIDA_OPT_AI_TONE_DEFAULT');
    toneSel.appendChild(toneBlank);
    Object.entries(State.aiTones).forEach(([k, tone]) => {
        const opt = document.createElement('option');
        opt.value = k;
        opt.textContent = tone.label || k;
        if (tool && tool.tone === k) opt.selected = true;
        toneSel.appendChild(opt);
    });

    // Override system prompt checkbox
    const overrideChk = document.createElement('input');
    overrideChk.id = 'modal-ai-tool-override-sys';
    overrideChk.type = 'checkbox';
    if (tool && tool.overrideSystem) overrideChk.checked = true;
    const overrideLbl = el('label', 'toggle-row', overrideChk, txt(' ' + t('GRAFIDA_LBL_AI_OVERRIDE_SYSTEM')));
    overrideLbl.htmlFor = 'modal-ai-tool-override-sys';

    // Service override dropdown
    const svcSel = document.createElement('select');
    svcSel.id = 'modal-ai-tool-service';
    svcSel.className = 'form-control';
    const svcDefault = document.createElement('option');
    svcDefault.value = '';
    svcDefault.textContent = t('GRAFIDA_OPT_AI_DEFAULT_SERVICE');
    svcSel.appendChild(svcDefault);
    State.aiServices.forEach(svc => {
        const opt = document.createElement('option');
        opt.value = String(svc.id);
        opt.textContent = svc.name;
        if (tool && tool.serviceId === svc.id) opt.selected = true;
        svcSel.appendChild(opt);
    });

    // Per-tool param overrides
    const existParams = (tool && tool.params) ? tool.params : {};

    const tempIn = document.createElement('input');
    tempIn.id = 'modal-ai-tool-temp';
    tempIn.type = 'number'; tempIn.className = 'form-control';
    tempIn.step = '0.1'; tempIn.min = '0'; tempIn.max = '2';
    tempIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.temperature !== undefined) tempIn.value = String(existParams.temperature);

    const topPIn = document.createElement('input');
    topPIn.id = 'modal-ai-tool-topp';
    topPIn.type = 'number'; topPIn.className = 'form-control';
    topPIn.step = '0.05'; topPIn.min = '0'; topPIn.max = '1';
    topPIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.top_p !== undefined) topPIn.value = String(existParams.top_p);

    const maxTokIn = document.createElement('input');
    maxTokIn.id = 'modal-ai-tool-maxtok';
    maxTokIn.type = 'number'; maxTokIn.className = 'form-control';
    maxTokIn.step = '1'; maxTokIn.min = '1';
    maxTokIn.placeholder = t('GRAFIDA_OPT_AUTO');
    if (existParams.max_completion_tokens !== undefined) maxTokIn.value = String(existParams.max_completion_tokens);

    nodes.push(
        formGroup(t('GRAFIDA_LBL_TITLE'), titleIn),
        formGroup(t('GRAFIDA_LBL_AI_TOOL_ICON'), iconIn),
        formGroup(t('GRAFIDA_LBL_AI_TOOL_PROMPT'), promptTa),
        formGroup(t('GRAFIDA_LBL_AI_TONE'), toneSel),
        el('div', 'form-group', overrideLbl),
        formGroup(t('GRAFIDA_LBL_AI_SERVICE_OVERRIDE'), svcSel),
        el('p', 'card-title', t('GRAFIDA_LBL_AI_PARAMS')),
        formGroup(t('GRAFIDA_LBL_AI_TEMPERATURE'), tempIn),
        formGroup(t('GRAFIDA_LBL_AI_TOP_P'), topPIn),
        formGroup(t('GRAFIDA_LBL_AI_MAX_TOKENS'), maxTokIn),
    );

    return nodes;
}

function buildAiToolFormFooter(saveHandler) {
    const cancelBtn = iconBtn('xmark', t('GRAFIDA_BTN_CANCEL'), 'btn', 'btn-secondary');
    cancelBtn.addEventListener('click', closeModal);
    const saveBtn = iconBtn('floppy-disk', t('GRAFIDA_BTN_SAVE'), 'btn', 'btn-primary');
    saveBtn.addEventListener('click', saveHandler);
    return [cancelBtn, saveBtn];
}

function openAddAiToolModal() {
    const body = buildAiToolFormBody(null);
    const footer = buildAiToolFormFooter(() => saveAiToolHandler(null));
    showModal(t('GRAFIDA_BTN_ADD_AI_TOOL'), body, footer);
}

function openAiToolModal(tool) {
    const body = buildAiToolFormBody(tool);
    const footer = buildAiToolFormFooter(() => saveAiToolHandler(tool));
    showModal(t('GRAFIDA_BTN_EDIT'), body, footer);
}

async function saveAiToolHandler(tool) {
    const titleEl = document.getElementById('modal-ai-tool-title');
    const iconEl = document.getElementById('modal-ai-tool-icon');
    const promptEl = document.getElementById('modal-ai-tool-prompt');
    const toneEl = document.getElementById('modal-ai-tool-tone');
    const overrideSysEl = document.getElementById('modal-ai-tool-override-sys');
    const svcEl = document.getElementById('modal-ai-tool-service');
    const tempEl = document.getElementById('modal-ai-tool-temp');
    const topPEl = document.getElementById('modal-ai-tool-topp');
    const maxTokEl = document.getElementById('modal-ai-tool-maxtok');

    const params = {};
    if (tempEl && tempEl.value !== '') params.temperature = parseFloat(tempEl.value);
    if (topPEl && topPEl.value !== '') params.top_p = parseFloat(topPEl.value);
    if (maxTokEl && maxTokEl.value !== '') params.max_completion_tokens = parseInt(maxTokEl.value, 10);

    const serviceIdRaw = svcEl ? svcEl.value : '';
    const serviceId = serviceIdRaw !== '' ? parseInt(serviceIdRaw, 10) : null;

    const body = {
        title: titleEl ? titleEl.value.trim() : '',
        icon: iconEl ? iconEl.value.trim() : '',
        prompt: promptEl ? promptEl.value : '',
        tone: toneEl ? toneEl.value : '',
        overrideSystem: overrideSysEl ? overrideSysEl.checked : false,
        serviceId,
        params,
    };

    try {
        if (tool === null) {
            // Creating a new custom tool.
            const keyEl = document.getElementById('modal-ai-tool-key');
            const toolKey = keyEl ? keyEl.value.trim() : '';
            if (!toolKey) {
                showToast(t('GRAFIDA_LBL_AI_TOOL_KEY') + ' is required.', 'error');
                return;
            }
            body.toolKey = toolKey;
            body.enabled = true;
            await api.createAiTool(body);
        } else {
            await api.updateAiTool(tool.toolKey, body);
        }
        closeModal();
        const data = await api.listAiTools();
        State.aiAllTools = data.tools || [];
        State.aiSystemPrompt = data.systemPrompt || '';
        State.aiTones = data.tones || {};
        renderAiToolsList();
        showToast(t('GRAFIDA_MSG_AI_TOOL_SAVED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function confirmDeleteAiTool(tool) {
    const nameStrong = el('strong', null, tool.title || tool.toolKey || '');
    const msgP = el('p', null, ...formatNodes(t('GRAFIDA_MSG_DELETE_AI_TOOL_CONFIRM'), nameStrong));
    const ok = await confirmYesNo(t('GRAFIDA_BTN_DELETE'), [msgP]);
    if (!ok) return;
    try {
        await api.deleteAiTool(tool.toolKey);
        const data = await api.listAiTools();
        State.aiAllTools = data.tools || [];
        State.aiSystemPrompt = data.systemPrompt || '';
        State.aiTones = data.tones || {};
        renderAiToolsList();
        showToast(t('GRAFIDA_MSG_AI_TOOL_DELETED'), 'success');
    } catch (err) {
        showToast(err.message, 'error');
    }
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
    syncSidebarTooltips();
}

/**
 * Show or hide the "New version available" notice above the version label.
 * The Download button opens the new version's GitHub release page in the
 * user's browser so they can fetch and install the update themselves.
 */
function renderUpdateNotice() {
    const box = document.getElementById('sidebar-update');
    if (!box) return;

    clearNode(box);

    const update = State.update;
    if (!update || !update.available) {
        box.hidden = true;
        return;
    }

    const msg = el('span', 'update-msg', t('GRAFIDA_MSG_UPDATE_AVAILABLE'));

    const downloadBtn = iconBtn('download', t('GRAFIDA_BTN_DOWNLOAD'), 'btn', 'btn-sm', 'btn-success');
    const url = update.infoURL || update.download;
    downloadBtn.disabled = !url;
    downloadBtn.addEventListener('click', async () => {
        if (!url) return;
        try {
            await api.openUrl(url);
        } catch (err) {
            showToast(err.message, 'error');
        }
    });

    box.appendChild(msg);
    box.appendChild(downloadBtn);
    box.hidden = false;
}

/** Asynchronously check for a newer version and surface the notice if one exists. */
async function checkForUpdate() {
    try {
        State.update = await api.checkUpdate();
    } catch (err) {
        // Best-effort: a failed check must never disrupt the app.
        State.update = null;
    }
    renderUpdateNotice();
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

// Maps a Grafida interface language tag (e.g. "fr-FR") to the TinyMCE language
// code of the pack bundled under js/tinymce/langs/. en-GB has no pack — TinyMCE's
// built-in UI is English — so it is intentionally absent (returns null → default).
const TINYMCE_LANGS = {
    'el-GR': 'el',
    'fr-FR': 'fr_FR',
    'de-DE': 'de',
    'es-ES': 'es',
    'it-IT': 'it',
    'pt-PT': 'pt_PT',
};

/** Returns the TinyMCE language code matching the interface language, or null for the English default. */
function editorLanguage() {
    return TINYMCE_LANGS[State.language] || null;
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
        State.aiServices = data.aiServices || [];
        State.aiDefaultServiceId = data.aiDefaultServiceId ?? null;
        State.aiProviders = data.aiProviders || {};
        State.aiTools = data.aiTools || [];
        State.secureStoreAi = data.secureStoreAi !== false;
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

    // Fire-and-forget: the update check runs after the UI is rendered so a slow
    // (or stale-cache-refreshing) request never blocks start-up. The 12-hour
    // cache lives server-side, so calling this on every launch is cheap.
    checkForUpdate();
}

// ============================================================
//  Collapsible sidebars + resizable AI panel
// ============================================================

const SIDEBAR_COLLAPSED_KEY = 'grafida.sidebarCollapsed';
const PROPS_COLLAPSED_KEY = 'grafida.propsCollapsed';
const AI_PANEL_WIDTH_KEY = 'grafida.aiPanelWidth';

/** Toggle a collapsible aside and persist the preference. */
function setupCollapsible(asideId, toggleId, storageKey, onChange) {
    const aside = document.getElementById(asideId);
    const toggle = document.getElementById(toggleId);
    if (!aside) return;

    if (localStorage.getItem(storageKey) === '1') aside.classList.add('collapsed');
    if (onChange) onChange();

    if (toggle) {
        toggle.addEventListener('click', () => {
            const collapsed = aside.classList.toggle('collapsed');
            localStorage.setItem(storageKey, collapsed ? '1' : '0');
            if (onChange) onChange();
        });
    }
}

/**
 * Mirror each sidebar item's label into a tooltip while the rail is collapsed: the
 * labels are hidden then, leaving only an icon. The aria-label is kept either way,
 * since a collapsed item has no accessible name at all without it.
 */
function syncSidebarTooltips() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const collapsed = sidebar.classList.contains('collapsed');
    const label = (node, text) => {
        if (!text) return;
        node.setAttribute('aria-label', text);
        if (collapsed) node.title = text; else node.removeAttribute('title');
    };

    sidebar.querySelectorAll('nav#main-nav a[data-screen]').forEach(link => {
        const span = link.querySelector('span[data-i18n]');
        if (span) label(link, span.textContent);
    });

    const footer = document.getElementById('sidebar-footer');
    const version = document.getElementById('sidebar-version');
    if (footer && version) label(footer, version.textContent || t('GRAFIDA_BTN_ABOUT'));
}

/** Make the AI panel resizable by dragging its left-edge handle. */
function setupAiPanelResize() {
    const panel = document.getElementById('ai-panel');
    const resizer = document.getElementById('ai-panel-resizer');
    if (!panel || !resizer) return;

    const MIN = 280;
    const maxWidth = () => Math.max(MIN, Math.min(window.innerWidth - 360, 760));

    function applyWidth(px) {
        const w = Math.round(Math.max(MIN, Math.min(px, maxWidth())));
        panel.style.width = w + 'px';
        panel.style.minWidth = w + 'px';
        panel.style.maxWidth = w + 'px';
        return w;
    }

    const saved = parseInt(localStorage.getItem(AI_PANEL_WIDTH_KEY) || '', 10);
    if (saved) applyWidth(saved);

    let startX = 0;
    let startW = 0;

    function onMove(e) {
        // The panel sits on the right edge, so dragging left (dx < 0) widens it.
        const dx = e.clientX - startX;
        applyWidth(startW - dx);
    }

    function onUp(e) {
        resizer.releasePointerCapture?.(e.pointerId);
        resizer.removeEventListener('pointermove', onMove);
        resizer.removeEventListener('pointerup', onUp);
        resizer.removeEventListener('pointercancel', onUp);
        document.body.classList.remove('resizing-col');
        localStorage.setItem(AI_PANEL_WIDTH_KEY,
            String(Math.round(panel.getBoundingClientRect().width)));
    }

    resizer.addEventListener('pointerdown', (e) => {
        startX = e.clientX;
        startW = panel.getBoundingClientRect().width;
        resizer.setPointerCapture?.(e.pointerId);
        document.body.classList.add('resizing-col');
        resizer.addEventListener('pointermove', onMove);
        resizer.addEventListener('pointerup', onUp);
        resizer.addEventListener('pointercancel', onUp);
        e.preventDefault();
    });
}

function initLayoutControls() {
    setupCollapsible('sidebar', 'sidebar-toggle', SIDEBAR_COLLAPSED_KEY, syncSidebarTooltips);
    setupCollapsible('editor-sidebar', 'editor-sidebar-toggle', PROPS_COLLAPSED_KEY);
    setupAiPanelResize();
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
            if (screen === 'media') loadMediaScreen();
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
            State.mediaAdapters = null;
            State.mediaSiteId = null;
            if (State.activeScreen === 'articles') loadArticlesScreen();
            if (State.activeScreen === 'media') loadMediaScreen();
        });
    }

    const sidebarFooter = document.getElementById('sidebar-footer');
    if (sidebarFooter) sidebarFooter.addEventListener('click', showAboutDialog);

    const btnAddSite = document.getElementById('btn-add-site');
    if (btnAddSite) btnAddSite.addEventListener('click', openAddSiteModal);

    const btnNewArticle = document.getElementById('btn-new-article');
    if (btnNewArticle) btnNewArticle.addEventListener('click', openNewArticle);

    const btnImportDraft = document.getElementById('btn-import-draft');
    if (btnImportDraft) btnImportDraft.addEventListener('click', importDraftAsNew);

    const btnSaveDraft = document.getElementById('btn-save-draft');
    if (btnSaveDraft) btnSaveDraft.addEventListener('click', saveDraft);

    // Ctrl/Cmd+S saves the open draft from anywhere in the editor screen
    // (metadata sidebar, AI panel, etc.) — not only while TinyMCE has focus,
    // which is handled separately by an editor.addShortcut('meta+s', …) since
    // TinyMCE's iframe has its own document and never sees this listener.
    document.addEventListener('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
        if (State.activeScreen !== 'editor' || !State.currentDraft) return;
        e.preventDefault();
        saveDraft();
    });

    // Ctrl/Cmd+, opens Settings from anywhere. When editing an article this
    // routes through the unsaved-changes prompt first (see navigateToSettings).
    // The editor iframe has its own document and never sees this listener, so
    // an editor.on('keydown', …) in initTinyMCE handles the editor-focused half.
    document.addEventListener('keydown', (e) => {
        if (!isSettingsShortcut(e)) return;
        e.preventDefault();
        navigateToSettings();
    });

    const btnPublish = document.getElementById('btn-publish');
    if (btnPublish) btnPublish.addEventListener('click', publishDraft);

    const btnImportMd = document.getElementById('btn-import-md');
    if (btnImportMd) btnImportMd.addEventListener('click', importMarkdown);

    const btnExportDraft = document.getElementById('btn-export-draft');
    if (btnExportDraft) btnExportDraft.addEventListener('click', exportCurrentDraft);

    const btnReplaceDraft = document.getElementById('btn-replace-draft');
    if (btnReplaceDraft) btnReplaceDraft.addEventListener('click', replaceDraftFromFile);

    const btnBack = document.getElementById('btn-back-to-articles');
    if (btnBack) {
        btnBack.addEventListener('click', () => { handleEditorBack(); });
    }

    // Auto-fill the alias from the title when focus leaves the title and the
    // alias is still empty; the add-on button always regenerates from the title.
    const editorTitleInput = document.getElementById('editor-title-input');
    if (editorTitleInput) {
        editorTitleInput.addEventListener('blur', () => regenerateAlias(false));
    }
    const btnRegenAlias = document.getElementById('btn-regenerate-alias');
    if (btnRegenAlias) {
        btnRegenAlias.addEventListener('click', () => regenerateAlias(true));
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

    initLayoutControls();

    bootstrap();
});
