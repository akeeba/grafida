-- Grafida initial database schema.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later.

-- Application-wide key/value settings (e.g. UI language override).
CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT
);

-- Connected Joomla sites.
--   base_url       : bare site root entered by the user (normalised, no trailing /).
--   api_base       : the working API base discovered by the connection test,
--                    e.g. https://example.com/index.php/api  (NULL until tested).
--   secret_ref     : opaque identifier used to look up the API token in the OS
--                    secret store (NULL when the token is stored insecurely).
--   insecure_token : API token stored in plaintext in this database, ONLY used
--                    when the OS secret store is unavailable and the user opted in.
CREATE TABLE IF NOT EXISTS sites (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    base_url        TEXT    NOT NULL,
    api_base        TEXT,
    secret_ref      TEXT,
    insecure_token  TEXT,
    default_language TEXT   NOT NULL DEFAULT '*',
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

-- Locally stored article drafts. A draft may correspond to a remote article
-- (remote_id set) or be brand new (remote_id NULL).
CREATE TABLE IF NOT EXISTS drafts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id      INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    remote_id    INTEGER,
    title        TEXT    NOT NULL DEFAULT '',
    alias        TEXT    NOT NULL DEFAULT '',
    catid        INTEGER,
    access       INTEGER NOT NULL DEFAULT 1,
    language     TEXT    NOT NULL DEFAULT '*',
    state        INTEGER NOT NULL DEFAULT 1,
    html         TEXT    NOT NULL DEFAULT '',
    fields_json  TEXT    NOT NULL DEFAULT '{}',
    tags_json    TEXT    NOT NULL DEFAULT '[]',
    images_json  TEXT    NOT NULL DEFAULT '{}',
    metadesc     TEXT    NOT NULL DEFAULT '',
    metakey      TEXT    NOT NULL DEFAULT '',
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_drafts_site ON drafts(site_id);

-- Per-site reference data cache (categories, tags, access levels, fields).
-- `kind` is one of: categories, tags, levels, fields. `payload` is JSON.
CREATE TABLE IF NOT EXISTS reference_cache (
    site_id    INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL,
    payload    TEXT    NOT NULL,
    fetched_at TEXT    NOT NULL,
    PRIMARY KEY (site_id, kind)
);

-- Per-site cached editor.css (already URL-rebased to absolute paths).
CREATE TABLE IF NOT EXISTS editor_css_cache (
    site_id    INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    css        TEXT    NOT NULL,
    fetched_at TEXT    NOT NULL
);

-- Images inserted while editing offline. Stored as raw bytes; embedded into the
-- article HTML as data: URIs carrying data-grafida-media-id="<id>". On publish
-- these are uploaded via the Media API and their data: URIs replaced.
CREATE TABLE IF NOT EXISTS media_blobs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    draft_id    INTEGER REFERENCES drafts(id) ON DELETE CASCADE,
    filename    TEXT    NOT NULL,
    mime        TEXT    NOT NULL,
    data        BLOB    NOT NULL,
    remote_path TEXT,
    remote_url  TEXT,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_media_site ON media_blobs(site_id);
