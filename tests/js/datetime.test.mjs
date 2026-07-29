/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/util/datetime.js — the parser/formatter for
 * the naive UTC `Y-m-d H:i:s` timestamps the app stores (gh-53).
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the
 * slashtools/csstheme/localmedia tests, this is the ONLY automated coverage
 * available: the module lives in the SPA, so PHPUnit cannot reach it.
 *
 * The point of the module is that a value like `2026-07-29 08:30:00` is UTC
 * even though it says so nowhere, so the assertions below pin the two things
 * that would silently rot: that the value is read as UTC (never as local time,
 * which is what Date.parse() does with this form in some engines — hence the
 * explicit Date.UTC() build) and that an out-of-range component is reported as
 * "no date" rather than silently rolled over into a real-looking one, which is
 * what Date.UTC() does on its own and what Joomla's `0000-00-00` null date
 * would otherwise become.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/private/js/util/datetime.js', import.meta.url), 'utf8');

/** Loads datetime.js into a fresh sandbox and returns its public API. */
function load() {
    const sandbox = { window: {}, Date, isNaN, String, Intl };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);

    return sandbox.window.GrafidaDateTime;
}

test('parse() reads the stamp as UTC, not local time', () => {
    const M = load();
    const d = M.parse('2026-07-29 08:30:15');

    assert.equal(d.toISOString(), '2026-07-29T08:30:15.000Z');
});

test('parse() accepts the ISO T separator and a missing seconds field', () => {
    const M = load();

    assert.equal(M.parse('2026-07-29T08:30:15').toISOString(), '2026-07-29T08:30:15.000Z');
    assert.equal(M.parse('2026-07-29 08:30').toISOString(), '2026-07-29T08:30:00.000Z');
});

test('parse() tolerates surrounding whitespace and a trailing fraction/zone', () => {
    const M = load();

    assert.equal(M.parse('  2026-07-29 08:30:15  ').toISOString(), '2026-07-29T08:30:15.000Z');
    assert.equal(M.parse('2026-07-29T08:30:15.500Z').toISOString(), '2026-07-29T08:30:15.000Z');
});

test('parse() rejects Joomla\'s MySQL null date', () => {
    const M = load();

    // A legacy article can still carry this in `modified`. Date.UTC() would
    // roll month 0 back into the previous December and hand back a perfectly
    // valid Date, which the row would then render as a real date.
    assert.equal(M.parse('0000-00-00 00:00:00'), null);
});

test('parse() returns null for anything unusable', () => {
    const M = load();

    for (const value of [null, undefined, '', '   ', 'never', '2026-07-29', 42, {}]) {
        assert.equal(M.parse(value), null, `expected null for ${JSON.stringify(value)}`);
    }
});

test('parse() returns null for an impossible date', () => {
    const M = load();

    assert.equal(M.parse('2026-99-99 08:30:00'), null);
});

test('format() renders in the requested locale', () => {
    const M = load();
    const stamp = '2026-07-29 08:30:00';

    // Compared against Intl's own output for the same instant rather than a
    // hard-coded string, so an ICU data update cannot fail the suite; what is
    // asserted is that the module passes the locale through and picks the
    // medium-date/short-time styles.
    const expected = new Date(Date.UTC(2026, 6, 29, 8, 30, 0))
        .toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

    assert.equal(M.format(stamp, 'en-GB'), expected);
    assert.notEqual(M.format(stamp, 'en-GB'), M.format(stamp, 'el-GR'));
});

test('format() falls back to the platform locale for a bad tag', () => {
    const M = load();

    // State.language comes from the server, so a tag Intl rejects must yield a
    // date rather than an exception that would break the whole article row.
    assert.notEqual(M.format('2026-07-29 08:30:00', 'not a tag'), '');
});

test('format() returns an empty string when there is no usable date', () => {
    const M = load();

    assert.equal(M.format(null, 'en-GB'), '');
    assert.equal(M.format('0000-00-00 00:00:00', 'en-GB'), '');
});
