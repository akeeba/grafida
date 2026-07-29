# Translation

Grafida's interface is translated into several languages, and adding another needs no code change
at all. This page describes how the translations are put together, the glossaries that keep them
consistent, and how to add a new language or improve an existing one.

The languages shipped today are English (United Kingdom) — the source — plus Greek, French, German,
Spanish, Italian and Portuguese (Portugal).

> [!NOTE]
> This applies to the **interface**: menus, buttons, labels and messages. The documentation you are
> reading is English only, deliberately — it is a single source shared with the project's GitHub
> wiki, which has a flat page namespace with nowhere to put a translated set.

## The language files

Each language is a **single INI file** in its own directory, named after its BCP 47 tag:

```
language/
    en-GB/en-GB.ini
    de-DE/de-DE.ini
    el-GR/el-GR.ini
    …
```

The format is the Joomla INI format, so it will look familiar if you have translated a Joomla
extension:

```ini
; A comment starts with a semicolon.
GRAFIDA_BTN_SAVE="Save"
GRAFIDA_MSG_DELETE_AI_TOOL_CONFIRM="Delete AI tool \"%s\"? This cannot be undone."
```

The rules are few:

* One `KEY="Value"` per line. **The double quotes are not optional.**
* Keys are ASCII, upper case, and identical in every language. Never translate a key.
* A literal double quote inside a value is escaped as `\"`.
* Files are UTF-8, without a byte-order mark.
* `%s`, `%1$s`, `%d` and friends are **placeholders** and must survive translation. Where a string
  has more than one, the numbered form (`%1$s`, `%2$s`) lets you reorder them for your language's
  word order — that is exactly what it is there for.

> [!IMPORTANT]
> Never build a sentence by gluing fragments around a value. Grafida keeps each message as one
> string with placeholders in it, precisely so that a translator controls word order, punctuation
> and the position of the value. If you find a string that reads as half a sentence, that is a bug
> worth reporting.

### `GRAFIDA_LANGUAGE_ENDONYM` is mandatory

Every language file must carry this key, holding the language's name **in its own tongue**:

```ini
GRAFIDA_LANGUAGE_ENDONYM="Français (France)"
```

Grafida does not have a hard-coded list of languages. On startup it scans the `language/` directory
for every `<tag>/<tag>.ini` and reads this key to label the entry in the Interface language
drop-down on the [Settings](Settings) screen. A file without it is not usable.

This is also why adding a language needs no code change and no manifest entry. There is no
`.sys.ini`, no XML manifest and no registration step: Grafida is a desktop application, not a
Joomla extension.

### What happens when a string is missing

Lookups fall back in order: **the chosen language → en-GB → the key itself**. So a partial
translation is perfectly usable — untranslated strings simply appear in English — and a typo in a
key shows up loudly as a bare `GRAFIDA_SOMETHING_OR_OTHER` on screen.

The Interface language setting also has an **(Auto-detect)** option, which reads `LC_ALL`,
`LC_MESSAGES` or `LANG` from the environment. A bare language (`fr`) matches the first shipped tag
that starts with it (`fr-FR`).

## The glossaries

Under `build/glossaries/` there is one Markdown file per language, `<tag>.md`, holding a table of
the terms that must be translated the same way everywhere. Here's a short excerpt from the German glossary:

