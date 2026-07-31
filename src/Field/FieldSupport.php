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
 * Knows which core Joomla custom-field types Grafida can edit, and detects the
 * situation where a required field uses an unsupported type — which makes
 * publishing through the API impossible.
 */
final class FieldSupport
{
    /**
     * The subset of core field types Grafida renders and edits. Any other type
     * (editor, sql, subform, user, usergrouplist, imagelist, ...) is considered
     * unsupported.
     *
     * `media` is in the list because Grafida has a media picker of its own —
     * the same one the intro/full-text images use, offline blobs included — so
     * the field is editable without a site-side media manager. Its value is a
     * record rather than a string; {@see MediaFieldValue} is the only thing
     * that should read or write it.
     */
    public const SUPPORTED = [
        'calendar',
        'checkboxes',
        'color',
        'integer',
        'list',
        'media',
        'radio',
        'text',
        'textarea',
        'url',
    ];

    public function isSupported(string $type): bool
    {
        return in_array(strtolower($type), self::SUPPORTED, true);
    }

    /**
     * Splits API field definitions into supported and unsupported, annotating
     * each with `supported` and normalised `required` flags.
     *
     * @param list<array<string, mixed>> $definitions
     *
     * @return array{supported: list<array<string, mixed>>, unsupported: list<array<string, mixed>>}
     */
    public function partition(array $definitions): array
    {
        $supported   = [];
        $unsupported = [];

        foreach ($definitions as $field) {
            $rawType   = $field['type'] ?? null;
            $type      = is_string($rawType) ? $rawType : 'text';
            $field['supported'] = $this->isSupported($type);
            $field['required']  = $this->isRequired($field);

            if ($field['supported']) {
                $supported[] = $field;
            } else {
                $unsupported[] = $field;
            }
        }

        return ['supported' => $supported, 'unsupported' => $unsupported];
    }

    /**
     * Splits the **required** fields of an unsupported type in two, according to
     * whether the draft carries a value Grafida could send back for them.
     *
     * Why the distinction exists at all: Joomla's API save runs the posted body
     * through the component's edit form, and `FormField::validate()` rejects a
     * required field whose value is `''`/`null` — so a required field Grafida
     * cannot render is a hard 400 unless *something* is sent for it. (A field
     * that is **not** required needs nothing: the API never fires
     * `onContentNormaliseRequestData`, so `plg_system_fields` falls back to the
     * stored `rawvalue` for every `com_fields` key we omit, and the site keeps
     * what it had.)
     *
     * - `blocking` — required, unsupported, and we hold no value for it. There
     *   is nothing we could send, so the publish cannot happen at all.
     * - `overridable` — required, unsupported, but the draft carries the value
     *   read off the site when the article was imported. Sending it back
     *   verbatim satisfies the form, so the publish *can* go ahead — with the
     *   user's consent, since a value Grafida cannot display is also one it
     *   cannot show to be stale (see gh-59).
     *
     * A field inside a `subform` needs no special case: `subform` is itself an
     * unsupported type, so a required one is already caught here, and its member
     * fields are assigned `-1` ("Only Use In Subform") and never reach an
     * article form at all — {@see FieldCategoryScope} drops them.
     *
     * @param list<array<string, mixed>> $definitions
     * @param array<string, mixed>       $values Draft field values, keyed by field name.
     *
     * @return array{blocking: list<array<string, mixed>>, overridable: list<array<string, mixed>>}
     */
    public function requiredUnsupported(array $definitions, array $values = []): array
    {
        $blocking    = [];
        $overridable = [];

        foreach ($definitions as $field) {
            $rawType = $field['type'] ?? null;

            if (!$this->isRequired($field) || $this->isSupported(is_string($rawType) ? $rawType : '')) {
                continue;
            }

            $rawName = $field['name'] ?? null;
            $name    = is_string($rawName) ? $rawName : '';

            if ($name !== '' && self::hasValue($values[$name] ?? null)) {
                $overridable[] = $field;
            } else {
                $blocking[] = $field;
            }
        }

        return ['blocking' => $blocking, 'overridable' => $overridable];
    }

    /**
     * The label a field should be listed under, falling back to its name.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<string>
     */
    public static function labels(array $fields): array
    {
        return array_values(array_map(
            static function (array $field): string {
                $label = $field['label'] ?? $field['name'] ?? 'field';

                return is_string($label) && $label !== '' ? $label : 'field';
            },
            $fields
        ));
    }

    /**
     * The `name` of each of the given field definitions, blanks dropped.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<string>
     */
    public static function names(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether a stored value would satisfy Joomla's `required` check — the same
     * test `FormField::validate()` applies, widened to the array form a
     * multi-value field is stored in. `'0'` is a value; the empty string is not.
     */
    private static function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return (string) (is_scalar($value) ? $value : '') !== '';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isRequired(array $field): bool
    {
        $required = $field['required'] ?? false;

        return $required === true || $required === 1 || $required === '1';
    }
}
