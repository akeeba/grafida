/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 *
 * Unit tests for assets/private/js/editor/localmedia.js — the module that
 * builds/parses the boson://app/api/media/{id}/raw?rev=… local-media URL
 * (gh-36).
 *
 * Run with `composer test:js` (or `node --test tests/js/`). Like the
 * slashtools/csstheme tests, this is the ONLY automated coverage available:
 * the module lives in the SPA, so PHPUnit cannot reach it — but its
 * token()/url() output MUST agree byte-for-byte with the PHP side
 * (Grafida\Media\LocalMediaUrl::token()/build()), which is why the first
 * assertion below pins a value cross-checked against
 * `php -r 'echo substr(sha1("2026-07-21 10:00:00|5"), 0, 8);'` rather than
 * merely round-tripping through this module's own functions.
 */

import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../assets/private/js/editor/localmedia.js', import.meta.url), 'utf8');

/** Loads localmedia.js into a fresh sandbox and returns its public API. */
function load() {
    const sandbox = { window: {} };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox);
    return sandbox.window.GrafidaLocalMedia;
}

test('token() matches the PHP formula (sha1("<revisedAt>|<id>") first 8 hex chars)', () => {
    const M = load();
    // php -r 'echo substr(sha1("2026-07-21 10:00:00|5"), 0, 8);' => 206081bb
    assert.equal(M.token(5, '2026-07-21 10:00:00'), '206081bb');
});

test('token() changes when either the id or the revision changes', () => {
    const M = load();
    const base = M.token(5, '2026-07-21 10:00:00');
    assert.notEqual(M.token(6, '2026-07-21 10:00:00'), base);
    assert.notEqual(M.token(5, '2026-07-21 10:00:01'), base);
});

test('url() builds the boson://app/api/media/{id}/raw?rev=<token> shape', () => {
    const M = load();
    assert.equal(
        M.url(5, '2026-07-21 10:00:00'),
        'boson://app/api/media/5/raw?rev=206081bb',
    );
});

test('idFromUrl() recovers the id from a URL minted by url()', () => {
    const M = load();
    const url = M.url(42, '2026-01-01 00:00:00');
    assert.equal(M.idFromUrl(url), 42);
});

test('idFromUrl() tolerates a missing/extra query string', () => {
    const M = load();
    assert.equal(M.idFromUrl('boson://app/api/media/7/raw'), 7);
    assert.equal(M.idFromUrl('boson://app/api/media/7/raw?rev=deadbeef'), 7);
    assert.equal(M.idFromUrl('boson://app/api/media/7/raw?rev=deadbeef&foo=1'), 7);
});

test('idFromUrl() returns null for anything not the local /raw form', () => {
    const M = load();
    assert.equal(M.idFromUrl('data:image/png;base64,abcd'), null);
    // A real site URL that merely happens to contain "/api/media/" must not
    // be mistaken for a local reference — anchored on the boson:// prefix.
    assert.equal(M.idFromUrl('https://example.com/api/media/1/raw'), null);
    assert.equal(M.idFromUrl('boson://app/api/media/7'), null);
    assert.equal(M.idFromUrl('boson://app/api/media/7/other'), null);
    assert.equal(M.idFromUrl('boson://app/api/media/abc/raw'), null);
    assert.equal(M.idFromUrl(''), null);
    assert.equal(M.idFromUrl(null), null);
    assert.equal(M.idFromUrl(undefined), null);
});