| Englisch (Quellbegriff) | Deutsch | Anmerkungen |
|---|---|---|
| article | Beitrag | Offizieller Joomla!-Begriff (JGLOBAL_ARTICLES = „Beiträge", nicht „Artikel") |
| tag | Schlagwörter | Offizieller Joomla!-Begriff (JTAG = „Schlagwörter"), nicht „Tag" |

These are not suggestions. They are the reason a term does not drift between two screens translated
months apart, and the reason the vocabulary matches what the person at the keyboard already sees in
their Joomla back end.

> [!IMPORTANT]
> **The core terms were taken from the official Joomla translations, not invented.** Where Grafida
> talks about something Joomla also talks about — article, category, tag, access level, featured,
> read more, intro image, full article image — the glossary records the term the official Joomla
> language pack uses, and cites the Joomla language key it came from.
>
> The German example above is the point in miniature. Joomla's German pack renders *article* as
> **Beitrag**, not the literal *Artikel*, and *tag* as **Schlagwort**, not the loanword *Tag*.
> Someone editing a Joomla site in German reads "Beiträge" in the back end; Grafida saying anything
> else would be Grafida's own private dialect.

Where a term is Grafida's own — *local article*, *slash commands*, *request log* — the glossary
records the decision and, often, why an earlier wording was dropped.

Proper nouns are never translated: Grafida, Joomla!, API, HTML, Markdown, TinyMCE.

## Machine translation, and what it is not

The shipped translations were produced with the help of a large language model, checked against the
glossary, and are maintained the same way. This is stated plainly rather than hidden: they are good
enough to use, and they are not the work of a native-speaker translator who knows the application.

That has two consequences.

**Corrections are genuinely welcome**, especially from people who use Grafida in that language every
day. A wording that is technically correct but nobody would actually say is a real bug; please
report it.

**Every correction belongs in the glossary too.** If you change how a term is translated, change it
in `build/glossaries/<tag>.md` in the same breath, otherwise the next translation run — machine or
human — will helpfully change it back.

## Improving an existing language

1. Open `language/<tag>/<tag>.ini` and `build/glossaries/<tag>.md` side by side.
2. Make the change. If it involves a term rather than a one-off phrase, update the glossary row —
   or add one — including a short note saying why.
3. Check that the placeholders in the value still match the English source exactly, in kind if not
   in order.
4. Test it (below), then open a pull request.

If the string you are fixing is wrong because the **English** is wrong, say so: the English source
is not sacred, and fixing it there fixes it for everybody.

## Adding a new language

1. Copy `language/en-GB/en-GB.ini` to `language/<tag>/<tag>.ini`, using the BCP 47 tag for your
   language and region, e.g. `nl-NL`.
2. Set `GRAFIDA_LANGUAGE_ENDONYM` to the language's name in its own tongue.
3. Create `build/glossaries/<tag>.md` and settle the core terms **first**, before translating the
   body of the file. Start from the official Joomla language pack for your language; a term that
   Joomla already translates should be translated the same way here.
4. Translate the values, leaving the keys alone.
5. Test it (below).
6. Open a pull request with both the INI file and the glossary.

That is the whole of it for the application itself. Two optional extras are worth knowing about:

**The editor's own interface.** TinyMCE ships its own language packs, and Grafida picks the one
matching your interface language. If a pack exists for your language, adding it needs two small
code changes: the pack's file name in the `tinymce-i18n` list in `composer.json`, and an entry in
`TINYMCE_LANGS` in `assets/private/js/app.js` mapping your tag to the pack's code. Without them the
editor's own menus stay English while the rest of Grafida is translated — which works, and is what
happens for any language TinyMCE has no pack for.

**The spell checker** is not ours to translate. It is your operating system's, and the dictionary it
uses is an OS setting — see [Editing Articles](Editing-Articles#spell-checking).

## Testing a translation

### From a source checkout

The straightforward way. Grafida reads `language/` straight out of the project tree, so your file is
picked up as soon as you restart the application. Choose it under Interface language in
[Settings](Settings), or leave the setting on (Auto-detect) and start Grafida with the environment
variable set:

```bash
LANG=nl-NL.UTF-8 php index.php
```

### Against an installed build

A compiled Grafida keeps its language files inside the binary and extracts them once, on first run,
into a `resources/language/` folder under its data directory:

| Platform | Data directory |
|---|---|
| macOS | `~/Library/Application Support/Grafida/` |
| Windows | `%APPDATA%\Grafida\` |
| Linux | `$XDG_DATA_HOME/grafida/` (usually `~/.local/share/grafida/`) |

Dropping a **new** `<tag>/<tag>.ini` into `resources/language/` there works: Grafida never deletes
files it did not put there, so your language is discovered on the next start and appears in the
drop-down. This is the quickest way to try a translation without building anything.

> [!WARNING]
> Editing one of the **shipped** files in that extracted folder is not reliable. On every start
> Grafida re-copies any bundled file whose extracted copy differs in size from the one inside the
> binary, so your edits will usually be overwritten — silently, and only sometimes, which is worse
> than always. Edit a source checkout instead, or test your change as a new tag.

### What to look for

* Every screen, not just the obvious ones: the Sites and Articles lists, the editor's properties
  sidebar, [Settings](Settings), the confirmation dialogs, and the toast messages that appear after
  an action.
* **Text that no longer fits.** German and Greek in particular run considerably longer than English.
  Collapse the sidebars, and check the article rows, which change layout at a set width.
* Sentences with a value dropped into them — a deletion confirmation naming an article, the
  "Created:" / "Modified:" pair on an article row — read correctly with a real value in place.
* A stray `GRAFIDA_…` key on screen, which means a missing or misspelt key.

## Where to send it

Open a pull request on the [project's GitHub repository](https://github.com/akeeba/grafida/pulls),
or raise it on the [Discussions page](https://github.com/akeeba/grafida/discussions) if you would
rather not use git. Please include the glossary change alongside the language file; see
[Community and help](Community-and-help).
