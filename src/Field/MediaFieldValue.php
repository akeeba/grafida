<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Field;

/**
 * The value of a `media` custom field.
 *
 * `plg_fields_media` does not render a plain path input: its
 * `onCustomFieldsPrepareDom()` sets the form field type to **`accessiblemedia`**,
 * a non-repeatable subform of three subfields — `imagefile` (the media path),
 * `alt_text` and `alt_empty` (Joomla's "the alt text is intentionally empty",
 * i.e. a decorative image). So the value is a *record*, not a string, and
 * `#__fields_values.value` holds it as a **JSON object string**:
 * `{"imagefile":"images/x.jpg","alt_text":"A cat","alt_empty":""}`.
 *
 * Three shapes therefore reach us and all three have to be understood:
 *
 *  - the JSON string above — what Joomla stores and what the SPA sends;
 *  - a decoded array/object — what a hand-written definition or a future API
 *    change might hand over;
 *  - a **bare path string** — a field created in Joomla 3, whose value was the
 *    file name and nothing else. `plg_fields_media::checkValue()` still carries
 *    that fallback, so we do too; dropping it would silently blank the field.
 *
 * ⚠️ **A write must carry the whole record, not just `imagefile`.**
 * `AccessiblemediaField::setup()` returns `false` for an array/object value
 * missing `imagefile` *or* `alt_text`, and `Form::filter()` omits a field whose
 * `setup()` failed from its output entirely — so a partial record is not a
 * partial save, it is *no* save, with no error anywhere. {@see encode()} always
 * emits all three keys, in a fixed order.
 */
final class MediaFieldValue
{
    /**
     * Normalises any of the three stored shapes into the full record.
     *
     * @return array{imagefile: string, alt_text: string, alt_empty: string}
     */
    public static function decode(mixed $raw): array
    {
        if (is_object($raw)) {
            $raw = get_object_vars($raw);
        }

        if (is_string($raw)) {
            $raw = self::fromString($raw);
        }

        if (!is_array($raw)) {
            return self::record('', '', '');
        }

        return self::record(
            $raw['imagefile'] ?? '',
            $raw['alt_text'] ?? '',
            $raw['alt_empty'] ?? '',
        );
    }

    /**
     * Serialises the record back into the JSON string Joomla stores.
     *
     * An empty `imagefile` collapses to the empty string rather than to
     * `{"imagefile":"", …}`: `plg_system_fields`' save handler treats a
     * zero-length value as "remove this field's value", which is what clearing
     * the picture in the editor has to mean. Alt text alone describes nothing.
     *
     * Slashes and Unicode are left unescaped so the stored value reads like
     * Joomla's own (`images/x.jpg`, not `images\/x.jpg`) and so PHP and the
     * SPA's `JSON.stringify()` agree on the bytes.
     *
     * @param array{imagefile?: mixed, alt_text?: mixed, alt_empty?: mixed} $value
     */
    public static function encode(array $value): string
    {
        $record = self::record(
            $value['imagefile'] ?? '',
            $value['alt_text'] ?? '',
            $value['alt_empty'] ?? '',
        );

        if ($record['imagefile'] === '') {
            return '';
        }

        $json = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    /**
     * A JSON object string decodes to its record; anything else is a Joomla 3
     * bare path (`plg_fields_media::checkValue()`'s own fallback), which stands
     * in for `imagefile` on its own.
     *
     * @return array<array-key, mixed>
     */
    private static function fromString(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === \JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['imagefile' => $raw];
    }

    /**
     * @return array{imagefile: string, alt_text: string, alt_empty: string}
     */
    private static function record(mixed $imagefile, mixed $altText, mixed $altEmpty): array
    {
        // Joomla's checkbox subfield posts "1" when ticked and nothing at all
        // when not, and its front-end template tests the value with empty() —
        // so anything falsy collapses to the empty string.
        $decorative = self::text($altEmpty);

        return [
            'imagefile' => self::text($imagefile),
            'alt_text'  => self::text($altText),
            'alt_empty' => $decorative !== '' && $decorative !== '0' ? '1' : '',
        ];
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return is_int($value) || is_float($value) || is_bool($value) ? trim((string) (int) $value) : '';
    }
}
