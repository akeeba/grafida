-- Grafida schema update: per-site media upload target (gh-57).
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- Where a published article's images are uploaded to.
--   media_adapter : the Media Manager filesystem, as the adapters API reports it
--                   ("local-images:/"). NULL/empty means "resolve automatically",
--                   i.e. prefer local-images, else the site's first adapter.
--   media_folder  : the folder inside that filesystem, relative to its root.
--                   NULL/empty means the built-in default ("grafida").
ALTER TABLE sites ADD COLUMN media_adapter TEXT;
ALTER TABLE sites ADD COLUMN media_folder TEXT;
