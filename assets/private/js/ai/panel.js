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

    // -------------------------------------------------------------------------
    //  Multimodal document images
    // -------------------------------------------------------------------------

    /**
     * Most images we send per request. Each one costs tokens and request size,
     * and an illustrated article can carry dozens; the first few carry nearly
     * all the meaning, so the tail is dropped rather than sent.
     */
    const MAX_DOC_IMAGES = 8;

    /** Longest edge, in pixels, an image is downscaled to before sending. */
    const MAX_IMAGE_EDGE = 1024;

    /** Downscaled JPEG quality. */
    const IMAGE_QUALITY = 0.85;

    /**
     * Downscale a data: URI to at most MAX_IMAGE_EDGE on its longest side.
     *
     * Joomla bakes a photo's full intrinsic size into the tag, so an article
     * image is routinely 4000px wide — several megabytes of base64 per turn,
     * for a picture every vision model downsamples on arrival anyway.
     *
     * Falls back to the original URI if anything goes wrong (a decode failure,
     * a tainted canvas): sending an oversized image beats sending none.
     *
     * @param {string} dataUri
     * @returns {Promise<string>}
     */
    function _downscaleImage(dataUri) {
        return new Promise((resolve) => {
            const img = new Image();

            img.onload = () => {
                const longest = Math.max(img.naturalWidth, img.naturalHeight);

                if (!longest) { resolve(dataUri); return; }
                if (longest <= MAX_IMAGE_EDGE) { resolve(dataUri); return; }

                const scale  = MAX_IMAGE_EDGE / longest;
                const canvas = document.createElement('canvas');
                canvas.width  = Math.round(img.naturalWidth * scale);
                canvas.height = Math.round(img.naturalHeight * scale);

                try {
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    resolve(canvas.toDataURL('image/jpeg', IMAGE_QUALITY));
                } catch (e) {
                    resolve(dataUri);
                }
            };
            img.onerror = () => resolve(dataUri);
            img.src = dataUri;
        });
    }

    /**
     * Resolve one <img> in the article body to a base64 data: URI, or null when
     * its bytes cannot be reached.
     *
     * An article image comes in three flavours and only the first arrives with
     * its bytes in hand:
     *   - a data: URI          — pasted in a build before gh-36, or otherwise
     *                            embedded; use as-is;
     *   - an offline blob      — a local, not-yet-published image, referenced by
     *                            its boson://app/api/media/{id}/raw URL and
     *                            normally also tagged data-grafida-media-id;
     *                            GET /api/media/{id} for the bytes as a data: URI;
     *   - a plain URL           — already published; the webview cannot fetch it
     *                             (CORS / macOS ATS), so PHP does it for us.
     *
     * The blob branch reads the attribute first but falls back to parsing the id
     * out of the URL: the attribute is applied by app.js's SetContent/NodeChange
     * hook, so HTML set programmatically can reach here before that hook has run,
     * and the URL is the thing that actually rendered. This fallback also keeps a
     * local URL out of the published-image branch below — GET /api/sites/{id}/image
     * would rightly refuse a boson:// URL (SiteImageFetcher only fetches from the
     * site's own host), but asking costs a round trip through the single-threaded
     * boson:// kernel, per image, for a guaranteed refusal.
     *
     * @param {HTMLImageElement} img
     * @param {number|null} siteId
     * @returns {Promise<string|null>}
     */
    async function _resolveImageSource(img, siteId) {
        const src = img.getAttribute('src') || '';

        if (src.startsWith('data:')) {
            return src.startsWith('data:image/') ? src : null;
        }

        const local = window.GrafidaLocalMedia || null;
        const tagged = img.getAttribute('data-grafida-media-id');
        const mediaId = tagged ? parseInt(tagged, 10) : (local ? local.idFromUrl(src) : null);

        if (mediaId) {
            try {
                const res = await api.getMediaBlob(mediaId);
                return res.dataUri || null;
            } catch (e) {
                return null;
            }
        }

        // An untagged, unparseable local URL: there is nothing to fetch and the
        // site-image branch cannot help, so stop here rather than round-tripping.
        if (local && src.startsWith(local.PREFIX)) return null;

        if (!src || siteId == null) return null;

        try {
            const res = await api.getSiteImage(siteId, src);
            return res.dataUri || null;
        } catch (e) {
            // Off-site, deleted, or not an image. One unreachable picture must
            // never fail the whole message — the rest still go.
            return null;
        }
    }

    /**
     * Collect the article body's images as downscaled base64 data: URIs, in
     * document order, for a multimodal request.
     *
     * @returns {Promise<Array<string>>} possibly empty; never rejects
     */
    async function _collectDocumentImages() {
        const editor = State.tinyMCEEditor;
        const body   = editor ? editor.getBody() : null;

        if (!body) return [];

        const imgs   = Array.from(body.querySelectorAll('img')).slice(0, MAX_DOC_IMAGES);
        const siteId = State.currentSiteId;
        const out    = [];

        for (const img of imgs) {
            const uri = await _resolveImageSource(img, siteId);
            if (uri) out.push(await _downscaleImage(uri));
        }

        return out;
    }

    /**
     * In-memory conversation history.
     * Entries: { role: 'user' | 'assistant', content: string, tool?: boolean,
     *            images?: string[] }
     * The document context is embedded inside the first user message's content
     * so it persists automatically across follow-up turns.
     *
     * `tool` marks a user turn that carries a writing tool's prompt rather than
     * something the user typed; it is display-only and never sent to a provider.
     *
     * `images` carries the article's pictures as base64 data: URIs on the first
     * user turn, for a service with the `multimodal` param on. It rides
     * alongside `content` — which stays a plain string — so nothing else that
     * reads a turn (rendering, saved chats, .grafida export) has to know about
     * it; providers.js folds it into the wire format. It is NOT persisted with
     * a remembered chat: the images belong to the article, which is re-read on
     * every fresh conversation anyway.
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

    /**
     * Responses-API chain state (an optimisation only — _history remains the
     * authoritative record of the conversation; see _sendMessage).
     *   _previousResponseId — the provider's response id from the last turn, or null.
     *   _chainServiceId     — the service id that produced it (a chain from service A
     *                         is meaningless to service B), or null.
     *   _lastResponseAt     — ISO timestamp of the last response, for the retention window.
     */
    let _previousResponseId = null;
    let _chainServiceId     = null;
    let _lastResponseAt     = null;

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

        // In fullscreen the panel sits behind the editor overlay, so a click on the
        // toolbar button means "show me the assistant" — never "close it". Leave
        // fullscreen and make sure the panel ends up open, whatever its state was.
        // An already-open panel is revealed as-is: _openPanel() resets the
        // conversation, which would wipe a chat started before going fullscreen.
        if (_exitEditorFullscreen()) {
            if (panel.classList.contains('hidden')) _openPanel(null);
            return;
        }

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
        _exitEditorFullscreen();

        _activeTool = tool;
        _history = [];
        _docContextEmbedded = false;
        _loadedChatId = null;
        _loadedHistoryLength = 0;
        _previousResponseId = null;
        _chainServiceId = null;
        _lastResponseAt = null;
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
        _previousResponseId = null;
        _chainServiceId = null;
        _lastResponseAt = null;
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

    /**
     * Leave TinyMCE's fullscreen mode, if the editor is currently in it.
     *
     * The #ai-panel is docked in the app layout, *outside* the TinyMCE container,
     * while fullscreen paints the editor over the whole viewport at a high z-index.
     * A panel opened while fullscreen is on is therefore invisible behind the
     * overlay — the button looks like it does nothing. Every entry point that shows
     * the panel drops out of fullscreen first.
     *
     * @returns {boolean} true if the editor was in fullscreen (and has now left it).
     */
    function _exitEditorFullscreen() {
        const editor = State.tinyMCEEditor;
        // The plugin API is absent in inline mode and before init: probe defensively.
        if (!editor || !editor.plugins || !editor.plugins.fullscreen) return false;
        if (!editor.plugins.fullscreen.isFullscreen()) return false;

        editor.execCommand('mceFullScreen');   // the command is a toggle
        return true;
    }

    function _openPanel(tool) {
        _activeTool = tool || null;
        _history = [];
        _docContextEmbedded = false;
        _loadedChatId = null;
        _loadedHistoryLength = 0;
        _previousResponseId = null;
        _chainServiceId = null;
        _lastResponseAt = null;
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

        // Claim the conversation NOW, before the first await. Everything below —
        // loading the tools, and especially fetching the article's images — is
        // asynchronous, so leaving the panel idle-looking until the request goes
        // out lets a second Send re-enter this function and fire a duplicate
        // request. `_streaming` is the flag `_onSendClick` and this guard read.
        _setStreaming(true);

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
        const svc = State.aiServices.find(s => s.id === serviceId);

        let userContent  = userText;
        const firstTurn  = !_docContextEmbedded;
        if (firstTurn) {
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

        // Add the user turn and re-render so it appears immediately. This must
        // happen BEFORE the images are gathered: that walks the article and can
        // spend seconds fetching published pictures back off the site, and a
        // panel that shows nothing while it runs reads as a dropped click.
        const userTurn = { role: 'user', content: userContent, tool: !!tool };
        _history.push(userTurn);
        _renderConversation();

        // Clear the textarea for manual (non-tool) messages.
        if (!tool) {
            const inputEl = document.getElementById('ai-input');
            if (inputEl) inputEl.value = '';
        }

        // A model that can see gets the article's pictures alongside its HTML; a
        // text-only one would reject the request outright, which is why this is
        // opt-in per service rather than inferred.
        if (firstTurn && svc && svc.params && svc.params.multimodal) {
            const docImages = await _collectDocumentImages();
            if (docImages.length) userTurn.images = docImages;
        }

        // Assemble the full messages array.
        const messages = [];
        if (systemContent) {
            messages.push({ role: 'system', content: systemContent });
        }
        // `tool` is a display-only marker and never goes on the wire; `images`
        // does, and providers.js folds it into the dialect's content shape.
        messages.push(..._history.map((m) => {
            const wire = { role: m.role, content: m.content };
            if (m.images && m.images.length) wire.images = m.images;
            return wire;
        }));

        // Respect the service's `stream` param (default: true).
        const wantStream = !(svc && svc.params && svc.params.stream === false);

        // Chain onto the prior response (Responses-API optimisation only —
        // _history stays the authoritative record) when all of these hold:
        //   1. we have a previous response id;
        //   2. it came from this same service (a chain from service A is
        //      meaningless to service B — a tool can target a different service);
        //   3. it is still within the service's retention window.
        const retentionDays = (svc && svc.params && svc.params.store_retention_days != null)
            ? svc.params.store_retention_days
            : 15;
        const withinRetention = _lastResponseAt != null
            && (Date.now() - Date.parse(_lastResponseAt)) <= retentionDays * 86400000;
        const useChain = _previousResponseId != null
            && _chainServiceId === serviceId
            && withinRetention;
        const previousResponseId = useChain ? _previousResponseId : null;

        // Already flagged before the awaits above; this is where the reply
        // itself starts.
        _streamingBubble = _appendStreamingBubble();
        const streamRenderer = _streamingBubble
            ? _createStreamRenderer(_streamingBubble.querySelector('.ai-bubble-text'))
            : null;
        _abortCtrl = GrafidaAI.newAbort();

        let fullText = '';
        // Created on the first reasoning delta — a non-reasoning model never
        // emits one, and then no thinking block ever appears.
        let thinkingBlock = null;

        try {
            const result = await GrafidaAI.sendChat(serviceId, messages, {
                stream:   wantStream,
                signal:   _abortCtrl.signal,
                toolKey:  tool ? (tool.toolKey || '') : '',
                previousResponseId,
                onThinking: (delta) => {
                    if (!_streamingBubble) return;
                    if (!thinkingBlock) thinkingBlock = _createThinkingBlock(_streamingBubble);
                    thinkingBlock.push(delta);
                    const conv = document.getElementById('ai-conversation');
                    if (conv) conv.scrollTop = conv.scrollHeight;
                },
                onToken:  (delta) => {
                    fullText += delta;
                    // The reply has begun, so the model is no longer thinking —
                    // settle the block before the first word lands. Idempotent.
                    if (thinkingBlock) thinkingBlock.finish();
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

            // Update the chain optimisation state. _history above already holds
            // the authoritative transcript regardless of what happens here.
            _previousResponseId = result.responseId || null;
            _chainServiceId     = serviceId;
            _lastResponseAt     = new Date().toISOString();

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
            // An aborted response may be stored partial or cancelled on the
            // provider's side, and a provider error already retracts the user
            // turn from _history — either way the server-side chain no longer
            // mirrors _history, so drop the optimisation and let the next turn
            // resend the full transcript.
            _previousResponseId = null;
            _chainServiceId     = null;
            _lastResponseAt     = null;

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
            // Covers a response that ended (or was aborted) while still thinking,
            // i.e. with no reply token to settle the block.
            if (thinkingBlock) thinkingBlock.finish();
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
                conv.appendChild(_buildUserBubble(msg.content, _isToolPrompt(msg)));
            } else if (msg.role === 'assistant') {
                conv.appendChild(_buildAssistantBubble(msg.content));
            }
        });

        conv.scrollTop = conv.scrollHeight;
    }

    /**
     * Whether a user turn carries a writing tool's prompt rather than something
     * the user typed.
     *
     * The in-session marker set by `_sendMessage` is authoritative. A chat
     * reloaded from the database has no marker (only role + content are stored),
     * so fall back to matching the text against the configured tools' prompts —
     * `openWithTool` sends a tool's prompt verbatim as the first user turn.
     *
     * @param {{content: string, tool?: boolean}} msg
     * @returns {boolean}
     */
    function _isToolPrompt(msg) {
        if (msg.tool) return true;

        const text = _stripDocContext(msg.content || '').trim();
        if (!text) return false;

        return (State.aiAllTools || []).some(tool => (tool.prompt || '').trim() === text);
    }

    /**
     * Build a user-message bubble with the prompt rendered as *formatted* text.
     *
     * Tool prompts (and many hand-typed prompts) are Markdown, so the prompt is
     * run through the same CommonMark + sanitiser pipeline as assistant replies
     * (`_renderRichText`) rather than shown as raw Markdown. The embedded
     * document-context preamble is stripped first so only the actual query shows.
     *
     * A tool's prompt is machinery the user did not write, and it is long — on
     * the accent background it dominated the panel. It gets the muted
     * "instructions" treatment plus a header naming what it is instead.
     *
     * @param {string}  content
     * @param {boolean} isToolPrompt
     * @returns {HTMLElement}
     */
    function _buildUserBubble(content, isToolPrompt) {
        const bubble = el('div', 'ai-bubble ' + (isToolPrompt ? 'ai-bubble-instructions' : 'ai-bubble-user'));

        if (isToolPrompt) {
            bubble.appendChild(el(
                'div',
                'ai-bubble-instructions-header',
                icon('wand-magic-sparkles'),
                txt(t('GRAFIDA_LBL_AI_INSTRUCTIONS'))
            ));
        }

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
     * Create the collapsible "thinking" block at the top of a streaming bubble.
     *
     * A reasoning model can spend a long time on its scratchpad before the first
     * word of the reply arrives, which reads as a stuck panel. The block is the
     * visible sign of life: a brain icon + a pulsing "Thinking…" line, which the
     * user can click to unfold the scratchpad itself and inspect it.
     *
     * The thinking text lives OUTSIDE `.ai-bubble-text`, so the reply's own
     * streaming renderer never touches it and — because it is also never
     * accumulated into the reply string — Insert and Copy cannot pick it up. It
     * is likewise not pushed to `_history`, so it is neither resent to the
     * provider nor saved with a remembered chat: it is a transient view of the
     * current response.
     *
     * The scratchpad is Markdown as often as the reply is, so it gets its OWN
     * stream renderer and is formatted through the same `POST /api/ai/render`
     * (CommonMark + sanitiser) pipeline as a bubble — raw `**bold**` and `#`
     * headings in the panel are noise, not information.
     *
     * Rendering is deferred while the block is collapsed: each render is a
     * round-trip through the single-threaded `boson://` kernel, and formatting
     * text nobody is looking at would compete with the reply's own renders. The
     * accumulated text is kept as an always-safe `textContent` placeholder, so
     * unfolding shows it instantly and the formatting lands a moment later.
     *
     * @param {HTMLElement} bubble
     * @returns {{push: function(string): void, finish: function(): void}}
     */
    function _createThinkingBlock(bubble) {
        const label  = el('span', 'ai-thinking-label', txt(t('GRAFIDA_LBL_AI_THINKING')));
        const toggle = el('button', 'ai-thinking-toggle', icon('brain'), label);
        toggle.type = 'button';
        toggle.setAttribute('aria-expanded', 'false');

        const textEl = el('div', 'ai-thinking-text');
        textEl.hidden = true;

        const block = el('div', 'ai-thinking ai-thinking-active', toggle, textEl);
        bubble.insertBefore(block, bubble.firstChild);

        let thoughts      = '';
        let expanded      = false;
        let done          = false;
        let finalRendered = false;

        // The block scrolls internally (it is capped in height), and it can be
        // unfolded long after its message scrolled up the conversation — so it
        // must never drag the conversation view with it.
        const renderer = _createStreamRenderer(textEl, {
            scrollConversation: false,
            onApply: () => { if (expanded) textEl.scrollTop = textEl.scrollHeight; },
        });

        /** Format what we have so far — only worth doing while it is on screen. */
        function render() {
            if (!expanded || !thoughts) return;
            if (!done) {
                renderer.push(thoughts);
                return;
            }
            // finish() ends the renderer's throttle loop, so it is only correct
            // once nothing more can arrive — and only useful once.
            if (finalRendered) return;
            finalRendered = true;
            renderer.finish(thoughts);
        }

        toggle.addEventListener('click', () => {
            expanded      = !expanded;
            textEl.hidden = !expanded;
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (!expanded) return;
            render();  // first unfold: nothing has been formatted yet
            textEl.scrollTop = textEl.scrollHeight;
        });

        return {
            push(delta) {
                thoughts += delta;
                // Always-safe placeholder, until the first formatted render owns
                // the element (mirrors how a reply bubble streams).
                if (!renderer.hasApplied()) textEl.textContent = thoughts;
                render();
                if (expanded) textEl.scrollTop = textEl.scrollHeight;
            },
            finish() {
                // The reply has started (or the response ended): stop the pulse and
                // relabel — it is a record of how the answer was reached, not
                // something still happening.
                if (done) return;
                done = true;
                block.classList.remove('ai-thinking-active');
                label.textContent = t('GRAFIDA_LBL_AI_THOUGHT_PROCESS');
                render();  // authoritative final render, if anyone is looking
            },
        };
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
            // The reply is often Markdown (the Generate tool) or loose HTML.
            // TinyMCE needs real HTML, so convert it through the same
            // Markdown-vs-HTML render/sanitise pipeline that formats the bubbles
            // (POST /api/ai/render). Fall back to the raw text if that fails.
            api.aiRender(content)
                .then((res) => {
                    const html = res && typeof res.html === 'string' ? res.html : null;
                    editor.insertContent(html !== null ? html : content);
                    editor.focus();
                })
                .catch(() => {
                    editor.insertContent(content);
                    editor.focus();
                });
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
     * Copy uses the raw `content`; Insert re-renders it to HTML through the same
     * pipeline before dropping it into TinyMCE (see `_addBubbleActions`).
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
     * @param {Object}      [opts]
     * @param {boolean}     [opts.scrollConversation=true] keep the conversation pinned to
     *                                                     the bottom as the text grows. Off
     *                                                     for a block the user may unfold
     *                                                     long after the fact — re-rendering
     *                                                     it must not yank the view.
     * @param {Function}    [opts.onApply]                 called after each applied render
     * @returns {{push: function(string): void, finish: function(string): void, hasApplied: function(): boolean}}
     */
    function _createStreamRenderer(textEl, opts) {
        const { scrollConversation = true, onApply = null } = opts || {};
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
            if (scrollConversation) {
                const conv = document.getElementById('ai-conversation');
                if (conv) conv.scrollTop = conv.scrollHeight;
            }
            if (onApply) onApply();
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
        _previousResponseId  = null;
        _chainServiceId      = null;
        _lastResponseAt      = null;
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
            // Hydrate the chain optimisation state; eligibility (service match +
            // retention window) is re-decided per-send in _sendMessage, so a chat
            // reopened after the window simply resends its full history.
            _previousResponseId = chat.previousResponseId || null;
            _chainServiceId     = chat.serviceId != null ? chat.serviceId : null;
            _lastResponseAt     = chat.lastResponseAt || null;
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
                await api.updateAiChat(chatId, {
                    messages:           _buildStoredMessages(),
                    previousResponseId: _previousResponseId,
                    lastResponseAt:     _lastResponseAt,
                });
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
                draftId:            State.currentDraftId,
                serviceId,
                title:              chatTitle,
                messages:           _buildStoredMessages(),
                previousResponseId: _previousResponseId,
                lastResponseAt:     _lastResponseAt,
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
         * Open the panel with a fresh, empty chat ready for a typed prompt.
         * Always opens (never toggles closed) and focuses the input — wired to
         * the "Custom…" item of the TinyMCE 'aitools' menu button so a new user
         * can discover that they may ask anything, not only run a preset tool.
         */
        openCustom() {
            _exitEditorFullscreen();

            const panel = document.getElementById('ai-panel');
            if (!panel) return;
            if (panel.classList.contains('hidden')) {
                _openPanel(null);
            } else {
                const inputEl = document.getElementById('ai-input');
                if (inputEl) inputEl.focus();
            }
        },

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
