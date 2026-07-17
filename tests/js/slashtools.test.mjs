/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/editor/slashtools.js — the editor's "/" menu.
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the providers
 * tests, this is the ONLY automated coverage available: the menu lives in the
 * SPA, so PHPUnit cannot reach it.
 *
 * slashtools.js is a plain browser IIFE that hangs itself off `window` and reaches
 * for the globals app.js declares (State, t, …). We therefore evaluate it inside a
 * `vm` context with those faked, then drive the real code.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

// -----------------------------------------------------------------------------
//  Harness
// -----------------------------------------------------------------------------

const SOURCE = readFileSync(new URL('../../assets/private/js/editor/slashtools.js', import.meta.url), 'utf8');

/** The English labels, standing in for the en-GB catalogue. */
const STRINGS = {
    GRAFIDA_LBL_SLASH_H1: 'Heading 1',
    GRAFIDA_LBL_SLASH_H2: 'Heading 2',
    GRAFIDA_LBL_SLASH_H3: 'Heading 3',
    GRAFIDA_LBL_SLASH_BULLET_LIST: 'Bulleted list',
    GRAFIDA_LBL_SLASH_ORDERED_LIST: 'Ordered list',
    GRAFIDA_LBL_SLASH_LIST_ITEM: 'List item',
    GRAFIDA_LBL_SLASH_LOREM_SENTENCE: 'Dummy sentence',
    GRAFIDA_LBL_SLASH_LOREM_PARAGRAPH: 'Dummy paragraph',
    GRAFIDA_LBL_SLASH_QUOTE: 'Quote',
    GRAFIDA_LBL_SLASH_QUOTATION: 'Quotation',
    GRAFIDA_LBL_SLASH_IMAGE_43: 'Placeholder image, landscape',
    GRAFIDA_LBL_SLASH_IMAGE_34: 'Placeholder image, portrait',
    GRAFIDA_LBL_SLASH_PLACEHOLDER_ALT: 'Placeholder',
    GRAFIDA_LBL_SLASH_FULLSCREEN: 'Fullscreen',
    GRAFIDA_LBL_SLASH_LINK: 'Insert link',
    GRAFIDA_LBL_SLASH_TABLE: 'Insert table',
    GRAFIDA_LBL_SLASH_IMAGE: 'Insert image',
    GRAFIDA_LBL_SOURCE_CODE: 'Source code',
    GRAFIDA_BTN_INSERT_READMORE: 'Insert read more',
};

/**
 * Load slashtools.js into a fresh sandbox.
 *
 * @param {Object} [opts]
 * @param {Object} [opts.strings]  the catalogue t() reads (defaults to English)
 * @param {Object}  [opts.state]        overrides merged into the fake State
 * @param {boolean} [opts.canvasWorks]  false makes the placeholder canvas fail
 * @returns {{ Slash: Object, editor: Object, calls: Object, state: Object }}
 */
