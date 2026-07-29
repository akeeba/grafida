/**
 * Grafida — desktop Joomla! article editor
 * Copyright (c) 2026 Nicholas K. Dionysopoulos
 * GNU General Public License version 3, or later
 *
 * Parses and displays the naive UTC `Y-m-d H:i:s` timestamps the app deals in
 * (gh-53). Exposes window.GrafidaDateTime = { parse, format }.
 *
 * Every timestamp that reaches the SPA — Joomla's `created`/`modified` article
 * attributes, our own `drafts.created_at`/`updated_at`, `reference_cache`'s
 * `fetchedAt` — is stored in **UTC with no zone marker**. That form must never
 * be handed to `Date.parse()`: WKWebView does not handle it reliably (it is the
 * same trap documented for `ai_chats.last_response_at`, which is why that one
 * column is stored as ISO-8601 instead). Wherever the app only needs to *order*
 * such values it compares the strings directly — the format sorts
 * lexicographically in chronological order — and never comes here at all. This
 * module exists for the one thing string comparison cannot do: showing the
 * timestamp to a person.
 *
 * `parse()` therefore pulls the components out with a regexp and builds the
 * Date from `Date.UTC()`, so no engine's date-string heuristics are involved.
 */
(function (global) {
    'use strict';

    /**
     * `Y-m-d H:i:s` (or the `T`-separated / second-less variants), UTC. Anything
     * after the seconds — a fractional part, a zone — is ignored rather than
     * rejected: no producer in this app emits one, and a value we can mostly
     * read is more useful than none.
     */
    const STAMP = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/;

    /**
     * Parses a naive UTC timestamp into a Date, or returns null.
     *
     * Null is returned for an empty/absent value and for anything that does not
     * read back as the exact date it claims to be — so a caller can treat "no
     * usable date" as one case and simply omit the value.
     *
     * ⚠️ That round-trip check is load-bearing, not belt-and-braces: `Date.UTC()`
     * does not reject an out-of-range component, it **rolls it over**. So a
     * plausible-looking `0000-00-00 00:00:00` — Joomla's MySQL null date, which
     * legacy rows can still carry in `modified` — would otherwise come back as a
     * December day in 1 BC and be rendered onto the row as a real date, and a
     * corrupt `2026-99-99` as a date in 2034. Comparing the components back
     * against the input is what turns both into "no date".
     *
     * @param {*} value
     * @returns {Date|null}
     */
    function parse(value) {
        const m = STAMP.exec(String(value === null || value === undefined ? '' : value).trim());

        if (!m) return null;

        const [year, month, day, hour, minute] = [+m[1], +m[2], +m[3], +m[4], +m[5]];
        const second = +(m[6] || 0);
        const date   = new Date(Date.UTC(year, month - 1, day, hour, minute, second));

        if (isNaN(date.getTime())) return null;

        const sameDay  = date.getUTCFullYear() === year
            && date.getUTCMonth() === month - 1
            && date.getUTCDate() === day;
        const sameTime = date.getUTCHours() === hour
            && date.getUTCMinutes() === minute
            && date.getUTCSeconds() === second;

        return sameDay && sameTime ? date : null;
    }

    /**
     * Renders a naive UTC timestamp for display, or '' when there is none.
     *
     * Shown in the reader's **own** time zone and in the interface locale: this
     * is a desktop app, so the machine's clock is the one the user thinks in.
     * Note this can differ from what the same article's date looks like in
     * Joomla's back-end, which renders it in the site/user time zone.
     *
     * An unusable locale tag falls back to the platform default rather than
     * throwing — the caller only wants a date on screen.
     *
     * @param {*}       value  naive UTC `Y-m-d H:i:s`
     * @param {?string} locale BCP-47 tag (the interface language)
     * @returns {string}
     */
    function format(value, locale) {
        const date = parse(value);

        if (!date) return '';

        const opts = { dateStyle: 'medium', timeStyle: 'short' };

        try {
            return date.toLocaleString(locale || undefined, opts);
        } catch (e) {
            return date.toLocaleString(undefined, opts);
        }
    }

    global.GrafidaDateTime = { parse, format };
}(typeof window !== 'undefined' ? window : this));
