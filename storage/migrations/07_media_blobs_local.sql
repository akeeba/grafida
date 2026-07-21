-- Grafida schema update: manage offline media blobs instead of write-once
-- upload fodder (gh-36).
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- Inline article images are no longer embedded as `data:` URIs inside
-- `drafts.html` (megabytes of base64 sitting in the DOM, the source editor
-- and the DB column); they are referenced by a local `boson://app/api/
-- media/{id}/raw` URL instead, and the bytes stay exactly where they always
-- lived — a `media_blobs` row. That row now has to serve a managed object
-- (listed, renamed, cropped/resized, deleted from the Local Media tab, and
-- cache-busted after an edit) rather than a fire-and-forget upload staging
-- area, hence the new columns.
--
-- Like 04_ai_chat_response_chain.sql this file is bare `ALTER TABLE ADD
-- COLUMN` statements and is NOT re-runnable; that is fine because
-- `schema_migrations` tracks it by file name and runs it exactly once.

-- Revision stamp: bumped whenever the blob's bytes change (upload, in-app
-- crop/resize). Drives both the Local Media list's sort order and the
-- `?rev=` cache-busting query parameter the raw endpoint's URL carries
-- (mirroring `mediaDisplayUrl()`'s `grafida_rev` trick for gh-4) — the
-- webview must never keep painting a stale copy of an edited image.
-- Nullable: a blob stored before this migration has no revision yet, and
-- code must fall back to `created_at`.
ALTER TABLE media_blobs ADD COLUMN updated_at TEXT;

-- Intrinsic pixel dimensions, captured at store/replace time so the Local
-- Media grid and the publish-time <img> never need to decode the blob just
-- to size it. Nullable: some formats (SVG, some AVIF/WebP) fail to report
-- dimensions via getimagesize(), and pre-migration rows have none.
ALTER TABLE media_blobs ADD COLUMN width  INTEGER;
ALTER TABLE media_blobs ADD COLUMN height INTEGER;

-- Byte length, shown in the Local Media list. A column avoids reading the
-- (potentially multi-megabyte) blob into PHP just to report its size;
-- nullable for the same pre-migration reason as width/height.
ALTER TABLE media_blobs ADD COLUMN size INTEGER;

-- The Local Media tab and the legacy-draft migration (step 6) both look up
-- a site's or a draft's blobs; index the foreign key used for the latter
-- (site_id already has idx_media_site from 01_initial_schema.sql).
CREATE INDEX IF NOT EXISTS idx_media_draft ON media_blobs(draft_id);
