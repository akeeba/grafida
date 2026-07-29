/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Code-format shortcuts and Markdown-style typing patterns for TinyMCE.
 * Exposes window.GrafidaCodeFormats = { register, markdownPatterns,
 * toggleInlineCode, togglePreformattedBlock }.
 */

'use strict';

(function (global) {
    const MARKDOWN_FENCE = '```';

    /** Applies the same inline Code format as Format ▸ Code. */
    function toggleInlineCode(editor) {
        editor.execCommand('mceToggleFormat', false, 'code');
    }

    /** Applies the same Pre block format as Format ▸ Formats ▸ Block ▸ Pre. */
    function togglePreformattedBlock(editor) {
        editor.execCommand('mceToggleFormat', false, 'pre');
    }

    /**
     * Extra TinyMCE text patterns. text_patterns_lookup appends these to the
     * editor's defaults, unlike text_patterns, which would replace them.
     *
     * Suppress the inline-backtick pattern on a bare triple-backtick line. The
     * Enter handler below owns that exact spelling, and this guard makes the
     * result independent of TinyMCE's keydown-listener registration order.
     */
    function markdownPatterns(context) {
        if (String(context && context.text || '').trim() === MARKDOWN_FENCE) {
            return [];
        }

        return [{ start: '`', end: '`', format: 'code' }];
    }

    /**
     * Returns the paragraph containing a bare Markdown fence when the caret is
     * immediately after it, or null when Enter should keep its normal meaning.
     */
    function markdownFenceBlock(editor) {
        if (!editor.selection.isCollapsed() || !editor.selection.isEditable()) {
            return null;
        }

        const rng = editor.selection.getRng();
        const block = editor.dom.getParent(rng.startContainer, editor.dom.isBlock);

        if (!block || block.nodeName.toLowerCase() !== 'p' ||
            block.textContent !== MARKDOWN_FENCE) {
            return null;
        }

        // textContent alone is not enough: Enter before the fence must remain a
        // normal newline. Measure from the start of the paragraph to the caret.
        const beforeCaret = editor.dom.createRng();
        beforeCaret.setStart(block, 0);
        beforeCaret.setEnd(rng.startContainer, rng.startOffset);

        return beforeCaret.toString() === MARKDOWN_FENCE ? block : null;
    }

    /**
     * Turns a line containing only ``` into an empty Pre block when Enter is
     * pressed. This is deliberately separate from TinyMCE's block text-pattern
     * API: an enter-triggered public pattern skips a marker with no text after
     * it, whereas the normal Markdown fence is precisely that bare marker.
     */
    function handleMarkdownFence(editor, event) {
        if ((event.key !== 'Enter' && event.keyCode !== 13) ||
            event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return false;
        }

        const block = markdownFenceBlock(editor);
        if (!block) return false;

        event.preventDefault();
        editor.undoManager.transact(() => {
            editor.dom.setHTML(block, '');
            editor.selection.setCursorLocation(block, 0);
            togglePreformattedBlock(editor);
        });

        return true;
    }

    /** Registers code-format shortcuts and the fenced-code typing handler. */
    function register(editor) {
        editor.addShortcut('alt+shift+c', 'Inline code format', () => {
            toggleInlineCode(editor);
        });
        editor.addShortcut('alt+shift+p', 'Preformatted block', () => {
            togglePreformattedBlock(editor);
        });
        editor.on('keydown', (event) => {
            handleMarkdownFence(editor, event);
        }, true);
    }

    global.GrafidaCodeFormats = {
        register,
        markdownPatterns,
        toggleInlineCode,
        togglePreformattedBlock,
    };
})(window);
