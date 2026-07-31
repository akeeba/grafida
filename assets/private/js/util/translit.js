/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Transliterates a title to ASCII for the alias (URL slug) preview, the way
 * Joomla does (gh-61). Exposes window.GrafidaTransliterate = { transliterate }.
 *
 * This is only reached on a site whose "Unicode Aliases" option is **off** —
 * Joomla's default, and the only mode that transliterates at all. See
 * `aliasSlug()` in app.js for the surrounding algorithm.
 *
 * ## Why there are language providers
 *
 * Joomla's own `OutputFilter::stringUrlSafe($string, $language)` transliterates
 * through `Language::transliterate()`, which is **per language**: a language
 * pack may ship an `xx-XX.localise.php` with a `transliterate()` method of its
 * own, and only falls back to the shared Latin-1/Latin-Extended-A map
 * (`Joomla\Language\Transliterate::utf8_latin_to_ascii()`) when it does not.
 * So the same title legitimately slugifies differently depending on the
 * article's language, which is exactly what this module reproduces:
 *
 *   - `el` — Greek has no letters in the shared map at all, so a Greek title
 *     currently survives it as *nothing* and the alias falls back to a
 *     timestamp. The rules here are the Greek pack's own `transliterate()`
 *     (`El_GRLocalise`), which follows the standard phonetic scheme: the
 *     αυ/ευ/ηυ diphthongs voice with the sound that follows them, and a
 *     word-initial μπ/ντ/γκ is a single b/d/g.
 *   - `de`, `fr` — Latin scripts, so the shared map already covers them, but it
 *     covers them with **one** set of rules for every language and those rules
 *     are German ones (`ö` → `oe`, `ü` → `ue`). That is right for German and
 *     wrong for French, where the diaeresis in `Saül` marks a separate vowel
 *     and must give `saul`, not `sauel`. Both are therefore stated explicitly
 *     rather than left to agree or disagree with the shared map by accident.
 *
 * A provider is chosen on the **primary subtag** of the article's language, so
 * `de-DE`, `de-AT` and `de-CH` all get the German one. "All" (`*`) and an
 * unknown language get the shared map alone — Joomla in that case uses the
 * *site's* default content language, which needs `core.admin` to read and so is
 * exactly as unavailable to us as `unicodeslugs` itself.
 *
 * Anything no map knows is finally run through Unicode NFKD decomposition, its
 * combining marks stripped. Joomla does not do this — it simply drops the
 * letter — but our output is not the final word: Joomla re-slugifies the alias
 * we send, and `à` → `a` passes that filter untouched. So the preview stays
 * accurate while salvaging letters Joomla would have thrown away.
 */
