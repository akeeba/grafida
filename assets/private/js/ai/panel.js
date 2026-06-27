/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * AI chat panel — Step 7 + Step 8 (saved chats).
 *
 * Exposes window.GrafidaAIPanel = { toggle, openWithTool, onEditorOpen, renderAiChatsBanner }.
 *
 * This module is a plain IIFE loaded AFTER app.js and relies on globals that
 * app.js places in the window scope (resolved at call time, not load time):
 *   State, t, el, txt, icon, iconBtn, clearNode, api, showToast, GrafidaAI,
 *   showModal, closeModal, confirmYesNo, promptText, saveDraft
 */

'use strict';

(function (global) {
    /* global State, t, el, txt, icon, iconBtn, clearNode, api, showToast, GrafidaAI,
              showModal, closeModal, confirmYesNo, promptText, saveDraft */

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

    /**
     * The id of a saved chat that was loaded into _history, or null for a fresh conversation.
     * When set, _onPanelClose offers to PATCH the existing chat rather than POST a new one.
     */
    let _loadedChatId = null;

    /**
     * The number of messages in _history at the moment a saved chat was loaded.
     * Used to detect whether the user added any new turns before closing.
     */
    let _loadedHistoryLength = 0;

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
        _loadedChatId = null;
        _loadedHistoryLength = 0;
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
        _loadedChatId = null;
        _loadedHistoryLength = 0;
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
        _loadedChatId = null;
        _loadedHistoryLength = 0;
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

    async function _closePanel() {
        // Abort any in-flight request.
        if (_abortCtrl) {
            _abortCtrl.abort();
            _abortCtrl = null;
        }
        _streaming = false;
        _streamingBubble = null;

        // The "remember?" prompt (if applicable) is shown while the panel is still
        // visible; we only hide it after the user has responded (or if there is
        // nothing to prompt about, _onPanelClose resolves immediately).
        await _onPanelClose();

        const panel = document.getElementById('ai-panel');
        if (panel) panel.classList.add('hidden');
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
        const streamRenderer = _streamingBubble
            ? _createStreamRenderer(_streamingBubble.querySelector('.ai-bubble-text'))
            : null;
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
                        // Show raw text as an instant placeholder only until the
                        // first formatted render lands; after that the throttled
                        // renderer owns the element (avoids rich⇄plain flicker).
                        if (textEl && !(streamRenderer && streamRenderer.hasApplied())) {
                            textEl.textContent = fullText;
                        }
                        if (streamRenderer) streamRenderer.push(fullText);
                        const conv = document.getElementById('ai-conversation');
                        if (conv) conv.scrollTop = conv.scrollHeight;
                    }
                },
            });

            // Non-streaming / proxy fallback: result.text holds the full response
            // (no tokens streamed in, so onToken never ran).
            if (!wantStream || result.usedFallback) {
                fullText = result.text;
            }

            // Record the assistant turn.
            _history.push({ role: 'assistant', content: fullText });

            // Final, authoritative render of the completed response (supersedes
            // any in-flight streaming render) + the Insert / Copy action buttons.
            if (_streamingBubble) {
                if (streamRenderer) {
                    streamRenderer.finish(fullText);
                } else {
                    const textEl = _streamingBubble.querySelector('.ai-bubble-text');
                    if (textEl) _renderRichText(textEl, fullText);
                }
                _addBubbleActions(_streamingBubble, fullText);
            }

        } catch (err) {
            if (err.name === 'AbortError') {
                // User cancelled via the Stop button or panel close.
                if (fullText) {
                    // Keep partial response in history.
                    _history.push({ role: 'assistant', content: fullText });
                    if (_streamingBubble) {
                        if (streamRenderer) {
                            streamRenderer.finish(fullText);
                        } else {
                            const textEl = _streamingBubble.querySelector('.ai-bubble-text');
                            if (textEl) _renderRichText(textEl, fullText);
                        }
                        _addBubbleActions(_streamingBubble, fullText);
                    }
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
                conv.appendChild(_buildUserBubble(msg.content));
            } else if (msg.role === 'assistant') {
                conv.appendChild(_buildAssistantBubble(msg.content));
            }
        });

        conv.scrollTop = conv.scrollHeight;
    }

    /**
     * Build a user-message bubble with the prompt rendered as *formatted* text.
     *
     * Tool prompts (and many hand-typed prompts) are Markdown, so the prompt is
     * run through the same CommonMark + sanitiser pipeline as assistant replies
     * (`_renderRichText`) rather than shown as raw Markdown. The embedded
     * document-context preamble is stripped first so only the actual query shows.
     *
     * @param {string} content
     * @returns {HTMLElement}
     */
    function _buildUserBubble(content) {
        const bubble = el('div', 'ai-bubble ai-bubble-user');
        const textEl = el('div', 'ai-bubble-text');
        _renderRichText(textEl, _stripDocContext(content));
        bubble.appendChild(textEl);
        return bubble;
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
        _renderRichText(textEl, content);
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
    //  Rich-text rendering (formatted assistant output)
    // -------------------------------------------------------------------------

    /**
     * Render an assistant message as *formatted* text inside `textEl`.
     *
     * The reply is untrusted model output (HTML, or Markdown for the Generate
     * tool). It is sent to the backend (`POST /api/ai/render`), which converts
     * Markdown via CommonMark when needed and sanitises the HTML with Symfony's
     * HtmlSanitizer; only the returned safe HTML is rendered. The raw text is
     * shown first as an always-safe placeholder and kept if the request fails,
     * so the panel never blocks on the render call.
     *
     * The raw `content` (not this rendered HTML) is still what Insert/Copy use.
     *
     * @param {HTMLElement} textEl
     * @param {string}      content
     */
    function _renderRichText(textEl, content) {
        // Immediate, always-safe placeholder (plain text — no HTML parsing).
        textEl.textContent = content;
        if (!content) return;

        api.aiRender(content)
            .then((res) => {
                const html = res && typeof res.html === 'string' ? res.html : null;
                if (html === null) return;  // keep the plain-text fallback
                textEl.classList.add('ai-rich');
                textEl.innerHTML = html;
            })
            .catch(() => { /* leave the plain-text fallback in place */ });
    }

    /** Minimum gap between successive streaming rich-render calls (ms). */
    const STREAM_RENDER_INTERVAL = 200;

    /**
     * Create a throttled rich-text renderer for an in-progress streaming reply.
     *
     * Chatbot-style: the reply is re-formatted *as it streams* instead of staying
     * raw until the end. As tokens arrive, call `push(fullText)`; it renders the
     * accumulated text through the same CommonMark + sanitiser pipeline as a
     * finished bubble (`POST /api/ai/render`), but at most once every
     * STREAM_RENDER_INTERVAL ms. Each render is an async server round-trip, so
     * results are sequenced: a slow, stale response can never overwrite a newer
     * one — the formatting only ever moves forward (occasional reflow jumps as
     * blocks resolve are expected and acceptable).
     *
     * Call `finish(fullText)` once when the stream ends for an authoritative final
     * render that supersedes any still-in-flight streaming render.
     *
     * @param {HTMLElement} textEl
     * @returns {{push: function(string): void, finish: function(string): void, hasApplied: function(): boolean}}
     */
    function _createStreamRenderer(textEl) {
        let seq     = 0;      // id of the most recent render REQUEST issued
        let applied = 0;      // id of the most recent render RESULT applied
        let timer   = null;   // throttle timer handle, or null when idle
        let pending = false;  // tokens arrived while a render was in flight
        let latest  = '';     // newest accumulated text
        let done    = false;  // finish() has been called

        function apply(mySeq, html) {
            if (html === null || mySeq < applied) return;  // stale or failed
            applied = mySeq;
            textEl.classList.add('ai-rich');
            textEl.innerHTML = html;
            const conv = document.getElementById('ai-conversation');
            if (conv) conv.scrollTop = conv.scrollHeight;
        }

        function fire() {
            timer = null;
            const content = latest;
            if (!content) return;
            const mySeq = ++seq;
            api.aiRender(content)
                .then((res) => apply(mySeq, res && typeof res.html === 'string' ? res.html : null))
                .catch(() => { /* keep the last good / plain-text view */ })
                .finally(() => {
                    if (pending && !done) {
                        pending = false;
                        timer = setTimeout(fire, STREAM_RENDER_INTERVAL);
                    }
                });
        }

        return {
            push(content) {
                latest = content;
                if (done) return;
                if (timer) { pending = true; return; }
                timer = setTimeout(fire, STREAM_RENDER_INTERVAL);
            },
            finish(content) {
                done   = true;
                latest = content;
                if (timer) { clearTimeout(timer); timer = null; }
                if (!content) return;
                const mySeq = ++seq;
                api.aiRender(content)
                    .then((res) => apply(mySeq, res && typeof res.html === 'string' ? res.html : null))
                    .catch(() => { /* keep the last good / plain-text view */ });
            },
            hasApplied() { return applied > 0; },
        };
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
        const sendBtn  = document.getElementById('ai-btn-send');
        const stopBtn  = document.getElementById('ai-btn-stop');
        const newBtn   = document.getElementById('ai-btn-new');
        const closeBtn = document.getElementById('ai-btn-close');
        const inputEl  = document.getElementById('ai-input');

        if (sendBtn)  sendBtn.addEventListener('click', _onSendClick);
        if (stopBtn)  stopBtn.addEventListener('click', _onStopClick);
        if (newBtn)   newBtn.addEventListener('click', () => { _newChat(); });
        if (closeBtn) {
            closeBtn.title = t('GRAFIDA_BTN_CLOSE');
            closeBtn.setAttribute('aria-label', t('GRAFIDA_BTN_CLOSE'));
            closeBtn.addEventListener('click', () => { _closePanel(); });
        }
        if (inputEl) inputEl.addEventListener('keydown', _onInputKeyDown);
    });

    /**
     * Start a fresh conversation without leaving the panel.
     * Offers to remember the current conversation first (same flow as closing),
     * then resets the history and refreshes the saved-chats banner.
     */
    async function _newChat() {
        if (_abortCtrl) { _abortCtrl.abort(); _abortCtrl = null; }
        _setStreaming(false);
        _streamingBubble = null;

        await _onPanelClose();

        _activeTool          = null;
        _history             = [];
        _docContextEmbedded  = false;
        _loadedChatId        = null;
        _loadedHistoryLength = 0;
        _renderConversation();
        renderAiChatsBanner();

        const inputEl = document.getElementById('ai-input');
        if (inputEl) { inputEl.value = ''; inputEl.disabled = false; inputEl.focus(); }
    }

    // -------------------------------------------------------------------------
    //  Step 8: saved chats banner + remember prompt
    // -------------------------------------------------------------------------

    /**
     * Populate #ai-chats-list with saved conversations for the current draft and
     * reveal #ai-chats-section.  Called each time the panel opens.
     *
     * Hides the section when there is no current draft or no saved chats.
     * Each row provides Open, Rename, and Delete actions.
     */
    async function renderAiChatsBanner() {
        const section = document.getElementById('ai-chats-section');
        const list    = document.getElementById('ai-chats-list');
        if (!section || !list) return;

        if (!State.currentDraftId) {
            section.classList.add('hidden');
            return;
        }

        clearNode(list);

        let chats;
        try {
            chats = await api.getDraftChats(State.currentDraftId);
        } catch {
            section.classList.add('hidden');
            return;
        }

        if (!Array.isArray(chats) || chats.length === 0) {
            section.classList.add('hidden');
            return;
        }

        section.classList.remove('hidden');
        chats.forEach(chat => list.appendChild(_renderChatRow(chat)));
    }

    /**
     * Build one row in the AI Chats banner.
     *
     * @param {Object} chat  — {id, title, ...} from the server
     * @returns {HTMLElement}
     */
    function _renderChatRow(chat) {
        const row = el('div', 'ai-chat-row');

        const titleEl = el('span', 'ai-chat-title', txt(chat.title || ''));

        const openBtn = iconBtn('comment', t('GRAFIDA_BTN_OPEN'), 'btn', 'btn-sm', 'btn-secondary');
        openBtn.title = t('GRAFIDA_BTN_OPEN');
        openBtn.addEventListener('click', async () => { await _openSavedChat(chat.id); });

        const renameBtn = iconBtn('pen', t('GRAFIDA_BTN_RENAME'), 'btn', 'btn-sm', 'btn-secondary');
        renameBtn.title = t('GRAFIDA_BTN_RENAME');
        renameBtn.addEventListener('click', async () => {
            const newTitle = await promptText(
                t('GRAFIDA_LBL_RENAME_CHAT'),
                t('GRAFIDA_LBL_CHAT_TITLE'),
                chat.title || '',
            );
            if (!newTitle) return;
            try {
                await api.updateAiChat(chat.id, { title: newTitle });
                showToast(t('GRAFIDA_MSG_CHAT_RENAMED'), 'success');
                await renderAiChatsBanner();
            } catch (err) {
                showToast(err.message, 'error');
            }
        });

        const delBtn = iconBtn('trash', t('GRAFIDA_BTN_DELETE'), 'btn', 'btn-sm', 'btn-danger');
        delBtn.title = t('GRAFIDA_BTN_DELETE');
        delBtn.addEventListener('click', async () => {
            const confirmed = await confirmYesNo(
                t('GRAFIDA_BTN_DELETE'),
                [el('p', null, txt(t('GRAFIDA_MSG_DELETE_CHAT_CONFIRM')))],
            );
            if (!confirmed) return;
            try {
                await api.deleteAiChat(chat.id);
                showToast(t('GRAFIDA_MSG_CHAT_DELETED'), 'success');
                await renderAiChatsBanner();
            } catch (err) {
                showToast(err.message, 'error');
            }
        });

        const actions = el('div', 'ai-chat-row-actions', openBtn, renameBtn, delBtn);
        row.appendChild(titleEl);
        row.appendChild(actions);
        return row;
    }

    /**
     * Load a saved chat by id: hydrate _history, mark the loaded position so
     * close can offer PATCH, and render.
     *
     * After loading, _docContextEmbedded is set to false so the first new message
     * the user sends re-embeds the current article context (the stored transcript
     * has it stripped).
     *
     * @param {number} chatId
     */
    async function _openSavedChat(chatId) {
        try {
            const chat = await api.getAiChat(chatId);
            _history = (chat.messages || []).map(m => ({ role: m.role, content: m.content }));
            // Re-embed doc context on the next user message so the AI has fresh
            // article content when the conversation continues.
            _docContextEmbedded = false;
            _loadedChatId       = chatId;
            _loadedHistoryLength = _history.length;
            _renderConversation();
            const conv = document.getElementById('ai-conversation');
            if (conv) conv.scrollTop = conv.scrollHeight;
        } catch (err) {
            showToast(err.message, 'error');
        }
    }

    /**
     * Called when the AI panel closes (from _closePanel, which awaits this).
     *
     * — If a saved chat was loaded and the user added new turns: offer to save
     *   the updated transcript via PATCH.
     * — If this is a fresh conversation with at least one user+assistant exchange:
     *   show the "Remember?" modal with a title field. On Remember:
     *     1. Auto-save the draft if State.currentDraftId is null.
     *     2. Auto-generate a title via a brief non-streaming sendChat if blank.
     *     3. POST /api/ai/chats to persist.
     */
    async function _onPanelClose() {
        const hasExchange = _history.some(m => m.role === 'user')
                         && _history.some(m => m.role === 'assistant');

        if (_loadedChatId !== null) {
            // Continuing a saved chat — capture id before clearing state.
            const chatId      = _loadedChatId;
            const hasNewTurns = _history.length > _loadedHistoryLength;
            _loadedChatId        = null;
            _loadedHistoryLength = 0;

            if (!hasNewTurns) return;

            const save = await confirmYesNo(
                t('GRAFIDA_MSG_SAVE_CHAT_CHANGES'),
                [el('p', null, txt(t('GRAFIDA_MSG_REMEMBER_CHAT_DESC')))],
            );
            if (!save) return;

            try {
                await api.updateAiChat(chatId, { messages: _buildStoredMessages() });
                showToast(t('GRAFIDA_MSG_CHAT_SAVED'), 'success');
            } catch (err) {
                showToast(err.message, 'error');
            }
            return;
        }

        if (!hasExchange) return;

        // Fresh conversation — offer to remember.
        const title = await _showRememberModal();
        if (title === null) return;  // Discard

        // Auto-save the draft if not yet persisted.
        if (State.currentDraftId == null) {
            try {
                await saveDraft();
            } catch {
                showToast(t('GRAFIDA_MSG_CHAT_SAVED'), 'error');
                return;
            }
        }
        if (State.currentDraftId == null) return;

        // Auto-generate title when blank.
        let chatTitle = title;
        if (!chatTitle) {
            chatTitle = await _autoGenerateTitle() || new Date().toLocaleString();
        }

        const serviceId = (_activeTool ? (_activeTool.serviceId || null) : null)
                       ?? State.aiDefaultServiceId
                       ?? null;

        try {
            await api.createAiChat({
                draftId:   State.currentDraftId,
                serviceId,
                title:     chatTitle,
                messages:  _buildStoredMessages(),
            });
            showToast(t('GRAFIDA_MSG_CHAT_SAVED'), 'success');
        } catch (err) {
            showToast(err.message, 'error');
        }
    }

    /**
     * Show the "Remember this conversation?" modal with a title field.
     *
     * Resolves to the trimmed title string (possibly empty, meaning "auto-generate")
     * when the user confirms, or null when they discard / close.
     *
     * @returns {Promise<string|null>}
     */
    function _showRememberModal() {
        return new Promise(resolve => {
            let settled = false;

            const input = el('input', 'form-control');
            input.type        = 'text';
            input.placeholder = t('GRAFIDA_PLACEHOLDER_CHAT_TITLE');

            const body = [
                el('p', null, txt(t('GRAFIDA_MSG_REMEMBER_CHAT_DESC'))),
                el('div', 'form-group',
                    el('label', null, txt(t('GRAFIDA_LBL_CHAT_TITLE'))),
                    input,
                ),
            ];

            const finish = (remember) => {
                if (settled) return;
                settled = true;
                document.removeEventListener('keydown', onKey, true);
                closeModal();
                resolve(remember ? input.value.trim() : null);
            };

            function onKey(e) {
                if (e.key === 'Enter')  { e.preventDefault(); finish(true);  }
                else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            }

            const rememberBtn = iconBtn('bookmark', t('GRAFIDA_BTN_REMEMBER'), 'btn', 'btn-primary');
            const discardBtn  = iconBtn('xmark',    t('GRAFIDA_BTN_DISCARD'),  'btn', 'btn-secondary');

            rememberBtn.addEventListener('click', () => finish(true));
            discardBtn.addEventListener('click',  () => finish(false));

            document.addEventListener('keydown', onKey, true);
            showModal(t('GRAFIDA_MSG_REMEMBER_CHAT'), body, [discardBtn, rememberBtn]);
            document.getElementById('modal-overlay').onclick = (e) => {
                if (e.target === e.currentTarget) finish(false);
            };
            setTimeout(() => input.focus(), 0);
        });
    }

    /**
     * Ask the AI to summarise the current conversation into a short 3-6 word title.
     * Returns the trimmed title string (quotes stripped), or null on failure.
     *
     * @returns {Promise<string|null>}
     */
    async function _autoGenerateTitle() {
        const serviceId = (_activeTool ? (_activeTool.serviceId || null) : null)
                       ?? State.aiDefaultServiceId;
        if (!serviceId) return null;

        const conversationTurns = _history.map(m => ({
            role:    m.role,
            content: m.role === 'user' ? _stripDocContext(m.content) : m.content,
        }));

        try {
            const result = await GrafidaAI.sendChat(
                serviceId,
                [
                    {
                        role:    'system',
                        content: 'Summarise this conversation into a short 3-6 word title. Return only the title.',
                    },
                    ...conversationTurns,
                ],
                { stream: false },
            );
            const text = (result.text || '').trim().replace(/^["']|["']$/g, '');
            return text || null;
        } catch {
            return null;
        }
    }

    /**
     * Build the list of message objects to store from the current _history.
     *
     * User messages have their document-context preamble stripped (so re-opening
     * the chat doesn't double-embed it). Only user and assistant roles are stored;
     * system messages are injected at runtime and never persisted.
     *
     * @returns {Array<{role: string, content: string, toolKey: null, sortOrder: number}>}
     */
    function _buildStoredMessages() {
        return _history
            .filter(m => m.role === 'user' || m.role === 'assistant')
            .map((m, i) => ({
                role:      m.role,
                content:   m.role === 'user' ? _stripDocContext(m.content) : m.content,
                toolKey:   null,
                sortOrder: i,
            }));
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
