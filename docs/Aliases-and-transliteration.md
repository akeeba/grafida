# Aliases and transliteration

The **alias** is the last part of an article's URL on your site. Grafida fills it in from the title — when you move focus out of the title field, if the alias is still empty, or whenever you press the circular-arrows button next to it — and shows you the result before you publish.

What Grafida shows is a preview of what Joomla itself would make of the same title. Joomla has two different rules for that, and which one applies is a setting on your site.

## The two rules

In your site's **System**, **Global Configuration**, **Site** tab there is an option called **Unicode Aliases**.

**Turned off** — Joomla's default — an alias may only contain the letters `a` to `z`, digits and dashes. Everything else is *transliterated*: turned into the closest Latin letters. `Crème brûlée` becomes `creme-brulee`. Anything that survives neither the transliteration nor the filter is dropped, and if that leaves nothing at all Joomla falls back to a date-and-time stamp such as `2026-07-31-14-22-05`.

**Turned on**, the letters are kept as they are. `Καλημέρα κόσμε` becomes `καλημέρα-κόσμε`, and only the characters that would break a URL are removed.

Neither rule is better than the other; they are a decision about what you want your URLs to look like. Grafida does not change that setting, it only follows it.

## Telling Grafida which rule applies

Grafida tries to read the **Unicode Aliases** option from your site. Joomla only lets a Super User's API Token read Global Configuration, so if you connect with a less privileged user — which is the [recommended way to work](Custom-API-Access) — the answer comes back as “access denied” and Grafida has to guess. It guesses “off”, which is Joomla's own default and therefore right for most sites, but wrong for yours if you have turned it on.

The **Site uses Unicode Aliases** setting in the [site's settings](Sites) is how you say so:

- **Automatic** — read it from the site. This is the default, and it is all a Super User's token needs.
- **Yes** — the site has Unicode Aliases turned on. Do not ask it.
- **No** — the site has Unicode Aliases turned off. Do not ask it.

Set it once, when you connect the site. Switching back to _Automatic_ makes Grafida ask the site again rather than remember what you told it.

> [!TIP]
> If you are not sure, look at an existing article's URL on your site. Non-Latin or accented characters in it mean Unicode Aliases is on.

## Transliteration and the article's language

Transliteration — the first rule above — is not the same everywhere. Joomla lets each language pack say how its own alphabet is written in Latin letters, and Grafida does the same, using the **Language** you pick for the article in the editor's sidebar.

The language is matched on its first part, so `de-DE`, `de-AT` and `de-CH` all use the German rules.

**German** (`de`). The umlauts are written out and the sharp s is doubled: `ä` → `ae`, `ö` → `oe`, `ü` → `ue`, `ß` → `ss`. `Grüße aus Köln` becomes `gruesse-aus-koeln`.

**French** (`fr`). The accents are simply dropped and `ç` becomes `c`: `Ça va, garçon` becomes `ca-va-garcon`. Note that a diaeresis is not an umlaut here — it marks a vowel that is pronounced separately — so `Saül` becomes `saul` and `capharnaüm` becomes `capharnaum`, rather than `sauel` and `capharnauem`.

**Greek** (`el`). The rules are the ones the Greek language pack for Joomla uses, which follow the sound rather than the letter:

| Greek | Alias | |
|---|---|---|
| `Καλημέρα κόσμε` | `kalimera-kosme` | the plain letters |
| `αυγό` | `avgo` | `αυ` before a voiced sound |
| `ναύτης` | `naftis` | `αυ` before a voiceless one |
| `Ευρώπη` | `evropi` | the same for `ευ` |
| `μπύρα` | `byra` | `μπ`, `ντ`, `γκ` at the start of a word |
| `λάμπα` | `lampa` | but not in the middle of one |

**Every other language** uses the general rules Joomla applies when a language pack says nothing: accented Latin letters lose their accents, with the German spelling for umlauts. Articles whose language is **All** are in this group too — Joomla would use your site's *default* content language there, which, like Unicode Aliases itself, needs a Super User's token to read.

If your alphabet is not in that list, and the transliterated alias comes out empty or nonsensical, you have two ways out: turn on Unicode Aliases on your site and tell Grafida about it, or type the alias yourself. Grafida never overwrites an alias you have typed.

## The last word is Joomla's

Whatever Grafida puts in the alias field is sent to your site, and Joomla runs it through its own rules once more before saving it. So the preview can be wrong about a detail without any harm being done — the published article still gets a valid alias. It is a preview to save you a surprise, not the final say.