(function (global) {
    'use strict';

    /**
     * Joomla's shared transliteration map, ported from
     * `Joomla\Language\Transliterate::$utf8LowerAccents` (itself phputf8's
     * `utf8_accents_to_ascii()`). Only the lower-case half is needed: the input
     * is lower-cased first, which is equivalent — Joomla transliterates and
     * *then* lower-cases, and every upper-case entry is the same mapping with a
     * capital first letter.
     */
    const LATIN = {
        'à': 'a', 'ô': 'o', 'ď': 'd', 'ḟ': 'f', 'ë': 'e', 'š': 's', 'ơ': 'o',
        'ß': 'ss', 'ă': 'a', 'ř': 'r', 'ț': 't', 'ň': 'n', 'ā': 'a', 'ķ': 'k',
        'ŝ': 's', 'ỳ': 'y', 'ņ': 'n', 'ĺ': 'l', 'ħ': 'h', 'ṗ': 'p', 'ó': 'o',
        'ú': 'u', 'ě': 'e', 'é': 'e', 'ç': 'c', 'ẁ': 'w', 'ċ': 'c', 'õ': 'o',
        'ṡ': 's', 'ø': 'o', 'ģ': 'g', 'ŧ': 't', 'ș': 's', 'ė': 'e', 'ĉ': 'c',
        'ś': 's', 'î': 'i', 'ű': 'u', 'ć': 'c', 'ę': 'e', 'ŵ': 'w', 'ṫ': 't',
        'ū': 'u', 'č': 'c', 'ö': 'oe', 'è': 'e', 'ŷ': 'y', 'ą': 'a', 'ł': 'l',
        'ų': 'u', 'ů': 'u', 'ş': 's', 'ğ': 'g', 'ļ': 'l', 'ƒ': 'f', 'ž': 'z',
        'ẃ': 'w', 'ḃ': 'b', 'å': 'a', 'ì': 'i', 'ï': 'i', 'ḋ': 'd', 'ť': 't',
        'ŗ': 'r', 'ä': 'ae', 'í': 'i', 'ŕ': 'r', 'ê': 'e', 'ü': 'ue', 'ò': 'o',
        'ē': 'e', 'ñ': 'n', 'ń': 'n', 'ĥ': 'h', 'ĝ': 'g', 'đ': 'd', 'ĵ': 'j',
        'ÿ': 'y', 'ũ': 'u', 'ŭ': 'u', 'ư': 'u', 'ţ': 't', 'ý': 'y', 'ő': 'o',
        'â': 'a', 'ľ': 'l', 'ẅ': 'w', 'ż': 'z', 'ī': 'i', 'ã': 'a', 'ġ': 'g',
        'ṁ': 'm', 'ō': 'o', 'ĩ': 'i', 'ù': 'u', 'į': 'i', 'ź': 'z', 'á': 'a',
        'û': 'u', 'þ': 'th', 'ð': 'dh', 'æ': 'ae', 'µ': 'u', 'ĕ': 'e', 'œ': 'oe'
    };

    /**
     * German. Agrees with {@see LATIN} today — the shared map's umlaut rules
     * *are* the German ones — and is stated anyway because that is a
     * coincidence of Joomla's history, not a decision about German: the shared
     * map is a Latin-1 catch-all every language falls back to, and the French
     * provider below is the standing proof that the two can and do disagree.
     */
    const GERMAN = {
        'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss', 'æ': 'ae', 'œ': 'oe'
    };

    /**
     * French. The accents are simply dropped (`é` → `e`, `ç` → `c`) and — the
     * part that matters — a diaeresis is **not** an umlaut: it marks a vowel
     * pronounced separately, so `Saül` is `saul` and `capharnaüm` is
     * `capharnaum`. The shared map's German `ü` → `ue` would make those `sauel`
     * and `capharnauem`. (`ë` and `ï` agree with the shared map either way; it
     * is `ä`/`ö`/`ü` that the two disagree on, which is why all three are
     * listed.)
     */
    const FRENCH = {
        'à': 'a', 'â': 'a', 'ä': 'a', 'ç': 'c', 'é': 'e', 'è': 'e', 'ê': 'e',
        'ë': 'e', 'î': 'i', 'ï': 'i', 'ô': 'o', 'ö': 'o', 'ù': 'u', 'û': 'u',
        'ü': 'u', 'ÿ': 'y', 'æ': 'ae', 'œ': 'oe'
    };

    /** Greek, single letters. Accented and diaeresis forms fold onto the plain one. */
    const GREEK = {
        'α': 'a', 'ά': 'a', 'β': 'v', 'γ': 'g', 'δ': 'd', 'ε': 'e', 'έ': 'e',
        'ζ': 'z', 'η': 'i', 'ή': 'i', 'θ': 'th', 'ι': 'i', 'ί': 'i', 'ϊ': 'i',
        'ΐ': 'i', 'κ': 'k', 'λ': 'l', 'μ': 'm', 'ν': 'n', 'ξ': 'ks', 'ο': 'o',
        'ό': 'o', 'π': 'p', 'ρ': 'r', 'σ': 's', 'ς': 's', 'τ': 't', 'υ': 'y',
        'ύ': 'y', 'ϋ': 'y', 'ΰ': 'y', 'φ': 'f', 'χ': 'x', 'ψ': 'ps', 'ω': 'o',
        'ώ': 'o'
    };

    /**
     * The consonants that make a preceding αυ/ευ/ηυ voiceless — `ναύτης` is
     * *naftis*, `αυγό` is *avgo*. Written as a look-ahead so the consonant
     * itself is still transliterated by the letter map afterwards.
     */
    const GREEK_VOICELESS = 'θκξπσςτφχψ';

    /**
     * Greek digraphs, applied in order, before the letter map.
     *
     * The two vowel rules match an accent on the **second** vowel only (`αύ`),
     * which is what marks a diphthong; an accent on the first (`άυ`, as in
     * `άυλος`) means two separate vowels and must fall through to the letter
     * map. `μπ`/`ντ`/`γκ` are single sounds at the start of a word — `μπύρα` is
     * *byra* — and the digraph pair anywhere else.
     */
    const GREEK_DIGRAPHS = [
        [new RegExp('([αεη])[υύ](?=[' + GREEK_VOICELESS + '])', 'g'), (m, v) => ({ α: 'af', ε: 'ef', η: 'if' }[v])],
        [/([αεη])[υύ]/g, (m, v) => ({ α: 'av', ε: 'ev', η: 'iv' }[v])],
        [/ο[υύ]/g, () => 'ou'],
        [/(^|\s)μπ/g, (m, lead) => lead + 'b'],
        [/(^|\s)ντ/g, (m, lead) => lead + 'd'],
        [/(^|\s)γκ/g, (m, lead) => lead + 'g']
    ];

    /** Combining diacritical marks, U+0300–U+036F, left behind by NFKD. */
    const COMBINING = /[̀-ͯ]/g;

    /** Language providers, keyed by BCP-47 primary subtag. */
    const PROVIDERS = {
        de: (str) => applyMap(str, GERMAN),
        fr: (str) => applyMap(str, FRENCH),
        el: (str) => applyMap(greekDigraphs(str), GREEK)
    };

    /** Replaces every key of `map` found in `str` with its value. */
    function applyMap(str, map) {
        let out = '';

        for (const ch of str) {
            out += Object.prototype.hasOwnProperty.call(map, ch) ? map[ch] : ch;
        }

        return out;
    }

    /** Runs the Greek digraph rules, in order. */
    function greekDigraphs(str) {
        return GREEK_DIGRAPHS.reduce((acc, [pattern, replace]) => acc.replace(pattern, replace), str);
    }

    /**
     * The BCP-47 primary subtag, lower-cased — `de-AT` → `de`. Joomla's "All"
     * (`*`), an empty value and anything that is not a language tag return ''.
     *
     * @param {*} tag
     * @returns {string}
     */
    function primarySubtag(tag) {
        const match = /^([A-Za-z]{2,3})(?:[-_]|$)/.exec(String(tag === null || tag === undefined ? '' : tag).trim());

        return match ? match[1].toLowerCase() : '';
    }

    /**
     * Transliterates a string to ASCII for the given article language.
     *
     * The result is lower-cased (Joomla lower-cases too, one step later) but is
     * otherwise unfiltered: stripping whatever is still not URL-safe is the
     * caller's job, exactly as it is Joomla's `stringUrlSafe()`'s.
     *
     * @param {*}       text     the title, or any string
     * @param {?string} language the article's BCP-47 language tag, or '*'/'' for none
     * @returns {string}
     */
    function transliterate(text, language) {
        // NFC first: every map below is keyed by precomposed characters, and a
        // decomposed `ä` (a + U+0308, which macOS hands out readily) would
        // otherwise miss the German rule and end up as a bare `a`.
        let str = String(text === null || text === undefined ? '' : text).normalize('NFC').toLowerCase();

        const provider = PROVIDERS[primarySubtag(language)];

        // The language's own rules first: they consume the characters they care
        // about, which is what lets French's `ü` → `u` win over the shared map's
        // German `ü` → `ue` without either knowing about the other.
        if (provider) str = provider(str);

        str = applyMap(str, LATIN);

        return str.normalize('NFKD').replace(COMBINING, '');
    }

    global.GrafidaTransliterate = { transliterate };
}(typeof window !== 'undefined' ? window : this));
