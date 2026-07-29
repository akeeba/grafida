/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/editor/codeformats.js.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(
    new URL('../../assets/private/js/editor/codeformats.js', import.meta.url),
    'utf8'
);

function load(blockOverrides = {}) {
    const calls = {
        commands: [],
        shortcuts: [],
        handlers: [],
        html: [],
        cursors: [],
        transactions: 0,
    };
    const block = Object.assign({
        nodeName: 'P',
        textContent: '```',
    }, blockOverrides);
    const range = {
        startContainer: {},
        startOffset: 3,
    };
    const editor = {
        addShortcut: (pattern, description, action) =>
            calls.shortcuts.push({ pattern, description, action }),
        execCommand: (command, ui, value) =>
            calls.commands.push({ command, ui, value }),
        on: (name, handler, capture) =>
            calls.handlers.push({ name, handler, capture }),
        selection: {
            isCollapsed: () => true,
            isEditable: () => true,
            getRng: () => range,
            setCursorLocation: (node, offset) =>
                calls.cursors.push({ node, offset }),
        },
        dom: {
            isBlock: () => true,
            getParent: () => block,
            createRng: () => ({
                setStart: () => {},
                setEnd: () => {},
                toString: () => block.textContent,
            }),
            setHTML: (node, html) => {
                calls.html.push({ node, html });
                node.textContent = '';
            },
        },
        undoManager: {
            transact: (callback) => {
                calls.transactions++;
                callback();
            },
        },
    };
    const sandbox = { window: {} };

    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);

    return {
        CodeFormats: sandbox.window.GrafidaCodeFormats,
        editor,
        calls,
        block,
        range,
    };
}

test('Markdown patterns append inline code without consuming a bare fence', () => {
    const { CodeFormats } = load();

    assert.deepEqual(
        Array.from(CodeFormats.markdownPatterns({ text: 'Use `this`' }), (pattern) => ({ ...pattern })),
        [{ start: '`', end: '`', format: 'code' }]
    );
    assert.deepEqual(Array.from(CodeFormats.markdownPatterns({ text: '```' })), []);
    assert.deepEqual(Array.from(CodeFormats.markdownPatterns({ text: '  ```  ' })), []);
});

test('register adds the requested Alt-Shift shortcuts', () => {
    const { CodeFormats, editor, calls } = load();

    CodeFormats.register(editor);

    assert.deepEqual(
        calls.shortcuts.map((shortcut) => shortcut.pattern),
        ['alt+shift+c', 'alt+shift+p']
    );

    calls.shortcuts[0].action();
    calls.shortcuts[1].action();
    assert.deepEqual(calls.commands, [
        { command: 'mceToggleFormat', ui: false, value: 'code' },
        { command: 'mceToggleFormat', ui: false, value: 'pre' },
    ]);
});

test('a bare triple-backtick paragraph becomes an empty Pre block on Enter', () => {
    const { CodeFormats, editor, calls, block } = load();

    CodeFormats.register(editor);
    const keydown = calls.handlers.find((handler) => handler.name === 'keydown');
    let prevented = false;

    assert.equal(keydown.capture, true);
    keydown.handler({
        key: 'Enter',
        keyCode: 13,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        preventDefault: () => { prevented = true; },
    });

    assert.equal(prevented, true);
    assert.equal(calls.transactions, 1);
    assert.deepEqual(calls.html, [{ node: block, html: '' }]);
    assert.deepEqual(calls.cursors, [{ node: block, offset: 0 }]);
    assert.deepEqual(calls.commands, [
        { command: 'mceToggleFormat', ui: false, value: 'pre' },
    ]);
});

test('the fence handler leaves ordinary paragraphs and modified Enter alone', () => {
    const ordinary = load({ textContent: '```php' });
    ordinary.CodeFormats.register(ordinary.editor);

    const ordinaryHandler = ordinary.calls.handlers[0].handler;
    ordinaryHandler({
        key: 'Enter',
        keyCode: 13,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        preventDefault: () => assert.fail('ordinary Enter was prevented'),
    });
    assert.equal(ordinary.calls.transactions, 0);

    const modified = load();
    modified.CodeFormats.register(modified.editor);
    modified.calls.handlers[0].handler({
        key: 'Enter',
        keyCode: 13,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: true,
        preventDefault: () => assert.fail('Shift+Enter was prevented'),
    });
    assert.equal(modified.calls.transactions, 0);
});
