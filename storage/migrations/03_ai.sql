-- Grafida schema update: AI services, tools, and chats.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- A configured AI provider connection. secret_ref/insecure_key mirror
-- sites.secret_ref/insecure_token: the API key normally lives in the OS keychain;
-- plaintext fallback only when no secure store is available and the user opts in.
-- params_json holds extra model parameters: temperature, top_p,
-- max_completion_tokens, stream, etc.
CREATE TABLE IF NOT EXISTS ai_services (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    provider     TEXT    NOT NULL,
    endpoint     TEXT    NOT NULL DEFAULT '',
    model        TEXT    NOT NULL DEFAULT '',
    params_json  TEXT    NOT NULL DEFAULT '{}',
    secret_ref   TEXT,
    insecure_key TEXT,
    is_default   INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

-- Per-tool overrides and custom tools. Built-in tool defaults live in code;
-- this table stores deviations from those defaults and user-defined custom tools.
CREATE TABLE IF NOT EXISTS ai_tools (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tool_key        TEXT    NOT NULL UNIQUE,
    title           TEXT    NOT NULL DEFAULT '',
    icon            TEXT    NOT NULL DEFAULT '',
    prompt          TEXT    NOT NULL DEFAULT '',
    override_system INTEGER NOT NULL DEFAULT 0,
    tone            TEXT    NOT NULL DEFAULT '',
    params_json     TEXT    NOT NULL DEFAULT '{}',
    service_id      INTEGER REFERENCES ai_services(id) ON DELETE SET NULL,
    is_custom       INTEGER NOT NULL DEFAULT 0,
    enabled         INTEGER NOT NULL DEFAULT 1,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

-- A remembered AI conversation, linked to a draft. Deleting the draft cascades
-- to its chats (and their messages, via the next table).
CREATE TABLE IF NOT EXISTS ai_chats (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    draft_id   INTEGER NOT NULL REFERENCES drafts(id) ON DELETE CASCADE,
    service_id INTEGER REFERENCES ai_services(id) ON DELETE SET NULL,
    title      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_chats_draft ON ai_chats(draft_id);

-- Transcript turns (user/assistant only). System prompt and document context are
-- injected at call time and are not stored here.
CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id    INTEGER NOT NULL REFERENCES ai_chats(id) ON DELETE CASCADE,
    role       TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    tool_key   TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_chat_messages_chat ON ai_chat_messages(chat_id);
