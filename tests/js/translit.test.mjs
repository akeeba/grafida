/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/util/translit.js — the per-language
 * transliteration behind the alias (URL slug) preview (gh-61).
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the other
 * tests in here, this is the ONLY automated coverage available: the module
 * lives in the SPA, so PHPUnit cannot reach it.
 *
 * What the assertions pin is the part that is not obvious from the code: that
 * a language provider **overrides** Joomla's shared Latin map rather than
 * merely adding to it (the French `ü` → `u` against the shared German
 * `ü` → `ue` is the whole reason providers exist), and that Greek is the case
 * where the shared map produces literally nothing and the provider is the
 * difference between an alias and a timestamp.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/private/js/util/translit.js', import.meta.url), 'utf8');

/** Loads translit.js into a fresh sandbox and returns its public API. */
function load() {
    const sandbox = { window: {}, String, Object, RegExp };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);

    return sandbox.window.GrafidaTransliterate;
}

const { transliterate } = load();

test('the shared Latin map is applied when the language has no provider', () => {
    // Joomla's own utf8_latin_to_ascii(): note the German-flavoured umlauts,
    // which every language falls back to when its pack ships no transliterate().
    assert.equal(transliterate('Crème brûlée', '*'), 'creme brulee');
    assert.equal(transliterate('Straße', '*'), 'strasse');
    assert.equal(transliterate('Köln', ''), 'koeln');
    assert.equal(transliterate('Æsop œuvre', null), 'aesop oeuvre');
});

test('German states the umlaut rules the shared map happens to agree with', () => {
    assert.equal(transliterate('Grüße aus Köln', 'de-DE'), 'gruesse aus koeln');
    assert.equal(transliterate('Österreich', 'de-AT'), 'oesterreich');
    assert.equal(transliterate('Zürich', 'de-CH'), 'zuerich');
});

test('French overrides the shared map: a diaeresis is not an umlaut', () => {
    // The point of the provider, and the only vowels it turns on: ä/ö/ü, where
    // the shared map writes the German umlaut out. Asserted against the shared
    // map's own answer so the test fails if either side stops disagreeing.
    assert.equal(transliterate('Saül', 'fr-FR'), 'saul');
    assert.equal(transliterate('Saül', '*'), 'sauel');
    assert.equal(transliterate('capharnaüm', 'fr-FR'), 'capharnaum');
    assert.equal(transliterate('capharnaüm', '*'), 'capharnauem');

    // ë and ï agree with the shared map either way — included so nobody
    // "fixes" the provider by deleting the entries that look redundant.
    assert.equal(transliterate('Noël', 'fr-FR'), 'noel');
    assert.equal(transliterate('Ça va, garçon ?', 'fr-CA'), 'ca va, garcon ?');
    assert.equal(transliterate('Sœur', 'fr'), 'soeur');
});

test('Greek is transliterated where the shared map would leave nothing', () => {
    assert.equal(transliterate('Καλημέρα κόσμε', 'el-GR'), 'kalimera kosme');

    // Without the provider the letters survive as Greek — the closing NFKD
    // pass takes the tonos off, nothing more — and aliasSlug()'s [a-z0-9-]
    // filter is what then leaves the caller with an empty alias.
    assert.equal(transliterate('Καλημέρα κόσμε', '*'), 'καλημερα κοσμε');
});

test('a Greek diphthong voices with the sound that follows it', () => {
    assert.equal(transliterate('αυγό', 'el-GR'), 'avgo');
    assert.equal(transliterate('ναύτης', 'el-GR'), 'naftis');
    assert.equal(transliterate('Ευρώπη', 'el-GR'), 'evropi');
    assert.equal(transliterate('ευτυχία', 'el-GR'), 'eftyxia');
    assert.equal(transliterate('ουρανός', 'el-GR'), 'ouranos');
});

test('an accent on the first vowel is not a Greek diphthong', () => {
    // άυλος — the accent sits on the alpha, so alpha and upsilon are separate
    // vowels and the diphthong rules must not fire.
    assert.equal(transliterate('άυλος', 'el-GR'), 'aylos');
});

test('a word-initial Greek μπ/ντ/γκ is a single sound', () => {
    assert.equal(transliterate('μπύρα', 'el-GR'), 'byra');
    assert.equal(transliterate('η μπύρα', 'el-GR'), 'i byra');
    assert.equal(transliterate('ντομάτα', 'el-GR'), 'domata');
    assert.equal(transliterate('γκολ', 'el-GR'), 'gol');
    // Mid-word it is the plain pair, which is what makes the rule word-initial.
    assert.equal(transliterate('λάμπα', 'el-GR'), 'lampa');
});

test('Greek upper case, final sigma and the two-letter sounds', () => {
    assert.equal(transliterate('ΟΔΟΣ', 'el-GR'), 'odos');
    assert.equal(transliterate('Ξέρω ψωμί', 'el-GR'), 'ksero psomi');
    assert.equal(transliterate('Θεσσαλονίκη', 'el-GR'), 'thessaloniki');
});

test('the provider is chosen on the primary subtag alone', () => {
    // de-DE, de-AT, de-CH all reach the German provider; a tag we know nothing
    // about falls back to the shared map.
    assert.equal(transliterate('Grüße', 'de'), 'gruesse');
    assert.equal(transliterate('Grüße', 'de-DE-1996'), 'gruesse');
    assert.equal(transliterate('Grüße', 'sv-SE'), 'gruesse');
    assert.equal(transliterate('aiguë', 'fr_FR'), 'aigue');
});

test('decomposed input is normalised before the maps see it', () => {
    // "Gru" + COMBINING DIAERESIS + "ße", which is the form macOS hands over
    // readily. Spelt with an escape so no editor can silently recompose it:
    // without the NFC pass the German rule misses the vowel entirely and the
    // result is "grusse".
    assert.equal(transliterate('Gru\u0308ße', 'de-DE'), 'gruesse');
});

test('anything no map knows falls through to NFKD, not to nothing', () => {
    // ǎ is in none of the maps; Joomla would drop it, we salvage the letter.
    assert.equal(transliterate('Ǎbc', '*'), 'abc');
    // A script with no decomposition at all is left as it is, for the caller's
    // own filter to strip.
    assert.equal(transliterate('日本', '*'), '日本');
});

test('an empty or absent input is the empty string, never a crash', () => {
    assert.equal(transliterate('', 'el-GR'), '');
    assert.equal(transliterate(null, 'el-GR'), '');
    assert.equal(transliterate(undefined, null), '');
});