function load(opts = {}) {
    const calls = { inserted: [], commands: [], icons: [], autocompleters: [], mediaBrowser: [], sourceCode: [], readMore: [], notifications: [] };

    const editor = {
        ui: {
            registry: {
                addIcon: (name, html) => calls.icons.push({ name, html }),
                addAutocompleter: (name, spec) => calls.autocompleters.push({ name, spec }),
            },
        },
        selection: {
            select: () => {},
            getNode: () => ({}),
            setRng: () => {},
        },
        insertContent: (html) => calls.inserted.push(html),
        execCommand: (cmd, ui, value) => calls.commands.push({ cmd, ui, value }),
        notificationManager: { open: (spec) => calls.notifications.push(spec) },
        dom: {
            // Stands in for TinyMCE's own attribute-escaping HTML builder.
            createHTML: (tag, attrs) => '<' + tag + Object.entries(attrs)
                .map(([k, v]) => ' ' + k + '="' + String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"')
                .join('') + ' />',
        },
    };

    const strings = opts.strings || STRINGS;

    const sandbox = {
        window: {},
        State: Object.assign({ slashTools: true }, opts.state || {}),
        t: (key) => (Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key),
        // Mirrors app.js's escapeHtmlText: it serialises a text node, so it
        // escapes &/</> but deliberately NOT quotes.
        escapeHtmlText: (text) => String(text ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
        openMediaBrowser: (siteId, o) => calls.mediaBrowser.push({ siteId, options: o }),
        openSourceCodeEditor: () => calls.sourceCode.push(true),
        insertReadMore: () => calls.readMore.push(true),
        // The placeholder items draw on a canvas. A jsdom-free stub is enough:
        // what matters here is the markup produced, not the pixels. Pass
        // canvasWorks: false to exercise the "could not draw" path.
        document: {
            createElement: () => ({
                getContext: () => (opts.canvasWorks === false ? null : {
                    fillRect: () => {}, strokeRect: () => {}, fillText: () => {},
                }),
                toDataURL: () => 'data:image/png;base64,AAAA',
            }),
        },
    };
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);

    return { Slash: sandbox.window.GrafidaSlashTools, editor, calls, state: sandbox.State };
}

/**
 * The visible labels of a fetch() result, separators marked as "---".
 *
 * Array.from() is load-bearing: fetchItems() returns an array minted inside the
 * sandbox's realm, and a strict deep-equal against an outer-realm literal fails
 * on the prototype alone. This re-homes the result here before comparing.
 */
function labels(results) {
    return Array.from(results, (r) => (r.type === 'separator' ? '---' : r.text));
}

/** Runs the command with the given label out of a fetch() result. */
function run(results, label) {
    const hit = results.find((r) => r.text === label);
    assert.ok(hit, 'no such command: ' + label);
    hit.meta.action();
}

// -----------------------------------------------------------------------------
//  Filtering
// -----------------------------------------------------------------------------

test('an empty pattern lists every command', () => {
    const { Slash, editor } = load();
    const results = Slash.fetchItems(editor, {}, '');

    assert.ok(labels(results).includes('Heading 1'));
    assert.ok(labels(results).includes('Insert table'));
    assert.ok(labels(results).includes('Fullscreen'));
});

test('the pattern matches the visible label', () => {
    const { Slash, editor } = load();
    const results = Slash.fetchItems(editor, {}, 'fullscr');

    assert.deepEqual(labels(results), ['Fullscreen']);
});

test('matching is case-insensitive', () => {
    const { Slash, editor } = load();

    assert.deepEqual(labels(Slash.fetchItems(editor, {}, 'FULLSCR')), ['Fullscreen']);
});

test('the pattern also matches English keywords, so /head works on a translated UI', () => {
    // A German catalogue: the labels no longer contain "heading" anywhere.
    const { Slash, editor } = load({
        strings: Object.assign({}, STRINGS, {
            GRAFIDA_LBL_SLASH_H1: 'Überschrift 1',
            GRAFIDA_LBL_SLASH_H2: 'Überschrift 2',
            GRAFIDA_LBL_SLASH_H3: 'Überschrift 3',
        }),
    });

    assert.deepEqual(
        labels(Slash.fetchItems(editor, {}, 'head')),
        ['Überschrift 1', 'Überschrift 2', 'Überschrift 3']
    );

    // …and the translated label still matches what a German speaker would type.
    assert.deepEqual(labels(Slash.fetchItems(editor, {}, 'überschrift 2')), ['Überschrift 2']);
});

test('a keyword matches only at the start of a word', () => {
    // Regression: "unordered" (the bulleted list's keyword) contains "ordered",
    // so a substring match put the BULLETED list first for "/ordered" — and the
    // first item is the one Enter picks.
    const { Slash, editor } = load();

    assert.deepEqual(labels(Slash.fetchItems(editor, {}, 'ordered')), ['Ordered list']);

    // …while a later word of a multi-word keyword ("full screen") still matches.
    assert.deepEqual(labels(Slash.fetchItems(editor, {}, 'screen')), ['Fullscreen']);
});

test('a pattern matching nothing yields no items — not a lone separator', () => {
    const { Slash, editor } = load();

    assert.deepEqual(labels(Slash.fetchItems(editor, {}, 'nothingmatchesthis')), []);
});

// -----------------------------------------------------------------------------
//  Separators
// -----------------------------------------------------------------------------

test('separators survive between commands but never at an edge or back-to-back', () => {
    const { Slash, editor } = load();
    const shown = labels(Slash.fetchItems(editor, {}, ''));

    assert.notEqual(shown[0], '---', 'leading separator');
    assert.notEqual(shown[shown.length - 1], '---', 'trailing separator');

    shown.forEach((label, i) => {
        if (label === '---') assert.notEqual(shown[i + 1], '---', 'back-to-back separators at ' + i);
    });

    assert.ok(shown.includes('---'), 'all separators collapsed away');
});

test('filtering across separator groups drops the separators between them', () => {
    // "image" hits Insert image + both placeholders (one group) and nothing else.
    const { Slash, editor } = load();

    assert.deepEqual(
        labels(Slash.fetchItems(editor, {}, 'image')),
        ['Insert image', 'Placeholder image, landscape', 'Placeholder image, portrait']
    );
});

// -----------------------------------------------------------------------------
//  The off switch
// -----------------------------------------------------------------------------

test('the menu yields nothing while switched off', () => {
    const { Slash, editor } = load({ state: { slashTools: false } });

    assert.deepEqual(labels(Slash.fetchItems(editor, {}, '')), []);
});

test('the off switch is read per fetch, so toggling it needs no editor re-init', async () => {
    const { Slash, editor, calls, state } = load();

    // Registration happens while the menu is enabled...
    Slash.register(editor, {});
    const spec = calls.autocompleters[0].spec;

    assert.ok((await spec.fetch('')).length > 0);

    // ...and switching it off afterwards must take effect on the same editor.
    state.slashTools = false;
    assert.deepEqual(labels(await spec.fetch('')), []);

    state.slashTools = true;
    assert.ok((await spec.fetch('')).length > 0);
});

// -----------------------------------------------------------------------------
//  What the commands insert
// -----------------------------------------------------------------------------

test('the ordered list inserts an <ol>, the bulleted list a <ul>', () => {
    // Upstream's slashtools inserts a <ul> for BOTH; this is the fix.
    const { Slash, editor, calls } = load();
    const results = Slash.fetchItems(editor, {}, '');

    run(results, 'Ordered list');
    run(results, 'Bulleted list');

    assert.deepEqual(calls.inserted, [
        '<ol><li>List item</li></ol>',
        '<ul><li>List item</li></ul>',
    ]);
});

test('the headings insert their own level', () => {
    const { Slash, editor, calls } = load();
    const results = Slash.fetchItems(editor, {}, '');

    run(results, 'Heading 2');

    assert.deepEqual(calls.inserted, ['<h2>Heading 2</h2>']);
});

test('the dummy text stays Latin whatever the interface language', () => {
    const { Slash, editor, calls } = load({
        strings: Object.assign({}, STRINGS, { GRAFIDA_LBL_SLASH_LOREM_SENTENCE: 'Dummy-Satz' }),
    });

    run(Slash.fetchItems(editor, {}, ''), 'Dummy-Satz');

    assert.match(calls.inserted[0], /^Lorem ipsum dolor sit/);
});

test('a translation containing HTML metacharacters is escaped, not injected', () => {
    const { Slash, editor, calls } = load({
        strings: Object.assign({}, STRINGS, {
            GRAFIDA_LBL_SLASH_H1: 'Rubrik & "Titel"',
            GRAFIDA_LBL_SLASH_LIST_ITEM: 'Punkt <1>',
        }),
    });
    const results = Slash.fetchItems(editor, {}, '');

    run(results, 'Rubrik & "Titel"');
    run(results, 'Bulleted list');

    assert.deepEqual(calls.inserted, [
        '<h1>Rubrik &amp; "Titel"</h1>',
        '<ul><li>Punkt &lt;1&gt;</li></ul>',
    ]);
});

test('a quote in the placeholder alt text cannot break out of the attribute', () => {
    // escapeHtmlText() would NOT catch this — it leaves a double quote alone —
    // which is why the img goes through editor.dom.createHTML instead.
    const { Slash, editor, calls } = load({
        strings: Object.assign({}, STRINGS, { GRAFIDA_LBL_SLASH_PLACEHOLDER_ALT: 'Bild "Platzhalter"' }),
    });

    run(Slash.fetchItems(editor, {}, ''), 'Placeholder image, landscape');

    assert.match(calls.inserted[0], /alt="Bild &quot;Platzhalter&quot;"/);
    assert.doesNotMatch(calls.inserted[0], /alt="Bild "Platzhalter""/);
});

test('a failed placeholder warns instead of silently inserting nothing', () => {
    const { Slash, editor, calls } = load({ canvasWorks: false });

    run(Slash.fetchItems(editor, {}, ''), 'Placeholder image, portrait');

    assert.deepEqual(calls.inserted, []);
    assert.equal(calls.notifications.length, 1);
    assert.equal(calls.notifications[0].type, 'warning');
});

test('the read more command shares the toolbar button handler', () => {
    const { Slash, editor, calls } = load();

    run(Slash.fetchItems(editor, {}, ''), 'Insert read more');

    // Not inserted directly: insertReadMore() is what refuses a second separator.
    assert.equal(calls.readMore.length, 1);
    assert.deepEqual(calls.inserted, []);
});

test('the image command opens the media browser for the editor\'s site', () => {
    const { Slash, editor, calls } = load();

    run(Slash.fetchItems(editor, { siteId: 42 }, ''), 'Insert image');

    assert.equal(calls.mediaBrowser.length, 1);
    assert.equal(calls.mediaBrowser[0].siteId, 42);
    assert.equal(calls.mediaBrowser[0].options.allowUpload, true);
});

test('the source code command opens the CodeMirror editor', () => {
    const { Slash, editor, calls } = load();

    run(Slash.fetchItems(editor, {}, ''), 'Source code');

    assert.equal(calls.sourceCode.length, 1);
});

// -----------------------------------------------------------------------------
//  Registration
// -----------------------------------------------------------------------------

test('register() adds the "/" autocompleter and its own heading icons', () => {
    const { Slash, editor, calls } = load();

    Slash.register(editor, { siteId: 1 });

    assert.equal(calls.autocompleters.length, 1);
    assert.equal(calls.autocompleters[0].spec.trigger, '/');
    assert.equal(calls.autocompleters[0].spec.minChars, 0);

    // TinyMCE 7 ships no heading icons, so we register our own.
    assert.deepEqual(calls.icons.map((i) => i.name), ['grafida-h1', 'grafida-h2', 'grafida-h3']);
});

test('picking a command deletes the typed "/pattern" before acting', async () => {
    const { Slash, editor, calls } = load();
    Slash.register(editor, {});

    const spec = calls.autocompleters[0].spec;
    const results = await spec.fetch('fullscr');

    let hidden = false;
    spec.onAction({ hide: () => { hidden = true; } }, {}, results[0].value, results[0].meta);

    assert.deepEqual(calls.commands.map((c) => c.cmd), ['Delete', 'mceFullScreen']);
    assert.ok(hidden, 'the popup was left open');
});
