/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * AI chat panel — Step 7.
 *
 * Exposes window.GrafidaAIPanel = { toggle, openWithTool, onEditorOpen, renderAiChatsBanner }.
 *
 * This module is a plain IIFE loaded AFTER app.js and relies on globals that
 * app.js places in the window scope (resolved at call time, not load time):
 *   State, t, el, txt, icon, iconBtn, clearNode, api, showToast, GrafidaAI
 *
 * Step 8 integration points (documented stubs — do NOT add logic here):
 *   renderAiChatsBanner()  — called when the panel opens; Step 8 populates #ai-chats-list
 *   _onPanelClose()        — called when the panel closes; Step 8 adds the "remember" prompt
 */

'use strict';

(function (global) {
    /* global State, t, el, txt, icon, iconBtn, clearNode, api, showToast, GrafidaAI */

    // -------------------------------------------------------------------------
    //  Panel state
    // -------------------------------------------------------------------------

    /**
     * In-memory conversation history.
     * Entries: { role: 'user' | 'assistant', content: string }
     * The document context is embedded inside the first user message's content
     * so it persists automatically across follow-up turns.
     */
    let _history = [];

    /** Whether the document context has already been embedded in the first user message. */
    let _docContextEmbedded = false;

    /** AbortController for the currently in-flight sendChat request, or null. */
    let _abortCtrl = null;

    /** True while a response is streaming in. */
    let _streaming = false;

    /** The assistant bubble element being built during a stream, or null. */
    let _streamingBubble = null;

    /** The tool that opened the current panel session (if any), or null. */
    let _activeTool = null;

    // -------------------------------------------------------------------------
    //  Public: toggle / open / close
    // -------------------------------------------------------------------------

    /**
     * Toggle the AI panel.
     * Opens (new empty chat) when hidden; closes when visible.
     * Entry point for the TinyMCE 'aiassistant' toolbar button.
     */
    function toggle() {
        const panel = document.getElementById('ai-panel');
        if (!panel) return;
        if (panel.classList.contains('hidden')) {
            _openPanel(null);
        } else {
            _closePanel();
        }
    }

    /**
     * Open the panel and immediately run `tool` against the current document.
     * Resets the conversation and sends the tool prompt as the first user message.
     * Entry point for items in the TinyMCE 'aitools' menu button.
     *
     * @param {Object} tool — entry from State.aiTools
     */
    function openWithTool(tool) {
        _activeTool = tool;
        _history = [];
        _docContextEmbedded = false;
        _streamingBubble = null;
        if (_abortCtrl) { _abortCtrl.abort(); _abortCtrl = null; }
        _setStreaming(false);
        _renderConversation();

        const panel = document.getElementById('ai-panel');
        if (!panel) return;
        panel.classList.remove('hidden');
        renderAiChatsBanner();

        // Run the tool immediately: the tool's prompt is the first user message.
        _sendMessage(tool.prompt || tool.title || '', tool);
    }

    // -------------------------------------------------------------------------
    //  Public: lifecycle hook (called by app.js)
    // -------------------------------------------------------------------------

    /**
     * Reset the panel conversation state when the editor (re)opens.
     * Called from app.js's openEditorScreen() after initTinyMCE() completes,
     * so State.tinyMCEEditor is available from this point on.
     */
    function onEditorOpen() {
        // Abort any request from a previous editor session.
        if (_abortCtrl) {
            _abortCtrl.abort();
            _abortCtrl = null;
        }
        _streaming = false;
        _streamingBubble = null;

        // Reset conversation.
        _history = [];
        _docContextEmbedded = false;
        _activeTool = null;
        _renderConversation();

        // Keep the panel hidden when the editor first opens; user must toggle it.
        const panel = document.getElementById('ai-panel');
        if (panel) panel.classList.add('hidden');

        // Set translated placeholder now that State.strings is populated.
        const inputEl = document.getElementById('ai-input');
        if (inputEl) {
            inputEl.placeholder = t('GRAFIDA_PLACEHOLDER_AI_CHAT');
            inputEl.value = '';
            inputEl.disabled = false;
        }

        // Ensure buttons reflect non-streaming state.
        _setStreaming(false);
    }

    // -------------------------------------------------------------------------
    //  Internal: open / close helpers
    // -------------------------------------------------------------------------

    function _openPanel(tool) {
        _activeTool = tool || null;
        _history = [];
        _docContextEmbedded = false;
        if (_abortCtrl) { _abortCtrl.abort(); _abortCtrl = null; }
        _setStreaming(false);
        _streamingBubble = null;
        _renderConversation();

        const panel = document.getElementById('ai-panel');
        if (!panel) return;
        panel.classList.remove('hidden');
        renderAiChatsBanner();

        const inputEl = document.getElementById('ai-input');
        if (inputEl) {
            inputEl.placeholder = t('GRAFIDA_PLACEHOLDER_AI_CHAT');
            inputEl.disabled = false;
            inputEl.focus();
        }
    }

    function _closePanel() {
        // Abort any in-flight request.
        if (_abortCtrl) {
            _abortCtrl.abort();
            _abortCtrl = null;
        }
        _streaming = false;
        _streamingBubble = null;

        const panel = document.getElementById('ai-panel');
        if (panel) panel.classList.add('hidden');

        _onPanelClose();
    }

    // -------------------------------------------------------------------------
    //  Conversation engine
    // -------------------------------------------------------------------------

    /**
     * Send a message to the AI.
     *
     * Builds messages = [{role:'system', content}, ...history], then calls
     * GrafidaAI.sendChat. Streaming tokens are appended live to a bubble.
     * The complete assistant response is pushed to _history when done.
     *
     * @param {string}      userText — the user's typed text, or a tool's prompt
     * @param {Object|null} tool     — the tool to run (for overrideSystem / serviceId), or null
     */
    async function _sendMessage(userText, tool) {
        if (_streaming) return;

        const serviceId = (tool ? tool.serviceId : null) ?? State.aiDefaultServiceId;
        if (!serviceId) {
            _appendErrorBubble(t('GRAFIDA_MSG_AI_NO_SERVICE'));
            return;
        }

        // Lazily load the system prompt if it hasn't been fetched yet (the
        // Settings screen's AI Tools card fetches it on first open; here we
        // fetch it proactively so the panel can work without opening Settings).
        if (!State.aiSystemPrompt && !State.aiAllTools.length) {
            try {
                const data = await api.listAiTools();
                State.aiAllTools = data.tools || [];
                State.aiSystemPrompt = data.systemPrompt || '';
                State.aiTones = data.tones || {};
            } catch {
                // Non-fatal: proceed with an empty system prompt.
            }
        }

        // Build the system message content.
        // If the tool overrides the system prompt, use only the tool's prompt;
        // otherwise append the tool's prompt to the base system prompt.
        const baseSystem = State.aiSystemPrompt || '';
        let systemContent = baseSystem;
        if (tool && tool.prompt) {
            systemContent = tool.overrideSystem
                ? tool.prompt
                : (baseSystem ? baseSystem + '\n\n' : '') + tool.prompt;
        }

        // Build the user content.
        // For the first message in a conversation we embed the document context
        // (article HTML + title) as a preamble before the actual query, mirroring
        // the [prompt, documentContent] pattern used in AITiny. All subsequent
        // messages go in as-is (the document is already in the history).
        let userContent = userText;
        if (!_docContextEmbedded) {
            const editor = State.tinyMCEEditor;
            const docHtml  = editor ? editor.getContent() : '';
            const titleEl  = document.getElementById('editor-title-input');
            const title    = titleEl ? titleEl.value.trim() : '';

            const preamble = [];
            if (title)   preamble.push('Article title: ' + title);
            if (docHtml) preamble.push('Article content:\n\n' + docHtml);

            if (preamble.length) {
                userContent = preamble.join('\n\n') + '\n\n' + userText;
            }
            _docContextEmbedded = true;
        }

        // Add the user turn and re-render so it appears immediately.
        _history.push({ role: 'user', content: userContent });
        _renderConversation();

        // Clear the textarea for manual (non-tool) messages.
        if (!tool) {
            const inputEl = document.getElementById('ai-input');
            if (inputEl) inputEl.value = '';
        }

        // Assemble the full messages array.
        const messages = [];
        if (systemContent) {
            messages.push({ role: 'system', content: systemContent });
        }
        messages.push(..._history);

        // Respect the service's `stream` param (default: true).
        const svc = State.aiServices.find(s => s.id === serviceId);
        const wantStream = !(svc && svc.params && svc.params.stream === false);

        _setStreaming(true);
        _streamingBubble = _appendStreamingBubble();
        _abortCtrl = GrafidaAI.newAbort();

        let fullText = '';

        try {
            const result = await GrafidaAI.sendChat(serviceId, messages, {
                stream:   wantStream,
                signal:   _abortCtrl.signal,
                toolKey:  tool ? (tool.toolKey || '') : '',
                onToken:  (delta) => {
                    fullText += delta;
                    if (_streamingBubble) {
                        const textEl = _streamingBubble.querySelector('.ai-bubble-text');
                        if (textEl) textEl.textContent += delta;
                        const conv = document.getElementById('ai-conversation');
                        if (conv) conv.scrollTop = conv.scrollHeight;
                    }
                },
            });

            // Non-streaming / proxy fallback: result.text holds the full response.
            if (!wantStream || result.usedFallback) {
                fullText = result.text;
                if (_streamingBubble) {
                    const textEl = _streamingBubble.querySelector('.ai-bubble-text');
                    if (textEl) textEl.textContent = fullText;
                }
            }

            // Record the assistant turn.
            _history.push({ role: 'assistant', content: fullText });

            // Attach Insert / Copy action buttons to the completed bubble.
            if (_streamingBubble) {
                _addBubbleActions(_streamingBubble, fullText);
            }

        } catch (err) {
            if (err.name === 'AbortError') {
                // User cancelled via the Stop button or panel close.
                if (fullText) {
                    // Keep partial response in history.
                    _history.push({ role: 'assistant', content: fullText });
                    if (_streamingBubble) _addBubbleActions(_streamingBubble, fullText);
                } else {
                    // Nothing produced: remove the streaming placeholder.
                    if (_streamingBubble) _streamingBubble.remove();
                    // Also remove the user turn that got no response.
                    if (_history.length && _history[_history.length - 1].role === 'user') {
                        _history.pop();
                        _docContextEmbedded = _history.some(m => m.role === 'user');
                    }
                }
            } else {
                // Provider / network error: show it and retract the user turn.
                _appendErrorBubble(err.message || String(err));
                if (_streamingBubble) _streamingBubble.remove();
                if (_history.length && _history[_history.length - 1].role === 'user') {
                    _history.pop();
                    _docContextEmbedded = _history.some(m => m.role === 'user');
                }
            }
        } finally {
            _abortCtrl = null;
            _streamingBubble = null;
            _setStreaming(false);
            const conv = document.getElementById('ai-conversation');
            if (conv) conv.scrollTop = conv.scrollHeight;
        }
    }

    // -------------------------------------------------------------------------
    //  Rendering helpers
    // -------------------------------------------------------------------------

    /**
     * Clear the conversation area and re-render all messages from _history.
     * For user messages the embedded document context preamble is stripped
     * so only the actual query is shown.
     */
    function _renderConversation() {
        const conv = document.getElementById('ai-conversation');
        if (!conv) return;
        clearNode(conv);

        if (!_history.length) {
            const empty = el('div', 'ai-conversation-empty');
            empty.textContent = t('GRAFIDA_MSG_AI_EMPTY');
            conv.appendChild(empty);
            return;
        }

        _history.forEach((msg) => {
            if (msg.role === 'user') {
                const bubble = el('div', 'ai-bubble ai-bubble-user');
                bubble.textContent = _stripDocContext(msg.content);
                conv.appendChild(bubble);
            } else if (msg.role === 'assistant') {
                conv.appendChild(_buildAssistantBubble(msg.content));
            }
        });

        conv.scrollTop = conv.scrollHeight;
    }

    /**
     * Build a complete assistant bubble with text + action buttons.
     *
     * @param {string} content
     * @returns {HTMLElement}
     */
    function _buildAssistantBubble(content) {
        const bubble = el('div', 'ai-bubble ai-bubble-assistant');
        const textEl = el('div', 'ai-bubble-text');
        textEl.textContent = content;
        bubble.appendChild(textEl);
        _addBubbleActions(bubble, content);
        return bubble;
    }

    /**
     * Append a new, empty streaming bubble to the conversation.
     * Returns the element so onToken can update its text content.
     *
     * @returns {HTMLElement|null}
     */
    function _appendStreamingBubble() {
        const conv = document.getElementById('ai-conversation');
        if (!conv) return null;

        // Remove the "empty conversation" placeholder if present.
        const emptyEl = conv.querySelector('.ai-conversation-empty');
        if (emptyEl) emptyEl.remove();

        const bubble = el('div', 'ai-bubble ai-bubble-assistant ai-bubble-streaming');
        bubble.appendChild(el('div', 'ai-bubble-text'));
        conv.appendChild(bubble);
        conv.scrollTop = conv.scrollHeight;
        return bubble;
    }

    /**
     * Add the Insert-into-editor and Copy action buttons below an assistant bubble.
     * Removes any existing actions row first (safe to call on completion).
     *
     * @param {HTMLElement} bubble
     * @param {string}      content
     */
    function _addBubbleActions(bubble, content) {
        bubble.classList.remove('ai-bubble-streaming');

        const existing = bubble.querySelector('.ai-bubble-actions');
        if (existing) existing.remove();

        const insertBtn = iconBtn(
            'arrow-right-to-bracket',
            t('GRAFIDA_BTN_AI_INSERT'),
            'btn', 'btn-sm', 'btn-secondary'
        );
        insertBtn.title = t('GRAFIDA_BTN_AI_INSERT');
        insertBtn.addEventListener('click', () => {
            const editor = State.tinyMCEEditor;
            if (!editor) return;
            editor.insertContent(content);
            editor.focus();
        });

        const copyBtn = iconBtn('copy', t('GRAFIDA_BTN_COPY'), 'btn', 'btn-sm', 'btn-secondary');
        copyBtn.title = t('GRAFIDA_BTN_COPY');
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(content)
                .then(() => showToast(t('GRAFIDA_MSG_AI_COPIED'), 'success', 2000))
                .catch(() => showToast(t('GRAFIDA_MSG_AI_COPY_FAIL'), 'error', 3000));
        });

        bubble.appendChild(el('div', 'ai-bubble-actions', insertBtn, copyBtn));
    }

    /**
     * Append an error message bubble to the conversation.
     *
     * @param {string} message
     */
    function _appendErrorBubble(message) {
        const conv = document.getElementById('ai-conversation');
        if (!conv) return;

        const emptyEl = conv.querySelector('.ai-conversation-empty');
        if (emptyEl) emptyEl.remove();

        const bubble = el('div', 'ai-bubble ai-bubble-error');
        bubble.textContent = message;
        conv.appendChild(bubble);
        conv.scrollTop = conv.scrollHeight;
    }

    /**
     * Strip the document context preamble from a user message content before
     * displaying it in the conversation. The preamble is everything up to the
     * last blank-line separator before the actual query/instruction.
     *
     * We added the preamble so it starts with "Article " — that is the cue.
     *
     * @param {string} content
     * @returns {string}
     */
    function _stripDocContext(content) {
        if (!content) return content;
        if (!content.startsWith('Article ')) return content;
        const lastSep = content.lastIndexOf('\n\n');
        if (lastSep >= 0 && lastSep < content.length - 2) {
            return content.slice(lastSep + 2);
        }
        return content;
    }

    // -------------------------------------------------------------------------
    //  UI state helpers
    // -------------------------------------------------------------------------

    /**
     * Switch the panel between idle and streaming state:
     *   - streaming = true:  disable Send + input, show Stop button
     *   - streaming = false: enable Send + input, hide Stop button
     *
     * @param {boolean} on
     */
    function _setStreaming(on) {
        _streaming = on;
        const sendBtn = document.getElementById('ai-btn-send');
        const stopBtn = document.getElementById('ai-btn-stop');
        const inputEl = document.getElementById('ai-input');
        if (sendBtn) sendBtn.disabled = on;
        if (stopBtn) stopBtn.classList.toggle('hidden', !on);
        if (inputEl) inputEl.disabled = on;
    }

    // -------------------------------------------------------------------------
    //  DOM event handlers
    // -------------------------------------------------------------------------

    function _onSendClick() {
        const inputEl = document.getElementById('ai-input');
        const text = inputEl ? inputEl.value.trim() : '';
        if (!text || _streaming) return;
        _sendMessage(text, null);
    }

    function _onStopClick() {
        if (_abortCtrl) _abortCtrl.abort();
    }

    /**
     * Ctrl+Enter / Cmd+Enter submits; plain Enter inserts a newline (for multi-
     * line prompts). This mirrors the convention in most AI chat UIs.
     */
    function _onInputKeyDown(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            _onSendClick();
        }
    }

    // -------------------------------------------------------------------------
    //  DOMContentLoaded: wire up static DOM elements
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', () => {
        const sendBtn = document.getElementById('ai-btn-send');
        const stopBtn = document.getElementById('ai-btn-stop');
        const inputEl = document.getElementById('ai-input');

        if (sendBtn) sendBtn.addEventListener('click', _onSendClick);
        if (stopBtn) stopBtn.addEventListener('click', _onStopClick);
        if (inputEl) inputEl.addEventListener('keydown', _onInputKeyDown);
    });

    // -------------------------------------------------------------------------
    //  Step 8 stubs — documented integration points
    // -------------------------------------------------------------------------

    /**
     * Populate the AI Chats banner with saved conversations.
     * Called each time the panel opens. Step 8 will implement this body.
     *
     * Step 8 integration:
     *   - Fetch saved chats for the current draft from the server.
     *   - Render entries inside #ai-chats-list.
     *   - Remove the 'hidden' class from #ai-chats-section to reveal the banner.
     *   - Each entry, when clicked, should load that chat's history into _history
     *     and call _renderConversation() (Step 8 will need to expose helpers).
     *
     * DO NOT add logic here — this is a documented stub for Step 8.
     */
    function renderAiChatsBanner() {
        // Step 8 will populate #ai-chats-list and reveal #ai-chats-section here.
    }

    /**
     * Called when the AI panel closes.
     * Step 8 will add the "remember this conversation?" prompt here.
     *
     * Step 8 integration:
     *   - If _history has messages, prompt the user to save the conversation.
     *   - Use _history, _activeTool, and State.currentDraftId to persist.
     *   - After saving (or declining), clear _history as needed.
     *
     * DO NOT add logic here — this is a documented stub for Step 8.
     */
    function _onPanelClose() {
        // Step 8: offer to save the current conversation to the server.
    }

    // -------------------------------------------------------------------------
    //  Public API
    // -------------------------------------------------------------------------

    global.GrafidaAIPanel = {
        /**
         * Toggle the panel open/closed (no tool — starts a fresh chat).
         * Wired to the TinyMCE 'aiassistant' toolbar button.
         */
        toggle,

        /**
         * Open the panel and immediately run a specific tool.
         * Wired to items in the TinyMCE 'aitools' menu button.
         *
         * @param {Object} tool — entry from State.aiTools
         */
        openWithTool,

        /**
         * Reset panel state when the editor opens/reinitialises.
         * Called by app.js from openEditorScreen() after initTinyMCE().
         */
        onEditorOpen,

        /**
         * Step 8 override point: replace with an implementation that populates
         * the AI Chats banner (#ai-chats-list, #ai-chats-section).
         */
        renderAiChatsBanner,
    };

})(window);
