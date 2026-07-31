-- Grafida schema update: per-site "Unicode Aliases" override (gh-61).
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- Whether the site has Joomla's "Unicode Aliases" Global Configuration option
-- turned on, as far as Grafida is concerned:
--   'auto' : read it from the site (needs a Super User token), falling back to
--            Joomla's own default, off. NULL/empty means the same thing.
--   'yes'  : force it on.
--   'no'   : force it off.
-- The override exists because GET v1/config/application needs core.admin, so a
-- less privileged token can never read the real value.
ALTER TABLE sites ADD COLUMN unicode_aliases TEXT;
