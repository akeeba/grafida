-- Grafida schema update: per-site manual editor.css override.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- editor_css_url lets the user point Grafida straight at a template's editor
-- stylesheet when auto-discovery cannot find it (a template that bundles or
-- inlines its CSS, or serves it from an unconventional path). It is either an
-- absolute URL or a site-root-relative path; NULL means "discover it".
ALTER TABLE sites ADD COLUMN editor_css_url TEXT;
