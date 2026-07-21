/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Builds and parses the `boson://app/api/media/{id}/raw?rev=…` URL a local
 * (not-yet-published) media blob is referenced by in the article HTML
 * (gh-36). Exposes window.GrafidaLocalMedia = { url, idFromUrl, token,
 * PREFIX }.
 *
 * Mirrors `Grafida\Media\LocalMediaUrl` (URL shape + rev token) and
 * `Grafida\Html\InlineMedia::LOCAL_URL_PREFIX`/`idFromLocalUrl()` (parsing) on
 * the PHP side. Both sides mint or read this exact URL — the editor tags a
 * pasted image with it (`images_upload_handler`'s return value, the tagging
 * hook that derives `data-grafida-media-id` from it) and PHP serves it
 * (`MediaController::mediaBlobRaw`) and rewrites it on publish
 * (`InlineMedia::rewriteOfflineImages`) — so the format is defined once here,
 * not re-derived at each call site. `url()` itself is not needed by step 4 (the
 * server already returns a fully-formed URL from `POST /api/sites/{id}/media`),
 * but a later step (the Local Media tab, an in-place image edit) needs to mint
 * a fresh URL client-side after the bytes change, without a round trip just to
 * learn the new `rev` — hence it lives here now rather than being bolted on
 * later.
 *
 * A pure module (no app.js/window.State/DOM dependency beyond `window`
 * itself), which is what makes it cheaply unit-testable
 * (tests/js/localmedia.test.mjs) the same way slashtools.js/csstheme.js are.
 */

'use strict';

(function (global) {
    // The id/raw URL a local media blob is referenced by. Anchoring on this
    // exact prefix (rather than e.g. "/api/media/") is what stops a published
    // site URL that merely happens to contain "/api/media/" from being
    // mistaken for a local reference.
    const PREFIX = 'boson://app/api/media/';

    /**
     * A minimal, dependency-free SHA-1 (RFC 3174) over a UTF-8 string,
     * returning a lowercase hex digest. `window.crypto.subtle.digest` exists
     * in every webview Grafida ships on, but it is Promise-only — the rev
     * token is needed synchronously wherever a URL is built inline (e.g. from
     * a TinyMCE `urlconverter_callback` or a render loop), so a plain
     * synchronous implementation is used instead. This has no security role
     * (see the token() doc comment) so a from-scratch, non-hardened SHA-1 is
     * fine.
     */
    function sha1Hex(message) {
        const bytes = [];
        const utf8 = unescape(encodeURIComponent(message));

        for (let i = 0; i < utf8.length; i += 1) {
            bytes.push(utf8.charCodeAt(i));
        }

        const bitLength = bytes.length * 8;
        bytes.push(0x80);
        while (bytes.length % 64 !== 56) bytes.push(0);
        for (let i = 3; i >= 0; i -= 1) bytes.push(0); // high 32 bits of length: always 0 here
        for (let shift = 24; shift >= 0; shift -= 8) bytes.push((bitLength >>> shift) & 0xff);

        let h0 = 0x67452301;
        let h1 = 0xefcdab89;
        let h2 = 0x98badcfe;
        let h3 = 0x10325476;
        let h4 = 0xc3d2e1f0;

        const rotl = (x, n) => (x << n) | (x >>> (32 - n));

        for (let chunk = 0; chunk < bytes.length; chunk += 64) {
            const w = new Array(80);
            for (let i = 0; i < 16; i += 1) {
                w[i] = (bytes[chunk + i * 4] << 24) | (bytes[chunk + i * 4 + 1] << 16)
                    | (bytes[chunk + i * 4 + 2] << 8) | bytes[chunk + i * 4 + 3];
            }
            for (let i = 16; i < 80; i += 1) {
                w[i] = rotl(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
            }

            let a = h0;
            let b = h1;
            let c = h2;
            let d = h3;
            let e = h4;

            for (let i = 0; i < 80; i += 1) {
                let f;
                let k;
                if (i < 20) {
                    f = (b & c) | (~b & d);
                    k = 0x5a827999;
                } else if (i < 40) {
                    f = b ^ c ^ d;
                    k = 0x6ed9eba1;
                } else if (i < 60) {
                    f = (b & c) | (b & d) | (c & d);
                    k = 0x8f1bbcdc;
                } else {
                    f = b ^ c ^ d;
                    k = 0xca62c1d6;
                }
                const temp = (rotl(a, 5) + f + e + k + w[i]) >>> 0;
                e = d;
                d = c;
                c = rotl(b, 30);
                b = a;
                a = temp;
            }

            h0 = (h0 + a) >>> 0;
            h1 = (h1 + b) >>> 0;
            h2 = (h2 + c) >>> 0;
            h3 = (h3 + d) >>> 0;
            h4 = (h4 + e) >>> 0;
        }

        const toHex = (n) => (n >>> 0).toString(16).padStart(8, '0');

        return toHex(h0) + toHex(h1) + toHex(h2) + toHex(h3) + toHex(h4);
    }

    /**
     * A short, URL-safe cache-busting token — not a security control, merely
     * a value that changes whenever the blob's bytes do. Must derive
     * identically to `Grafida\Media\LocalMediaUrl::token()`.
     *
     * @param {number} id
     * @param {string} revisedAt the blob's `updated_at`, falling back to
     *        `created_at` when never edited
     * @return {string}
     */
    function token(id, revisedAt) {
        return sha1Hex(revisedAt + '|' + id).slice(0, 8);
    }

    /**
     * @param {number} id
     * @param {string} revisedAt see token()
     * @return {string} the boson://app/api/media/{id}/raw?rev=… URL
     */
    function url(id, revisedAt) {
        return PREFIX + id + '/raw?rev=' + token(id, revisedAt);
    }

    /**
     * Parses the blob id out of a "boson://app/api/media/{id}/raw[?...]" URL,
     * tolerating (and ignoring) the `?rev=…` query string. Returns null for
     * anything else — including a `data:` URI, a real site URL, or a
     * boson://app/api/media/ URL that is not the /raw form — mirroring
     * `InlineMedia::idFromLocalUrl()`.
     *
     * @param {string} src
     * @return {?number}
     */
    function idFromUrl(src) {
        if (typeof src !== 'string' || src.indexOf(PREFIX) !== 0) return null;

        const rest = src.slice(PREFIX.length);
        const m = /^(\d+)\/raw(?:\?.*)?$/.exec(rest);

        return m ? parseInt(m[1], 10) : null;
    }

    global.GrafidaLocalMedia = { PREFIX, url, idFromUrl, token };
}(typeof window !== 'undefined' ? window : this));
