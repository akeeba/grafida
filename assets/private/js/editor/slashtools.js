/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Slash commands — type "/" in the editor to pick a command without leaving the
 * keyboard (gh-9). Exposes window.GrafidaSlashTools = { register, items }.
 *
 * Ported from Brian Teeman's slashtools TinyMCE plugin
 * (https://github.com/brianteeman/slashtools, GPLv3), which ships as a Joomla
 * extension loaded through TinyMCE's "External Plugin URLs" setting. Neither
 * mechanism exists here: assets/private/js/tinymce/ is npm-vendored and
 * gitignored, so a plugin file dropped in there would be untracked and wiped by
 * the next `vendor:assets`, and Grafida sets no `external_plugins`. So this is a
 * port: a plain IIFE loaded AFTER app.js, registering an autocompleter from
 * initTinyMCE()'s setup callback the way every other custom editor feature here
 * is registered.
 *
 * Two deliberate departures from upstream, both explained at their call site:
 * the placeholder images are PNG rather than SVG, and item labels are localised
 * while filtering still matches the English keywords.
 *
 * Relies on globals app.js places in the window scope (resolved at call time):
 *   State, t, escapeHtmlText, openMediaBrowser, openSourceCodeEditor, insertReadMore
 */

'use strict';

(function (global) {
    /* global State, t, escapeHtmlText, openMediaBrowser, openSourceCodeEditor, insertReadMore */

    // -------------------------------------------------------------------------
    //  Dummy text
    // -------------------------------------------------------------------------

    // Kept Latin on purpose, whatever the interface language: lorem ipsum is a
    // universal convention and is meant to read as "not real text", which text
    // in the reader's own language does not.
    const LOREM_SENTENCE = 'Lorem ipsum dolor sit, amet consectetur adipisicing elit.';

    const LOREM_PARAGRAPH = 'Lorem ipsum dolor sit, amet consectetur adipisicing elit. ' +
        'Asperiores sint officiis dolore vitae facilis praesentium non? Recusandae magni ' +
        'ipsa debitis quam animi libero minus enim possimus exercitationem? Architecto ' +
        'nobis eos, repudiandae ullam ex quos laborum commodi maiores reiciendis, omnis ' +
        'recusandae. Asperiores, est? Aliquid, beatae nisi? Ea sunt iusto inventore magni ' +
        'provident dolorem sint, maxime obcaecati illum delectus';

    // -------------------------------------------------------------------------
    //  Placeholder images
    // -------------------------------------------------------------------------

    /**
     * Draws a grey placeholder box captioned with its own size, as a data: PNG.
     *
     * Upstream inserts an SVG placeholder, which Grafida cannot use: publishing
     * uploads *every* inline data: image to the site's Media Manager
     * (Html\InlineMedia::rewriteDataImages), and Joomla's Media Manager rejects
     * SVG by default — so an SVG placeholder left in an article would abort the
     * publish outright. A PNG survives that path.
     *
     * Drawn on a canvas rather than held here as a base64 literal: it keeps a
     * multi-KB blob out of the source and the caption honest for any size.
     *
     * @param   {number}  w  Width in pixels.
     * @param   {number}  h  Height in pixels.
     * @returns {string}     A data: URI, or '' if the canvas is unavailable.
     */
    function placeholderPng(w, h) {
        const canvas = document.createElement('canvas');
        canvas.width  = w;
        canvas.height = h;

        const ctx = canvas.getContext('2d');
        if (!ctx) return '';

        ctx.fillStyle = '#dedede';
        ctx.fillRect(0, 0, w, h);

        ctx.strokeStyle = '#555555';
        ctx.lineWidth   = 2;
        ctx.strokeRect(1, 1, w - 2, h - 2);

        ctx.fillStyle    = '#555555';
        ctx.font         = '18px monospace';
        ctx.textAlign    = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(w + '×' + h, w / 2, h / 2);

        return canvas.toDataURL('image/png');
    }

    /** Inserts a placeholder image of the given size at the cursor. */
    function insertPlaceholder(editor, w, h) {
        const uri = placeholderPng(w, h);

        if (!uri) {
            // The typed "/pattern" has already been deleted by now, so failing
            // silently would look exactly like a broken command.
            editor.notificationManager.open({
                text: t('GRAFIDA_MSG_SLASH_PLACEHOLDER_FAILED'),
                type: 'warning',
                timeout: 3000,
            });
            return;
        }

        // Built through editor.dom.createHTML rather than string concatenation:
        // it escapes attribute values properly, where escapeHtmlText() would not
        // (it serialises a text node, so it leaves a double quote alone — which
        // in an alt="…" would close the attribute early).
        editor.insertContent(editor.dom.createHTML('img', {
            src: uri,
            alt: t('GRAFIDA_LBL_SLASH_PLACEHOLDER_ALT'),
            width: w,
            height: h,
        }));
    }

    // -------------------------------------------------------------------------
    //  Icons
    // -------------------------------------------------------------------------

    /**
     * TinyMCE 7's icon pack has no heading icons at all — upstream's "h1"/"h2"/
     * "h3" names silently fall back to a placeholder glyph — so draw our own.
     */
    function headingIcon(level) {
        return '<svg width="24" height="24" viewBox="0 0 24 24">' +
            '<text x="12" y="16" text-anchor="middle" fill="currentColor" ' +
            'font-family="sans-serif" font-size="13" font-weight="bold">H' + level + '</text></svg>';
    }

    // -------------------------------------------------------------------------
    //  The command list
    // -------------------------------------------------------------------------

    /**
     * Builds the menu.
     *
     * Each entry is either `{ type: 'separator' }` or a command:
     *   key       the UI string key; the label is resolved at fetch() time, so a
     *             language switch needs no re-registration;
     *   keywords  English words the pattern also matches, so "/head" still finds
     *             the headings on a translated interface;
     *   icon      an icon name registered with the editor;
     *   action    what to run once picked.
     *
     * Anything built out of t() is escaped before it becomes markup: a
     * translation is free to contain an "&" or a quote, which would otherwise
     * corrupt the inserted HTML.
     *
     * @param {Object} editor   the TinyMCE editor instance
     * @param {Object} options  { siteId } — the site the media browser browses
     */
    function buildItems(editor, options) {
        const heading = (level) => ({
            key: 'GRAFIDA_LBL_SLASH_H' + level,
            keywords: ['heading', 'h' + level, 'title'],
            icon: 'grafida-h' + level,
            action: () => {
                const label = escapeHtmlText(t('GRAFIDA_LBL_SLASH_H' + level));
                editor.insertContent('<h' + level + '>' + label + '</h' + level + '>');
                editor.selection.select(editor.selection.getNode());
            },
        });

        const list = (tag, key) => ({
            key: key,
            keywords: tag === 'ol' ? ['ordered', 'numbered', 'list'] : ['bulleted', 'unordered', 'list'],
            icon: tag === 'ol' ? 'ordered-list' : 'unordered-list',
            action: () => {
                // Upstream inserts a <ul> for BOTH lists; an ordered list must be <ol>.
                const item = escapeHtmlText(t('GRAFIDA_LBL_SLASH_LIST_ITEM'));
                editor.insertContent('<' + tag + '><li>' + item + '</li></' + tag + '>');
                editor.selection.select(editor.selection.getNode());
            },
        });

        return [
            heading(1),
            heading(2),
            heading(3),
            { type: 'separator' },
            list('ul', 'GRAFIDA_LBL_SLASH_BULLET_LIST'),
            list('ol', 'GRAFIDA_LBL_SLASH_ORDERED_LIST'),
            { type: 'separator' },
            {
                key: 'GRAFIDA_LBL_SLASH_LOREM_SENTENCE',
                keywords: ['dummy', 'lorem', 'ipsum', 'sentence', 'placeholder'],
                icon: 'line',
                action: () => editor.insertContent(LOREM_SENTENCE),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_LOREM_PARAGRAPH',
                keywords: ['dummy', 'lorem', 'ipsum', 'paragraph', 'placeholder'],
                icon: 'paragraph',
                action: () => editor.insertContent('<p>' + LOREM_PARAGRAPH + '</p>'),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_QUOTE',
                keywords: ['quote', 'blockquote', 'citation'],
                icon: 'quote',
                action: () => {
                    editor.execCommand('mceBlockQuote');
                    editor.insertContent(escapeHtmlText(t('GRAFIDA_LBL_SLASH_QUOTATION')));
                    editor.selection.select(editor.selection.getNode());
                },
            },
            { type: 'separator' },
            {
                // Grafida's own read-more separator, sharing the toolbar button's
                // handler (which refuses a second one).
                key: 'GRAFIDA_BTN_INSERT_READMORE',
                keywords: ['read more', 'readmore', 'separator', 'intro'],
                icon: 'readmore',
                action: () => insertReadMore(editor),
            },
            { type: 'separator' },
            {
                key: 'GRAFIDA_LBL_SLASH_IMAGE',
                keywords: ['image', 'picture', 'media', 'photo'],
                icon: 'image',
                action: () => openMediaBrowser(options.siteId, { allowUpload: true }),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_IMAGE_43',
                keywords: ['image', 'placeholder', 'landscape', 'dummy', '4:3'],
                icon: 'image',
                action: () => insertPlaceholder(editor, 400, 300),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_IMAGE_34',
                keywords: ['image', 'placeholder', 'portrait', 'dummy', '3:4'],
                icon: 'image',
                action: () => insertPlaceholder(editor, 300, 400),
            },
            { type: 'separator' },
            {
                key: 'GRAFIDA_LBL_SLASH_LINK',
                keywords: ['link', 'url', 'anchor', 'hyperlink'],
                icon: 'link',
                action: () => editor.execCommand('mceLink'),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_TABLE',
                keywords: ['table', 'grid', 'rows', 'columns'],
                icon: 'table',
                action: () => editor.execCommand('mceInsertTable', false, { rows: 2, columns: 2 }),
            },
            { type: 'separator' },
            {
                key: 'GRAFIDA_LBL_SOURCE_CODE',
                keywords: ['source', 'code', 'html'],
                icon: 'sourcecode',
                action: () => openSourceCodeEditor(editor),
            },
            {
                key: 'GRAFIDA_LBL_SLASH_FULLSCREEN',
                keywords: ['fullscreen', 'full screen', 'maximise', 'maximize'],
                icon: 'fullscreen',
                action: () => editor.execCommand('mceFullScreen'),
            },
        ];
    }

    // -------------------------------------------------------------------------
    //  Filtering
    // -------------------------------------------------------------------------

    /**
     * Does the typed pattern match this command's label or English keywords?
     *
     * The label matches on a substring, but a keyword matches only at the start
     * of one of its words: a substring match would make "/ordered" surface the
     * *bulleted* list first, through its own "unordered" keyword — and the first
     * item is the one Enter picks. Matching per word (rather than per keyword)
     * keeps "/screen" finding "full screen".
     */
    function matches(item, pattern) {
        if (pattern === '') return true;

        if (item.text.toLowerCase().indexOf(pattern) !== -1) return true;

        return (item.keywords || []).some((keyword) =>
            keyword.toLowerCase().split(' ').some((word) => word.indexOf(pattern) === 0));
    }

    /**
     * Drops separators that would render at an edge or back-to-back once the
     * commands between them have been filtered out.
     */
    function dropRedundantSeparators(items) {
        return items.filter((item, i, all) => {
            if (item.type !== 'separator') return true;

            const prev = all[i - 1];
            const next = all[i + 1];

            return prev && next && prev.type !== 'separator' && next.type !== 'separator';
        });
    }

    /**
     * The autocompleter's result list for a typed pattern.
     *
     * This is also where the global off switch is enforced — rather than at
     * registration — so that toggling the Settings option takes effect at once
     * instead of at the next editor open. An autocompleter that returns nothing
     * shows no popup.
     */
    function fetchItems(editor, options, pattern) {
        if (State.slashTools === false) return [];

        const needle = String(pattern || '').toLowerCase();

        const resolved = buildItems(editor, options).map((item) =>
            item.type === 'separator' ? item : Object.assign({}, item, { text: t(item.key) })
        );

        const matched = dropRedundantSeparators(
            resolved.filter((item) => item.type === 'separator' || matches(item, needle))
        );

        return matched.map((item) => item.type === 'separator'
            ? { type: 'separator' }
            : { type: 'autocompleteitem', meta: item, text: item.text, icon: item.icon, value: item.key });
    }

    // -------------------------------------------------------------------------
    //  Registration
    // -------------------------------------------------------------------------

    /**
     * Registers the "/" autocompleter on an editor instance. Called once per
     * editor from initTinyMCE()'s setup callback.
     *
     * @param {Object} editor    the TinyMCE editor instance
     * @param {Object} [options] { siteId } — the site the media browser browses
     */
    function register(editor, options) {
        const opts = options || {};

        [1, 2, 3].forEach((level) => editor.ui.registry.addIcon('grafida-h' + level, headingIcon(level)));

        editor.ui.registry.addAutocompleter('grafida-slash', {
            trigger: '/',
            minChars: 0,
            columns: 1,

            fetch: (pattern) => Promise.resolve(fetchItems(editor, opts, pattern)),

            onAction: (autocompleteApi, rng, value, meta) => {
                // Select and delete the typed "/pattern" before acting, so the
                // trigger text does not survive in the article.
                editor.selection.setRng(rng);
                editor.execCommand('Delete');
                meta.action();
                autocompleteApi.hide();
            },
        });
    }

    global.GrafidaSlashTools = { register, buildItems, fetchItems };
}(typeof window !== 'undefined' ? window : this));
